<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pelanggan\Kueri\KreditPelanggan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Enum\StatusPesananGrosir;
use App\Domain\Penjualan\Layanan\PenghitungGrosir;
use App\Domain\Penjualan\Model\PesananGrosir;
use App\Domain\Penjualan\Model\PesananGrosirDetail;
use App\Domain\Penjualan\Model\SuratJalan;
use App\Domain\Tenant\Kueri\PengaturanKasirTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Konfirmasi SO grosir (F-12, §9.7, **BR-12.6**): draf menjadi pesanan yang boleh dikirim, dan di sinilah paparan
 * kredit pelanggan diperiksa.
 *
 * Pemeriksaannya **memakai ulang `KreditPelanggan::Periksa()` yang sama dengan BR-12.1** (penjualan tempo di kasir),
 * bukan rumus kedua: satu tenant tidak boleh punya dua definisi "melebihi limit kredit". Bedanya hanya jumlah yang
 * diuji dan cara menyetujuinya:
 *
 * - **Jumlah yang diuji** = nilai SO ini + nilai baris SO lain yang sudah dikonfirmasi tetapi **belum terkirim** +
 *   nilai surat jalan yang **sudah diserahkan tetapi belum difakturkan**. Bucket ketiga itu penting: barang yang sudah
 *   keluar gudang adalah uang toko yang sudah ada di tangan pembeli, tetapi `KreditPelanggan` baru melihatnya setelah
 *   faktur terbit (piutang). Tanpa bucket ini, pembeli bisa menghabiskan limitnya dua kali dalam satu bulan hanya
 *   karena fakturnya belum dibuat. Barang yang sudah difakturkan tidak dihitung lagi di sini — sudah jadi piutang.
 * - **Penyetujunya izin `grosir.setujui-kredit`**, bukan PIN kasir: SO dibuat di back-office oleh orang yang sudah
 *   masuk, jadi tidak ada gunanya meminta PIN lagi.
 *
 * Snapshot tarif PPN, pengali DPP, dan termin pelanggan diambil **di sini**, bukan saat draf dibuat, supaya angka
 * dokumen terikat pada saat kesepakatan dan tidak bergeser bila `TarifPajak` atau termin pelanggan berubah kemudian.
 */
