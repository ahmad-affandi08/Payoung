<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\Billing\GerbangBillingPlatform;
use App\Domain\Pajak\Kueri\TarifPajakBerlaku;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\MetodePembayaranLangganan;
use App\Domain\Tenant\Enum\SiklusTagihan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Enum\TahapPengingatTagihan;
use App\Domain\Tenant\Kueri\HargaPaketBerlaku;
use App\Domain\Tenant\Kueri\TagihanLanggananTenant;
use App\Domain\Tenant\Model\Addon;
use App\Domain\Tenant\Model\KuponLangganan;
use App\Domain\Tenant\Model\KuponLanggananPemakaian;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\LanggananAddon;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\TagihanLanggananAddon;
use Carbon\CarbonImmutable;

/**
 * Inti penerbitan tagihan langganan (P-08 langkah 1, BR-P08.5–P08.8), dipakai tagihan buatan Owner
 * (`BuatTagihanLangganan`) dan tagihan perpanjangan otomatis H-7 (`TerbitkanTagihanPerpanjanganOtomatis`) supaya
 * angka, nomor, kupon, dan jatuh temponya tidak bisa berbeda antara kedua jalur.
 *
 * Wajib dipanggil di dalam transaksi dengan baris `Langganan` sudah dikunci dan konteks tenant sudah diatur; pemanggil
 * memastikan tidak ada tagihan terbuka lain (BR-P08.4).
 */
final class PenerbitTagihanLangganan
{
    public function __construct(
        private readonly HargaPaketBerlaku $hargaBerlaku,
        private readonly TarifPajakBerlaku $tarifBerlaku,
        private readonly GerbangBillingPlatform $gerbang,
        private readonly KalkulatorTagihanLangganan $kalkulator,
        private readonly PenomorTagihanLangganan $penomor,
        private readonly TagihanLanggananTenant $tagihanTenant,
        private readonly PencatatAudit $audit,
        private readonly PenghitungProrataAddon $prorataAddon,
        private readonly KelayakanBeliAddon $kelayakanAddon,
        private readonly PenghitungNaikPaket $naikPaket,
        private readonly PelunasTagihanLangganan $pelunas,
        private readonly PengaturanPenagihanPlatform $pengaturan,
    ) {}

