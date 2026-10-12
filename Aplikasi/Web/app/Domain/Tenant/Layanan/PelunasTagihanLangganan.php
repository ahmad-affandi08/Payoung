<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Tenant\Data\HasilPelunasanLangganan;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\LanggananAddon;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\TagihanLanggananAddon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Inti pelunasan tagihan langganan (BR-P08.9): pembayaran Diterima → tagihan Lunas → langganan Aktif dengan paket,
 * siklus, dan periode baru.
 *
 * Dipakai **kedua** jalur pembayaran P-08 langkah 3 supaya hasilnya tidak bisa berbeda:
 * - transfer manual, diverifikasi Keuangan/Super Admin (`TerimaPembayaranLangganan`);
 * - gerbang pembayaran, dari notifikasi webhook (`TerimaNotifikasiBillingLangganan`).
 *
 * Layanan ini **tidak** memuat, mengunci, atau mengambil data apa pun: ketiga model harus sudah dikunci pemanggil
 * dengan urutan Langganan → Tagihan → Pembayaran. Alasannya, kedua jalur memuatnya dari kueri berbeda — pengelola
 * lintas tenant, atau tenant lewat scope `MilikTenant` — sedangkan keputusan dan tulisannya harus sama.
 * Audit juga milik pemanggil, karena pelakunya berbeda.
 */
final class PelunasTagihanLangganan
{
    public function __construct(
        private readonly PenghitungPeriodeLangganan $periode,
        private readonly PencatatKomisiMitra $komisiMitra,
    ) {}