final class KonfirmasiPesananGrosir
{
    public function __construct(
        private readonly PenghitungGrosir $penghitung,
        private readonly KreditPelanggan $kredit,
        private readonly PengaturanKasirTenant $pengaturan,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(string $uuidPesanan, int $idPengguna, bool $bolehSetujuiKredit, ?string $alasanPersetujuan = null): PesananGrosir
    {
        return DB::transaction(function () use ($uuidPesanan, $idPengguna, $bolehSetujuiKredit, $alasanPersetujuan): PesananGrosir {
            // Urutan kunci: Pelanggan dulu, baru baris pesanan. Dibalik (pesanan dulu) dua konfirmasi untuk pelanggan yang
            // sama saling mengunci: yang satu memegang SO-nya dan menunggu pelanggan, yang lain memegang pelanggan dan
            // memindai SO milik yang pertama saat menghitung paparan (deadlock). Id pelanggan dibaca tanpa kunci, lalu
            // dicocokkan lagi setelah pesanan dikunci.
            $idPelangganAwal = PesananGrosir::query()->where('Uuid', $uuidPesanan)->value('IdPelanggan');

            if ($idPelangganAwal !== null) {
                Pelanggan::query()->whereKey($idPelangganAwal)->lockForUpdate()->value('Id');
            }

            // Detail ikut dikunci: pembacaan biasa memakai snapshot yang sudah dibuat sebelum menunggu kunci di atas.
            $pesanan = PesananGrosir::query()->with(['Detail' => fn ($kueri) => $kueri->lockForUpdate(), 'Outlet'])->where('Uuid', $uuidPesanan)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('PesananTidakDitemukan', 'Pesanan grosir tidak ditemukan.');

            if ($pesanan->IdPelanggan !== $idPelangganAwal) {
                throw new PelanggaranAturanBisnis('PesananBerubah', 'Pelanggan pesanan ini berubah saat dikonfirmasi. Muat ulang lalu coba lagi.');
            }

            if ($pesanan->Status !== StatusPesananGrosir::Draf) {
                throw new PelanggaranAturanBisnis(
                    'PesananBukanDraf',
                    "Pesanan {$pesanan->Nomor} sudah {$pesanan->Status->AmbilLabel()}, jadi tidak perlu dikonfirmasi lagi.",
                );
            }

            if ($pesanan->Detail->isEmpty()) {
                throw new PelanggaranAturanBisnis('PesananTanpaBaris', 'Pesanan grosir harus punya minimal satu barang.', 'Baris');
            }

            // Baris pelanggan sudah dikunci di atas (sebelum pesanan), jadi paparan kredit di bawah dibaca dengan locking
            // read (nilai terbaru, bukan snapshot transaksi ini) dan SO yang baru dikonfirmasi pihak lain ikut terhitung.
            $pelanggan = Pelanggan::query()->whereKey($pesanan->IdPelanggan)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('PelangganTidakDikenal', 'Pelanggan pesanan ini tidak ditemukan.');
            $barisHitung = [];

            foreach ($pesanan->Detail as $baris) {
                $barisHitung[] = [
                    'Jumlah' => $baris->AmbilJumlah(),
                    'HargaSatuan' => $baris->AmbilHarga(),
                    'Diskon' => Uang::Dari($baris->Diskon),
                    'IdKelompokPajak' => $baris->IdKelompokPajak,
                    // Snapshot inklusif/eksklusif baris (null = ikut pengaturan outlet): harus nilai yang sama dengan
                    // saat draf dihitung, kalau tidak total konfirmasi bisa berbeda dari total yang dilihat pembeli.
                    'HargaTermasukPajak' => $baris->HargaTermasukPajak,
                ];
            }

            $hasil = $this->penghitung->Hitung($pesanan->IdOutlet, $pesanan->Outlet->KodeKota, $barisHitung);

            $hariIni = CarbonImmutable::parse($this->tanggalBisnis->Hitung($pesanan->IdOutlet)->toDateString());
            $paparan = $hasil->total
                ->Tambah($this->NilaiBelumTerkirimLain($pesanan))
                ->Tambah($this->NilaiTerkirimBelumDifakturkan($pesanan->IdPelanggan));
            $alasanKredit = $this->kredit->Periksa(
                $pesanan->IdPelanggan,
                $paparan,
                $hariIni,
                $this->pengaturan->Ambil()->batasHariLewatJatuhTempo,
            );
            $penyetuju = null;

            if ($alasanKredit !== []) {
                if (! $bolehSetujuiKredit) {
                    throw new PelanggaranAturanBisnis(
                        'ButuhPersetujuanKredit',
                        'Pesanan ini butuh persetujuan kredit ('.implode('; ', $alasanKredit).'). Minta pengguna dengan izin persetujuan kredit grosir untuk mengonfirmasinya.',
                    );
                }

                if ($alasanPersetujuan === null || mb_strlen(trim($alasanPersetujuan)) < 5) {
                    throw new PelanggaranAturanBisnis(
                        'AlasanPersetujuanWajib',
                        'Tulis alasan persetujuan kredit minimal 5 karakter ('.implode('; ', $alasanKredit).').',
                        'AlasanPersetujuanKredit',
                    );
                }

                $penyetuju = $idPengguna;
            }

            $status = $pesanan->Status->value;
            $pesanan->UbahStatus(StatusPesananGrosir::Dikonfirmasi);
            $pesanan->fill([
                'TerminHari' => $pelanggan->TerminHari,
                'TarifPpn' => $hasil->tarifPpn,
                'PengaliDppPembilang' => $hasil->pengaliDppPembilang,
                'PengaliDppPenyebut' => $hasil->pengaliDppPenyebut,
                'IdPenyetujuKredit' => $penyetuju,
                'AlasanPersetujuanKredit' => $penyetuju === null ? null : trim((string) $alasanPersetujuan),
                'DikonfirmasiOleh' => $idPengguna,
                'DikonfirmasiPada' => CarbonImmutable::now(),
                'DiubahOleh' => $idPengguna,
            ])->save();

            $this->riwayat->Catat(PesananGrosir::JENIS_DOKUMEN, $pesanan->Id, $status, $pesanan->Status->value, $idPengguna, $penyetuju === null ? null : trim((string) $alasanPersetujuan));
            $this->audit->Catat('grosir.pesanan-konfirmasi', $pesanan, nilaiLama: ['Status' => $status], nilaiBaru: [
                'Status' => $pesanan->Status->value,
                'Nomor' => $pesanan->Nomor,
                'Total' => $pesanan->Total,
                'PaparanKredit' => $paparan->KeString(),
                'AlasanKredit' => $alasanKredit,
                'IdPenyetujuKredit' => $penyetuju,
            ]);

            return $pesanan;
        });
    }

    /**
     * Nilai surat jalan pelanggan ini yang sudah diserahkan tetapi belum masuk faktur penjualan — isi akun
     * `PiutangBelumDifakturkan` untuk pelanggan itu (J-12.1 belum disusul J-12.2).
     */
    private function NilaiTerkirimBelumDifakturkan(int $idPelanggan): Uang
    {
        $nilai = Uang::Nol();

        foreach (SuratJalan::query()
            ->where('IdPelanggan', $idPelanggan)
            ->where('Status', StatusDokumenTerposting::Diposting->value)
            ->whereNull('IdFakturPenjualan')
            ->sharedLock()
            ->get() as $suratJalan) {
            $nilai = $nilai->Tambah($suratJalan->AmbilTotal());
        }

        return $nilai;
    }

    /**
     * Nilai baris yang belum diserahkan dari SO lain pelanggan ini yang masih terbuka. Dihitung dari baris, bukan dari
     * total dokumen, supaya SO yang sudah terkirim sebagian tidak dihitung penuh.
     */
    private function NilaiBelumTerkirimLain(PesananGrosir $pesanan): Uang
    {
        $idLain = PesananGrosir::query()
            ->where('IdPelanggan', $pesanan->IdPelanggan)
            ->where('Id', '!=', $pesanan->Id)
            ->whereIn('Status', [StatusPesananGrosir::Dikonfirmasi->value, StatusPesananGrosir::SebagianDikirim->value])
            ->sharedLock()
            ->pluck('Id')
            ->all();

        if ($idLain === []) {
            return Uang::Nol();
        }

        $nilai = Uang::Nol();

        foreach (PesananGrosirDetail::query()->whereIn('IdPesananGrosir', $idLain)->sharedLock()->get() as $baris) {
            $sisa = $baris->AmbilSisaKirim();

            if ($sisa->Bandingkan(Kuantitas::Nol()) <= 0) {
                continue;
            }

            $nilai = $nilai->Tambah($baris->AmbilHarga()->Kali($sisa->KeString()));
        }

        return $nilai;
    }
}