    /**
     * @param  int|null  $idPengguna  null = diterbitkan sistem (perpanjangan otomatis)
     */
    public function Terbitkan(
        Langganan $langganan,
        Paket $paket,
        JenisTagihanLangganan $jenis,
        SiklusTagihan $siklus,
        ?KuponLangganan $kupon,
        ?int $idPengguna,
    ): TagihanLangganan {
        $idTenant = $langganan->IdTenant;
        $sekarang = CarbonImmutable::now();

        $harga = $this->hargaBerlaku->Cari($paket->Id, $sekarang, $jenis === JenisTagihanLangganan::Perpanjangan ? $this->tagihanTenant->AmbilMulaiLanggananPaket($paket->Id) : null)
            ?? throw new PelanggaranAturanBisnis('HargaBelumTersedia', "Harga paket {$paket->Nama} belum tersedia. Hubungi tim kami.", 'KodePaket');
        $subtotal = $siklus === SiklusTagihan::Tahunan ? $harga->AmbilHargaTahunan() : $harga->AmbilHargaBulanan();

        if ($subtotal->Bandingkan(Uang::Nol()) <= 0) {
            throw new PelanggaranAturanBisnis('PaketTanpaBiaya', "Paket {$paket->Nama} tidak memerlukan tagihan.", 'KodePaket');
        }

        $tarif = null;

        if ($this->pengaturan->PlatformPkp()) {
            $tarif = $this->tarifBerlaku->Cari('Ppn', null, $sekarang)
                ?? throw new PelanggaranAturanBisnis('TarifPpnBelumTerbit', 'Tagihan belum bisa dibuat karena tarif PPN belum diterbitkan. Hubungi tim kami.');
        }

        $jumlahBulan = PenghitungPeriodeLangganan::JumlahBulan($siklus);
        // D-49: add-on yang masih berlangganan ikut ditagih di perpanjangan (harga add-on berlaku saat ini, tanpa kupon).
        $barisAddon = $jenis === JenisTagihanLangganan::Perpanjangan ? $this->SusunBarisAddonPerpanjangan($idTenant, $jumlahBulan) : [];
        $totalAddon = array_reduce($barisAddon, fn (Uang $total, array $baris): Uang => $total->Tambah($baris['Subtotal']), Uang::Nol());
        $bulanDiskon = $kupon === null ? 0 : min($jumlahBulan, $kupon->DurasiBulan - $this->HitungBulanTerpakai($idTenant, $kupon));

        $rincian = $this->kalkulator->Hitung(
            subtotal: $subtotal,
            jumlahBulan: $jumlahBulan,
            jenisKupon: $kupon?->Jenis,
            nilaiKupon: $kupon?->Nilai,
            bulanDiskon: $bulanDiskon,
            tarifPersen: $tarif?->Tarif,
            pengaliDppPembilang: $tarif->PengaliDppPembilang ?? 1,
            pengaliDppPenyebut: $tarif->PengaliDppPenyebut ?? 1,
            tambahan: $totalAddon,
        );

        // D-112: tagihan Rp 0 (kupon 100%) tidak butuh gerbang; ia terbit lalu langsung lunas (lihat akhir metode ini).
        $gratis = $rincian->total->BernilaiNol();

        if (! $gratis && ! $this->gerbang->CekAktif()) {
            throw new PelanggaranAturanBisnis('GerbangBelumAktif', 'Pembayaran tagihan belum dibuka karena gerbang pembayaran online belum diaktifkan. Hubungi tim kami.');
        }

        $jatuhTempo = $this->TentukanJatuhTempo($jenis, $langganan, $sekarang);
        $tagihan = TagihanLangganan::query()->create([
            'IdTenant' => $idTenant,
            'Nomor' => $this->penomor->Ambil($sekarang),
            'Jenis' => $jenis,
            'Status' => StatusTagihanLangganan::Terbit,
            'IdPaket' => $paket->Id,
            'IdHargaPaket' => $harga->Id,
            'Siklus' => $siklus,
            'JumlahBulan' => $jumlahBulan,
            'Subtotal' => $rincian->subtotal->KeString(),
            'IdKuponLangganan' => $kupon?->Id,
            'KodeKupon' => $kupon?->Kode,
            'Diskon' => $rincian->diskon->KeString(),
            'IdTarifPajak' => $tarif?->Id,
            'TarifPpn' => $tarif->Tarif ?? '0',
            'PengaliDppPembilang' => $tarif->PengaliDppPembilang ?? 1,
            'PengaliDppPenyebut' => $tarif->PengaliDppPenyebut ?? 1,
            'DasarPengenaanPajak' => $rincian->dasarPengenaanPajak->KeString(),
            'JumlahPpn' => $rincian->jumlahPpn->KeString(),
            'Total' => $rincian->total->KeString(),
            'TerbitPada' => $sekarang,
            'JatuhTempoPada' => $jatuhTempo,
            'IdPenggunaPembuat' => $idPengguna,
            // Owner yang membuat tagihannya sendiri sudah tahu tagihan itu terbit: tahap pengingat yang sedang
            // berjalan dianggap terkirim. Tagihan dari sistem dibiarkan kosong supaya pengingat pertama dikirim.
            'PengingatTerakhir' => $idPengguna === null ? null : TahapPengingatTagihan::Tentukan($sekarang, $jatuhTempo)?->value,
        ]);

        if ($barisAddon !== []) {
            $periodeBaru = (new PenghitungPeriodeLangganan)->Hitung(JenisTagihanLangganan::Perpanjangan, $siklus, $sekarang, $langganan->PeriodeSelesai);

            foreach ($barisAddon as $baris) {
                $this->CatatBarisAddon($tagihan, $baris, $periodeBaru['Mulai'], $periodeBaru['Selesai']);
            }
        }

        if ($kupon !== null && $rincian->bulanDiskon > 0) {
            KuponLanggananPemakaian::query()->create([
                'IdKuponLangganan' => $kupon->Id,
                'IdTenant' => $idTenant,
                'IdTagihanLangganan' => $tagihan->Id,
                'BulanDiskon' => $rincian->bulanDiskon,
                'Diskon' => $rincian->diskon->KeString(),
            ]);
        }

        $this->audit->Catat($idPengguna === null ? 'langganan.tagihan-otomatis' : 'langganan.tagihan-buat', $tagihan, nilaiBaru: [
            'Nomor' => $tagihan->Nomor, 'Jenis' => $jenis->value, 'Paket' => $paket->Kode, 'Siklus' => $siklus->value,
            'Total' => $rincian->total->KeString(), 'KodeKupon' => $kupon?->Kode,
            'Addon' => array_map(fn (array $baris): string => $baris['Addon']->Kode, $barisAddon),
        ], idTenant: $idTenant);

        if ($gratis) {
            $this->LunasiTanpaBayar($langganan, $tagihan, $sekarang);
        }

        return $tagihan;
    }