    /**
     * @param  CarbonImmutable|null  $mulaiPaketSebelumnya  Jangkar grandfathering (BR-P04.1) dari tagihan lunas
     *                                                      terakhir untuk paket yang sama; hanya dipakai bila
     *                                                      tagihan ini memang menyambung periode berjalan.
     *
     * @throws PelanggaranAturanBisnis
     */
    public function Lunasi(
        Langganan $langganan,
        TagihanLangganan $tagihan,
        PembayaranLangganan $pembayaran,
        Uang $diterima,
        CarbonImmutable $sekarang,
        ?int $idVerifikatorPengelola = null,
        ?CarbonImmutable $mulaiPaketSebelumnya = null,
    ): HasilPelunasanLangganan {
        if ($pembayaran->Status !== StatusPembayaranLangganan::Menunggu) {
            throw new PelanggaranAturanBisnis('SudahDiverifikasi', "Pembayaran ini sudah {$pembayaran->Status->AmbilLabel()}.");
        }

        if (! $tagihan->Status->CekTerbuka()) {
            throw new PelanggaranAturanBisnis('TagihanTidakTerbuka', "Tagihan {$tagihan->Nomor} sudah {$tagihan->Status->AmbilLabel()}.");
        }

        if (! $diterima->SamaDengan($tagihan->AmbilTotal()) || ! $diterima->SamaDengan($pembayaran->AmbilJumlah())) {
            throw new PelanggaranAturanBisnis(
                'JumlahTidakCocok',
                'Jumlah diterima harus sama dengan total tagihan '.$tagihan->AmbilTotal()->FormatRupiah().'. Bila berbeda, tolak dengan alasan.',
                'JumlahDiterima',
            );
        }

        if ($langganan->CekDitangguhkanManual()) {
            throw new PelanggaranAturanBisnis(
                'BR-P07.4',
                'Tenant ini ditangguhkan manual oleh Super Admin. Penangguhan harus dicabut lewat "Aktifkan kembali" di halaman tenant sebelum pembayaran bisa diterima.',
            );
        }

        if ($tagihan->Jenis === JenisTagihanLangganan::Addon) {
            return $this->LunasiAddon($langganan, $tagihan, $pembayaran, $diterima, $sekarang, $idVerifikatorPengelola);
        }

        if ($tagihan->Jenis === JenisTagihanLangganan::NaikPaket) {
            return $this->LunasiNaikPaket($langganan, $tagihan, $pembayaran, $diterima, $sekarang, $idVerifikatorPengelola);
        }

        if ($langganan->Status !== StatusLangganan::Aktif && ! $langganan->Status->BisaBerubahKe(StatusLangganan::Aktif)) {
            throw new PelanggaranAturanBisnis('LanggananTidakBisaAktif', "Langganan berstatus {$langganan->Status->AmbilLabel()} tidak bisa diaktifkan dari tagihan.");
        }

        // Menyambung periode berjalan hanya bila tagihan perpanjangan untuk paket yang sama persis; selain itu
        // periode dimulai saat pembayaran diterima, sehingga masa tenggang tidak menjadi hari gratis.
        $samaPaket = $langganan->IdPaket === $tagihan->IdPaket;
        // BR-P04.4: perpanjangan ke paket lebih murah (turun paket) tetap menyambung periode berjalan; ia menyebut paket asalnya.
        $lanjutan = $tagihan->Jenis === JenisTagihanLangganan::Perpanjangan
            && in_array($langganan->Status, [StatusLangganan::Aktif, StatusLangganan::Tertunggak], true)
            && ($samaPaket || ($tagihan->IdPaketAsal !== null && $tagihan->IdPaketAsal === $langganan->IdPaket));
        $periode = $this->periode->Hitung(
            $lanjutan ? JenisTagihanLangganan::Perpanjangan : JenisTagihanLangganan::Aktivasi,
            $tagihan->Siklus,
            $sekarang,
            $langganan->PeriodeSelesai,
        );
        $mulaiPaket = $lanjutan && $samaPaket ? $mulaiPaketSebelumnya : null;

        $statusTagihanLama = $tagihan->Status->value;
        $langgananLama = [
            'Status' => $langganan->Status->value,
            'IdPaket' => $langganan->IdPaket,
            'SiklusTagihan' => $langganan->SiklusTagihan->value,
            'PeriodeMulai' => $langganan->PeriodeMulai?->toIso8601ZuluString(),
            'PeriodeSelesai' => $langganan->PeriodeSelesai?->toIso8601ZuluString(),
        ];

        $pembayaran->update([
            'Status' => StatusPembayaranLangganan::Diterima,
            'IdPenggunaPengelolaVerifikator' => $idVerifikatorPengelola,
            'DiverifikasiPada' => $sekarang,
            'JumlahDiterima' => $diterima->KeString(),
        ]);

        $tagihan->update([
            'Status' => StatusTagihanLangganan::Lunas,
            'DibayarPada' => $sekarang,
            'PeriodeMulai' => $periode['Mulai'],
            'PeriodeSelesai' => $periode['Selesai'],
            'MulaiLanggananPaket' => ($mulaiPaket ?? $periode['Mulai'])->toDateString(),
        ]);

        $langganan->update([
            'Status' => StatusLangganan::Aktif,
            'IdPaket' => $tagihan->IdPaket,
            'SiklusTagihan' => $tagihan->Siklus,
            'PeriodeMulai' => $periode['Mulai'],
            'PeriodeSelesai' => $periode['Selesai'],
        ]);

        // D-49: add-on yang ikut ditagih di perpanjangan diperpanjang sampai akhir periode baru.
        $this->PerpanjangAddonTagihan($tagihan, $periode['Mulai'], $periode['Selesai']);

        // P-12 (BR-P12.1): komisi mitra perujuk lahir dari tagihan lunas, di transaksi pelunasan yang sama.
        $this->komisiMitra->CatatDariTagihan($tagihan);

        return new HasilPelunasanLangganan($periode, $langgananLama, $statusTagihanLama, $lanjutan);
    }