    /**
     * D-112: tagihan manual untuk paket harga negosiasi (Enterprise), diterbitkan Keuangan/Super Admin. Tidak ada
     * `HargaPaket`: `$subtotal` (sebelum PPN) adalah harga kesepakatan untuk satu siklus. Kupon tidak berlaku. Dibayar
     * dengan transfer (bukti diunggah Owner atau dicatat langsung Keuangan), tidak lewat gerbang. Pemanggil mengunci baris
     * `Langganan` di dalam transaksi dan memastikan tidak ada tagihan terbuka lain.
     */
    public function TerbitkanManual(
        Langganan $langganan,
        Paket $paket,
        JenisTagihanLangganan $jenis,
        SiklusTagihan $siklus,
        Uang $subtotal,
        string $keterangan,
        int $idPenggunaPengelola,
    ): TagihanLangganan {
        $sekarang = CarbonImmutable::now();

        if ($subtotal->Bandingkan(Uang::Nol()) <= 0) {
            throw new PelanggaranAturanBisnis('HargaTidakSah', 'Harga kesepakatan harus lebih dari Rp 0.', 'Subtotal');
        }

        $tarif = null;

        if ($this->pengaturan->PlatformPkp()) {
            $tarif = $this->tarifBerlaku->Cari('Ppn', null, $sekarang)
                ?? throw new PelanggaranAturanBisnis('TarifPpnBelumTerbit', 'Tagihan belum bisa dibuat karena tarif PPN belum diterbitkan.');
        }

        $jumlahBulan = PenghitungPeriodeLangganan::JumlahBulan($siklus);
        $rincian = $this->kalkulator->Hitung(
            subtotal: $subtotal,
            jumlahBulan: $jumlahBulan,
            tarifPersen: $tarif?->Tarif,
            pengaliDppPembilang: $tarif->PengaliDppPembilang ?? 1,
            pengaliDppPenyebut: $tarif->PengaliDppPenyebut ?? 1,
        );
        $jatuhTempo = $this->TentukanJatuhTempo($jenis, $langganan, $sekarang);
        $tagihan = TagihanLangganan::query()->create([
            'IdTenant' => $langganan->IdTenant,
            'Nomor' => $this->penomor->Ambil($sekarang),
            'Jenis' => $jenis,
            'Status' => StatusTagihanLangganan::Terbit,
            'Manual' => true,
            'Keterangan' => $keterangan,
            'IdPaket' => $paket->Id,
            'IdHargaPaket' => null,
            'Siklus' => $siklus,
            'JumlahBulan' => $jumlahBulan,
            'Subtotal' => $rincian->subtotal->KeString(),
            'Diskon' => '0.00',
            'IdTarifPajak' => $tarif?->Id,
            'TarifPpn' => $tarif->Tarif ?? '0',
            'PengaliDppPembilang' => $tarif->PengaliDppPembilang ?? 1,
            'PengaliDppPenyebut' => $tarif->PengaliDppPenyebut ?? 1,
            'DasarPengenaanPajak' => $rincian->dasarPengenaanPajak->KeString(),
            'JumlahPpn' => $rincian->jumlahPpn->KeString(),
            'Total' => $rincian->total->KeString(),
            'TerbitPada' => $sekarang,
            'JatuhTempoPada' => $jatuhTempo,
            'IdPenggunaPengelolaPembuat' => $idPenggunaPengelola,
            // Tagihan dari Keuangan belum diketahui Owner: pengingat pertama dikirim penjadwal.
            'PengingatTerakhir' => null,
        ]);

        return $tagihan;
    }

    /**
     * D-112: tagihan Rp 0 karena kupon 100% dilunasi di transaksi yang sama lewat `PelunasTagihanLangganan` (jalur yang
     * sama dengan transfer dan gerbang), dengan pembayaran bermetode `Kupon` bernilai nol. Paket, siklus, dan periode
     * langganan berubah persis seperti pembayaran sungguhan; kupon memakai bulan diskonnya seperti biasa.
     */
    private function LunasiTanpaBayar(Langganan $langganan, TagihanLangganan $tagihan, CarbonImmutable $sekarang): void
    {
        $pembayaran = PembayaranLangganan::query()->create([
            'IdTenant' => $tagihan->IdTenant,
            'IdTagihanLangganan' => $tagihan->Id,
            'Metode' => MetodePembayaranLangganan::Kupon,
            'Status' => StatusPembayaranLangganan::Menunggu,
            'Jumlah' => '0.00',
        ]);
        $lanjutan = $tagihan->Jenis === JenisTagihanLangganan::Perpanjangan ? $this->tagihanTenant->AmbilMulaiLanggananPaket($tagihan->IdPaket) : null;
        $hasil = $this->pelunas->Lunasi($langganan, $tagihan, $pembayaran, Uang::Nol(), $sekarang, mulaiPaketSebelumnya: $lanjutan);
        $this->audit->Catat('langganan.tagihan-lunas-kupon', $tagihan, nilaiLama: ['Status' => $hasil->statusTagihanLama], nilaiBaru: [
            'Nomor' => $tagihan->Nomor, 'Status' => $tagihan->Status->value, 'KodeKupon' => $tagihan->KodeKupon,
            'Langganan' => $hasil->LanggananBaru($tagihan->IdPaket, $tagihan->Siklus),
        ], idTenant: $tagihan->IdTenant);
    }