    /**
     * D-49: tagihan add-on lunas → add-on aktif sampai akhir periode langganan berjalan. Paket, siklus, dan periode
     * langganan tidak berubah. Pembayaran yang baru masuk setelah periode berganti memakai akhir periode terbaru.
     */
    private function LunasiAddon(
        Langganan $langganan,
        TagihanLangganan $tagihan,
        PembayaranLangganan $pembayaran,
        Uang $diterima,
        CarbonImmutable $sekarang,
        ?int $idVerifikatorPengelola,
    ): HasilPelunasanLangganan {
        if (! in_array($langganan->Status, [StatusLangganan::Aktif, StatusLangganan::Tertunggak], true)) {
            throw new PelanggaranAturanBisnis('LanggananTidakAktif', "Langganan berstatus {$langganan->Status->AmbilLabel()} tidak bisa menerima pembayaran add-on. Hubungi tim kami.");
        }

        $rincian = TagihanLanggananAddon::query()->where('IdTagihanLangganan', $tagihan->Id)->get();
        $akhirPeriode = $langganan->PeriodeSelesai !== null && $langganan->PeriodeSelesai->greaterThan($sekarang)
            ? CarbonImmutable::instance($langganan->PeriodeSelesai)
            : null;
        $statusTagihanLama = $tagihan->Status->value;
        $langgananLama = [
            'Status' => $langganan->Status->value,
            'IdPaket' => $langganan->IdPaket,
            'SiklusTagihan' => $langganan->SiklusTagihan->value,
            'PeriodeMulai' => $langganan->PeriodeMulai?->toIso8601ZuluString(),
            'PeriodeSelesai' => $langganan->PeriodeSelesai?->toIso8601ZuluString(),
        ];
        $selesaiTagihan = $sekarang;

        $pembayaran->update([
            'Status' => StatusPembayaranLangganan::Diterima,
            'IdPenggunaPengelolaVerifikator' => $idVerifikatorPengelola,
            'DiverifikasiPada' => $sekarang,
            'JumlahDiterima' => $diterima->KeString(),
        ]);

        foreach ($rincian as $baris) {
            $selesai = $akhirPeriode ?? CarbonImmutable::instance($baris->SelesaiPada);
            $selesaiTagihan = $selesai;
            $milik = LanggananAddon::query()->where('IdTenant', $tagihan->IdTenant)->where('IdAddon', $baris->IdAddon)->lockForUpdate()->first();

            if ($milik === null) {
                LanggananAddon::query()->create([
                    'IdTenant' => $tagihan->IdTenant,
                    'IdAddon' => $baris->IdAddon,
                    'Jumlah' => $baris->Jumlah,
                    'MulaiPada' => $sekarang,
                    'SelesaiPada' => $selesai,
                    'PerpanjangOtomatis' => true,
                    'IdTagihanLanggananAsal' => $tagihan->Id,
                ]);

                continue;
            }

            $masihAktif = $milik->CekAktifPada(Carbon::instance($sekarang));
            $milik->update([
                'Jumlah' => $baris->Jumlah,
                'MulaiPada' => $masihAktif ? $milik->MulaiPada : $sekarang,
                'SelesaiPada' => $selesai->greaterThan($milik->SelesaiPada) ? $selesai : $milik->SelesaiPada,
                'PerpanjangOtomatis' => true,
                'BerhentiPada' => null,
                'IdTagihanLanggananAsal' => $tagihan->Id,
            ]);
        }

        $tagihan->update([
            'Status' => StatusTagihanLangganan::Lunas,
            'DibayarPada' => $sekarang,
            'PeriodeMulai' => $sekarang,
            'PeriodeSelesai' => $selesaiTagihan,
        ]);

        $this->komisiMitra->CatatDariTagihan($tagihan);

        return new HasilPelunasanLangganan(
            ['Mulai' => $sekarang, 'Selesai' => $selesaiTagihan],
            $langgananLama,
            $statusTagihanLama,
            false,
            langgananTidakBerubah: true,
        );
    }