    /**
     * D-49: tagihan pembelian add-on di tengah periode berjalan, dibayar lewat jalur yang sama dengan tagihan paket.
     * Harga prorata sampai akhir periode langganan. Add-on aktif setelah tagihan lunas (`PelunasTagihanLangganan`).
     * Pemanggil mengunci baris `Langganan` di dalam transaksi dan memastikan tidak ada tagihan terbuka lain.
     */
    public function TerbitkanAddon(Langganan $langganan, Addon $addon, ?int $idPengguna, int $jumlah = 1): TagihanLangganan
    {
        $idTenant = $langganan->IdTenant;
        $sekarang = CarbonImmutable::now();
        $paket = Paket::query()->whereKey($langganan->IdPaket)->first();
        $alasan = $this->kelayakanAddon->Periksa($langganan, $paket, $sekarang);

        if ($alasan !== null || $paket === null || $langganan->PeriodeMulai === null || $langganan->PeriodeSelesai === null) {
            throw new PelanggaranAturanBisnis('AddonTidakBisaDibeli', $alasan ?? 'Add-on belum bisa dibeli.');
        }

        if (! $this->gerbang->CekAktif()) {
            throw new PelanggaranAturanBisnis('GerbangBelumAktif', 'Pembayaran tagihan belum dibuka karena gerbang pembayaran online belum diaktifkan. Hubungi tim kami.');
        }

        $harga = $this->hargaBerlaku->Cari($paket->Id, $sekarang, $this->tagihanTenant->AmbilMulaiLanggananPaket($paket->Id))
            ?? throw new PelanggaranAturanBisnis('HargaBelumTersedia', "Harga paket {$paket->Nama} belum tersedia. Hubungi tim kami.");
        $jumlahBulan = PenghitungPeriodeLangganan::JumlahBulan($langganan->SiklusTagihan);
        $hargaAddon = Uang::Dari($addon->HargaBulanan);

        if ($hargaAddon->Bandingkan(Uang::Nol()) <= 0) {
            throw new PelanggaranAturanBisnis('AddonTanpaBiaya', "Add-on {$addon->Nama} tidak memerlukan tagihan. Hubungi tim kami untuk mengaktifkannya.");
        }

        $hitung = $this->prorataAddon->Hitung($hargaAddon, $jumlahBulan, $jumlah, $sekarang, $langganan->PeriodeMulai, $langganan->PeriodeSelesai);
        $tarif = null;

        if ($this->pengaturan->PlatformPkp()) {
            $tarif = $this->tarifBerlaku->Cari('Ppn', null, $sekarang)
                ?? throw new PelanggaranAturanBisnis('TarifPpnBelumTerbit', 'Tagihan belum bisa dibuat karena tarif PPN belum diterbitkan. Hubungi tim kami.');
        }

        $rincian = $this->kalkulator->Hitung(
            subtotal: $hitung['Subtotal'],
            jumlahBulan: 1,
            tarifPersen: $tarif?->Tarif,
            pengaliDppPembilang: $tarif->PengaliDppPembilang ?? 1,
            pengaliDppPenyebut: $tarif->PengaliDppPenyebut ?? 1,
        );

        if ($rincian->total->BernilaiNol()) {
            throw new PelanggaranAturanBisnis('TotalNol', 'Tagihan add-on ini bernilai Rp 0 dan belum bisa diproses otomatis. Hubungi tim kami.');
        }

        $akhirPeriode = CarbonImmutable::instance($langganan->PeriodeSelesai);
        $jatuhTempo = $sekarang->addDays((int) config('tagihan.HariJatuhTempo'));
        $jatuhTempo = $jatuhTempo->greaterThan($akhirPeriode) ? $akhirPeriode : $jatuhTempo;
        $tagihan = TagihanLangganan::query()->create([
            'IdTenant' => $idTenant,
            'Nomor' => $this->penomor->Ambil($sekarang),
            'Jenis' => JenisTagihanLangganan::Addon,
            'Status' => StatusTagihanLangganan::Terbit,
            'IdPaket' => $paket->Id,
            'IdHargaPaket' => $harga->Id,
            'Siklus' => $langganan->SiklusTagihan,
            'JumlahBulan' => $jumlahBulan,
            'Subtotal' => $rincian->subtotal->KeString(),
            'Diskon' => '0.00',
            'IdTarifPajak' => $tarif?->Id,
            'TarifPpn' => $tarif->Tarif ?? '0',
            'PengaliDppPembilang' => $tarif->PengaliDppPembilang ?? 1,
            'PengaliDppPenyebut' => $tarif->PengaliDppPenyebut ?? 1,
            'DasarPengenaanPajak' => $rincian->dasarPengenaanPajak->KeString(),
            'JumlahPpn' => $rincian->jumlahPpn->KeString(),
            'Total' => $rincian->total->KeString(),
            'TerbitPada' => $sekarang,
            'JatuhTempoPada' => $jatuhTempo,
            'IdPenggunaPembuat' => $idPengguna,
            // Pemilik yang membeli sendiri sudah tahu tagihannya terbit; pengingat jatuh tempo tidak dipakai add-on.
            'PengingatTerakhir' => $idPengguna === null ? null : TahapPengingatTagihan::Tentukan($sekarang, $jatuhTempo)?->value,
        ]);

        $this->CatatBarisAddon($tagihan, [
            'Addon' => $addon,
            'Jumlah' => $jumlah,
            'Subtotal' => $hitung['Subtotal'],
            'JumlahBulan' => $jumlahBulan,
            'Prorata' => $hitung['Prorata'],
            'HariDitagih' => $hitung['HariDitagih'],
            'HariPeriode' => $hitung['HariPeriode'],
        ], $sekarang, $akhirPeriode);

        $this->audit->Catat('langganan.tagihan-addon', $tagihan, nilaiBaru: [
            'Nomor' => $tagihan->Nomor, 'Addon' => $addon->Kode, 'Jumlah' => $jumlah, 'Prorata' => $hitung['Prorata'],
            'HariDitagih' => $hitung['HariDitagih'], 'HariPeriode' => $hitung['HariPeriode'], 'Total' => $rincian->total->KeString(),
        ], idTenant: $idTenant);

        return $tagihan;
    }

    /**
     * D-98: naik paket di tengah periode. Menagih selisih harga paket baru dan paket berjalan (siklus yang sama)
     * secara prorata sampai akhir periode berjalan; paket berganti saat tagihan lunas (`PelunasTagihanLangganan`).
     * Harga paket berjalan memakai jangkar grandfathering tenant (BR-P04.1), harga paket baru = harga berlaku hari ini.
     * Kupon tidak berlaku. Pemanggil mengunci baris `Langganan` di dalam transaksi dan memastikan tidak ada tagihan
     * terbuka lain.
     */
    public function TerbitkanNaikPaket(Langganan $langganan, Paket $paketBaru, ?int $idPengguna): TagihanLangganan
    {
        $idTenant = $langganan->IdTenant;
        $sekarang = CarbonImmutable::now();
        $paketLama = Paket::query()->whereKey($langganan->IdPaket)->first();

        if ($paketLama === null) {
            throw new PelanggaranAturanBisnis('NaikPaketTidakBisa', 'Naik paket hanya bisa saat langganan berbayar masih aktif. Perpanjang atau aktifkan langganan Anda dulu.', 'KodePaket');
        }

        if (! $this->gerbang->CekAktif()) {
            throw new PelanggaranAturanBisnis('GerbangBelumAktif', 'Pembayaran tagihan belum dibuka karena gerbang pembayaran online belum diaktifkan. Hubungi tim kami.');
        }

        $hitung = $this->naikPaket->Hitung($langganan, $paketLama, $paketBaru, $sekarang, $this->tagihanTenant->AmbilMulaiLanggananPaket($paketLama->Id));
        $hargaBaru = $hitung['HargaBaru'];
        $siklus = $hitung['Siklus'];
        $tarif = null;

        if ($this->pengaturan->PlatformPkp()) {
            $tarif = $this->tarifBerlaku->Cari('Ppn', null, $sekarang)
                ?? throw new PelanggaranAturanBisnis('TarifPpnBelumTerbit', 'Tagihan belum bisa dibuat karena tarif PPN belum diterbitkan. Hubungi tim kami.');
        }

        $rincian = $this->kalkulator->Hitung(
            subtotal: $hitung['Subtotal'],
            jumlahBulan: 1,
            tarifPersen: $tarif?->Tarif,
            pengaliDppPembilang: $tarif->PengaliDppPembilang ?? 1,
            pengaliDppPenyebut: $tarif->PengaliDppPenyebut ?? 1,
        );

        if ($rincian->total->BernilaiNol()) {
            throw new PelanggaranAturanBisnis('TotalNol', 'Tagihan naik paket ini bernilai Rp 0 dan belum bisa diproses otomatis. Hubungi tim kami.');
        }

        $akhirPeriode = CarbonImmutable::instance($langganan->PeriodeSelesai
            ?? throw new PelanggaranAturanBisnis('NaikPaketTidakBisa', 'Periode langganan belum tercatat. Hubungi tim kami.', 'KodePaket'));
        $jatuhTempo = $sekarang->addDays((int) config('tagihan.HariJatuhTempo'));
        $jatuhTempo = $jatuhTempo->greaterThan($akhirPeriode) ? $akhirPeriode : $jatuhTempo;
        $tagihan = TagihanLangganan::query()->create([
            'IdTenant' => $idTenant,
            'Nomor' => $this->penomor->Ambil($sekarang),
            'Jenis' => JenisTagihanLangganan::NaikPaket,
            'Status' => StatusTagihanLangganan::Terbit,
            'IdPaket' => $paketBaru->Id,
            'IdHargaPaket' => $hargaBaru->Id,
            'IdPaketAsal' => $paketLama->Id,
            'Siklus' => $siklus,
            'JumlahBulan' => PenghitungPeriodeLangganan::JumlahBulan($siklus),
            'HariDitagih' => $hitung['HariDitagih'],
            'HariPeriode' => $hitung['HariPeriode'],
            'Subtotal' => $rincian->subtotal->KeString(),
            'Diskon' => '0.00',
            'IdTarifPajak' => $tarif?->Id,
            'TarifPpn' => $tarif->Tarif ?? '0',
            'PengaliDppPembilang' => $tarif->PengaliDppPembilang ?? 1,
            'PengaliDppPenyebut' => $tarif->PengaliDppPenyebut ?? 1,
            'DasarPengenaanPajak' => $rincian->dasarPengenaanPajak->KeString(),
            'JumlahPpn' => $rincian->jumlahPpn->KeString(),
            'Total' => $rincian->total->KeString(),
            'TerbitPada' => $sekarang,
            'JatuhTempoPada' => $jatuhTempo,
            'IdPenggunaPembuat' => $idPengguna,
            // Pemilik yang menaikkan paketnya sendiri sudah tahu tagihannya terbit; tagihan tengah periode tanpa pengingat.
            'PengingatTerakhir' => $idPengguna === null ? null : TahapPengingatTagihan::Tentukan($sekarang, $jatuhTempo)?->value,
        ]);

        $this->audit->Catat('langganan.tagihan-naik-paket', $tagihan, nilaiBaru: [
            'Nomor' => $tagihan->Nomor, 'PaketAsal' => $paketLama->Kode, 'PaketBaru' => $paketBaru->Kode, 'Siklus' => $siklus->value,
            'Selisih' => $hitung['Selisih']->KeString(), 'HariDitagih' => $hitung['HariDitagih'], 'HariPeriode' => $hitung['HariPeriode'],
            'Total' => $rincian->total->KeString(),
        ], idTenant: $idTenant);

        return $tagihan;
    }