    /**
     * D-98: tagihan naik paket lunas → paket langganan berganti saat itu juga. Siklus dan periode langganan tidak
     * berubah (selisih sudah ditagih prorata sampai akhir periode); perpanjangan berikutnya memakai paket baru. Hanya
     * sah selama langganan masih Aktif di paket asal dan periodenya belum berakhir; kalau tidak, pembayaran dibiarkan
     * `Menunggu` untuk ditinjau pengelola (jangan memberi paket mahal tanpa sisa periode yang dibayar).
     * `MulaiLanggananPaket` = hari ini: jangkar grandfathering paket baru dimulai dari naik paket (BR-P04.1).
     */
    private function LunasiNaikPaket(
        Langganan $langganan,
        TagihanLangganan $tagihan,
        PembayaranLangganan $pembayaran,
        Uang $diterima,
        CarbonImmutable $sekarang,
        ?int $idVerifikatorPengelola,
    ): HasilPelunasanLangganan {
        if (
            $langganan->Status !== StatusLangganan::Aktif
            || $langganan->IdPaket !== $tagihan->IdPaketAsal
            || $langganan->PeriodeSelesai === null
            || ! $langganan->PeriodeSelesai->greaterThan($sekarang)
        ) {
            throw new PelanggaranAturanBisnis(
                'NaikPaketTidakBerlaku',
                'Tagihan naik paket tidak bisa diproses: paket atau periode langganan sudah berubah sejak tagihan terbit. Tolak pembayaran ini atau hubungi tim kami.',
            );
        }

        $statusTagihanLama = $tagihan->Status->value;
        $langgananLama = [
            'Status' => $langganan->Status->value,
            'IdPaket' => $langganan->IdPaket,
            'SiklusTagihan' => $langganan->SiklusTagihan->value,
            'PeriodeMulai' => $langganan->PeriodeMulai?->toIso8601ZuluString(),
            'PeriodeSelesai' => $langganan->PeriodeSelesai->toIso8601ZuluString(),
        ];
        $selesai = CarbonImmutable::instance($langganan->PeriodeSelesai);

        $pembayaran->update([
            'Status' => StatusPembayaranLangganan::Diterima,
            'IdPenggunaPengelolaVerifikator' => $idVerifikatorPengelola,
            'DiverifikasiPada' => $sekarang,
            'JumlahDiterima' => $diterima->KeString(),
        ]);

        $tagihan->update([
            'Status' => StatusTagihanLangganan::Lunas,
            'DibayarPada' => $sekarang,
            'PeriodeMulai' => $sekarang,
            'PeriodeSelesai' => $selesai,
            'MulaiLanggananPaket' => $sekarang->toDateString(),
        ]);

        $langganan->update(['IdPaket' => $tagihan->IdPaket]);

        // P-12 (BR-P12.1): komisi mitra perujuk juga lahir dari tagihan naik paket yang lunas.
        $this->komisiMitra->CatatDariTagihan($tagihan);

        return new HasilPelunasanLangganan(['Mulai' => $sekarang, 'Selesai' => $selesai], $langgananLama, $statusTagihanLama, false);
    }

    /** Memperpanjang add-on yang ditagih pada tagihan perpanjangan sampai akhir periode baru (D-49). */
    private function PerpanjangAddonTagihan(TagihanLangganan $tagihan, CarbonImmutable $mulai, CarbonImmutable $selesai): void
    {
        if ($tagihan->Jenis !== JenisTagihanLangganan::Perpanjangan) {
            return;
        }

        foreach (TagihanLanggananAddon::query()->where('IdTagihanLangganan', $tagihan->Id)->get() as $baris) {
            $milik = LanggananAddon::query()->where('IdTenant', $tagihan->IdTenant)->where('IdAddon', $baris->IdAddon)->lockForUpdate()->first();

            if ($milik === null) {
                LanggananAddon::query()->create([
                    'IdTenant' => $tagihan->IdTenant,
                    'IdAddon' => $baris->IdAddon,
                    'Jumlah' => $baris->Jumlah,
                    'MulaiPada' => $mulai,
                    'SelesaiPada' => $selesai,
                    'PerpanjangOtomatis' => true,
                    'IdTagihanLanggananAsal' => $tagihan->Id,
                ]);

                continue;
            }

            $milik->update([
                'MulaiPada' => $milik->SelesaiPada->lessThan($mulai) ? $mulai : $milik->MulaiPada,
                'SelesaiPada' => $selesai->greaterThan($milik->SelesaiPada) ? $selesai : $milik->SelesaiPada,
                'IdTagihanLanggananAsal' => $tagihan->Id,
            ]);
        }
    }
}