    /**
     * D-49/D-98: tagihan tengah periode (add-on, naik paket) yang belum dibayar tidak boleh menghalangi tagihan paket
     * (BR-P08.4: satu tagihan terbuka). Yang terbuka tanpa pembayaran yang sedang diproses dibatalkan, lalu pemilik
     * membelinya lagi bila perlu.
     *
     * @return int jumlah tagihan yang dibatalkan
     */
    public function BatalkanTagihanTengahPeriodeTerbuka(int $idTenant, string $alasan): int
    {
        $jumlah = 0;
        $daftar = TagihanLangganan::query()
            ->where('IdTenant', $idTenant)
            ->whereIn('Jenis', [JenisTagihanLangganan::Addon->value, JenisTagihanLangganan::NaikPaket->value])
            ->whereIn('Status', StatusTagihanLangganan::NilaiTerbuka())
            ->lockForUpdate()
            ->get();

        foreach ($daftar as $tagihan) {
            $menunggu = PembayaranLangganan::query()
                ->where('IdTagihanLangganan', $tagihan->Id)
                ->where('Status', StatusPembayaranLangganan::Menunggu->value)
                ->exists();

            if ($menunggu) {
                continue;
            }

            $lama = $tagihan->Status;
            $tagihan->update(['Status' => StatusTagihanLangganan::Dibatalkan, 'DibatalkanPada' => now(), 'AlasanBatal' => $alasan]);
            $this->audit->Catat('langganan.tagihan-batal', $tagihan, nilaiLama: ['Status' => $lama->value], nilaiBaru: [
                'Status' => StatusTagihanLangganan::Dibatalkan->value, 'Nomor' => $tagihan->Nomor, 'Alasan' => $alasan,
            ], idTenant: $idTenant);
            $jumlah++;
        }

        return $jumlah;
    }

    /**
     * Add-on yang ikut ditagih di perpanjangan: yang belum berhenti berlangganan. Harga = harga bulanan add-on saat ini
     * × bulan siklus × jumlah.
     *
     * @return list<array{Addon: Addon, Jumlah: int, Subtotal: Uang, JumlahBulan: int, Prorata: bool, HariDitagih: int|null, HariPeriode: int|null}>
     */
    private function SusunBarisAddonPerpanjangan(int $idTenant, int $jumlahBulan): array
    {
        $hasil = [];

        foreach (LanggananAddon::query()->with('Addon')->where('IdTenant', $idTenant)->where('PerpanjangOtomatis', true)->whereNull('BerhentiPada')->orderBy('Id')->get() as $milik) {
            $harga = Uang::Dari($milik->Addon->HargaBulanan);

            if ($harga->Bandingkan(Uang::Nol()) <= 0) {
                continue;
            }

            $hasil[] = [
                'Addon' => $milik->Addon,
                'Jumlah' => $milik->Jumlah,
                'Subtotal' => $harga->Kali($jumlahBulan * $milik->Jumlah),
                'JumlahBulan' => $jumlahBulan,
                'Prorata' => false,
                'HariDitagih' => null,
                'HariPeriode' => null,
            ];
        }

        return $hasil;
    }

    /**
     * @param  array{Addon: Addon, Jumlah: int, Subtotal: Uang, JumlahBulan: int, Prorata: bool, HariDitagih: int|null, HariPeriode: int|null}  $baris
     */
    private function CatatBarisAddon(TagihanLangganan $tagihan, array $baris, \DateTimeInterface $mulai, \DateTimeInterface $selesai): void
    {
        TagihanLanggananAddon::query()->create([
            'IdTenant' => $tagihan->IdTenant,
            'IdTagihanLangganan' => $tagihan->Id,
            'IdAddon' => $baris['Addon']->Id,
            'KodeAddon' => $baris['Addon']->Kode,
            'NamaAddon' => $baris['Addon']->Nama,
            'Jumlah' => $baris['Jumlah'],
            'HargaBulanan' => (string) $baris['Addon']->HargaBulanan,
            'JumlahBulan' => $baris['JumlahBulan'],
            'Prorata' => $baris['Prorata'],
            'HariDitagih' => $baris['HariDitagih'],
            'HariPeriode' => $baris['HariPeriode'],
            'Subtotal' => $baris['Subtotal']->KeString(),
            'MulaiPada' => $mulai,
            'SelesaiPada' => $selesai,
        ]);
    }

    /** Bulan berdiskon kupon yang sudah dipakai tenant ini (pemakaian yang dibatalkan tidak dihitung, BR-P08.7). */
    public function HitungBulanTerpakai(int $idTenant, KuponLangganan $kupon): int
    {
        return (int) KuponLanggananPemakaian::query()
            ->where('IdTenant', $idTenant)
            ->where('IdKuponLangganan', $kupon->Id)
            ->whereNull('DibatalkanPada')
            ->sum('BulanDiskon');
    }

    /** BR-P08.8: perpanjangan jatuh tempo di akhir periode berjalan; selain itu terbit + `tagihan.HariJatuhTempo`. */
    private function TentukanJatuhTempo(JenisTagihanLangganan $jenis, Langganan $langganan, CarbonImmutable $sekarang): CarbonImmutable
    {
        $akhirPeriode = $langganan->PeriodeSelesai === null ? null : CarbonImmutable::instance($langganan->PeriodeSelesai);

        if ($jenis === JenisTagihanLangganan::Perpanjangan && $akhirPeriode !== null && $akhirPeriode->greaterThan($sekarang)) {
            return $akhirPeriode;
        }

        return $sekarang->addDays((int) config('tagihan.HariJatuhTempo'));
    }
}
