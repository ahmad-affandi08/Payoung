<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\DaftarKapasitasOrganisasi;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPaket;
use App\Domain\Tenant\Enum\StatusPermintaanTurunPaket;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Kueri\PermintaanTurunPaketMenunggu;
use App\Domain\Tenant\Layanan\PembandingHargaTurunPaket;
use App\Domain\Tenant\Layanan\PemvalidasiPilihanKapasitas;
use App\Domain\Tenant\Layanan\PenilaiKapasitasPaket;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\PermintaanTurunPaket;
use App\Domain\Tenant\Model\TagihanLangganan;
use Illuminate\Support\Facades\DB;

/**
 * Owner meminta turun ke paket yang lebih murah atau ke Gratis (BR-P04.4, audit E5). Berlaku pada perpanjangan berikutnya
 * (tagihan perpanjangan memakai paket tujuan) atau, untuk Gratis, saat periode berbayar berakhir; tidak ada pengembalian
 * dana karena periode yang sudah dibayar tetap dinikmati penuh. Bila paket tujuan lebih kecil dari pemakaian outlet atau
 * pengguna, Owner wajib memilih yang tetap aktif (sisanya diarsipkan/dinonaktifkan saat berlaku; data tidak dihapus).
 * Kelebihan SKU dan perangkat hanya memblokir penambahan baru (SKU tidak bisa "dinonaktifkan"; perangkat dicabut manual).
 */
final class AjukanTurunPaket
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenilaiKapasitasPaket $penilai,
        private readonly DaftarKapasitasOrganisasi $daftar,
        private readonly PembandingHargaTurunPaket $pembanding,
        private readonly PemvalidasiPilihanKapasitas $pemvalidasi,
        private readonly PermintaanTurunPaketMenunggu $menunggu,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @param  list<string>  $uuidOutletTetap
     * @param  list<string>  $uuidAnggotaTetap
     */
    public function Jalankan(int $idPengguna, string $kodePaket, array $uuidOutletTetap = [], array $uuidAnggotaTetap = []): PermintaanTurunPaket
    {
        $idTenant = $this->konteks->Wajib();

        return DB::transaction(function () use ($idTenant, $idPengguna, $kodePaket, $uuidOutletTetap, $uuidAnggotaTetap): PermintaanTurunPaket {
            $langganan = Langganan::query()->where('IdTenant', $idTenant)->lockForUpdate()->with('Paket')->first()
                ?? throw new PelanggaranAturanBisnis('LanggananTidakAda', 'Data langganan usaha ini tidak ditemukan. Hubungi tim kami.');

            $selesai = $langganan->PeriodeSelesai;

            if ($langganan->Status !== StatusLangganan::Aktif || $selesai === null || ! $selesai->isFuture()) {
                throw new PelanggaranAturanBisnis('TurunPaketTidakBisa', 'Turun paket hanya bisa saat langganan berbayar sedang aktif.', 'KodePaket');
            }

            if ($langganan->CekDitangguhkanManual()) {
                throw new PelanggaranAturanBisnis('BR-P07.4', 'Usaha ini sedang ditangguhkan oleh tim kami. Hubungi tim kami lewat menu Bantuan.');
            }

            if ($langganan->Paket->HargaNegosiasi) {
                throw new PelanggaranAturanBisnis('PaketNegosiasi', 'Paket dengan harga khusus diubah lewat tim kami. Hubungi kami lewat menu Bantuan.', 'KodePaket');
            }

            if ($this->menunggu->Cari($langganan->Id) !== null) {
                throw new PelanggaranAturanBisnis('TurunPaketSudahAda', 'Sudah ada permintaan turun paket yang menunggu. Batalkan dulu bila ingin menggantinya.', 'KodePaket');
            }

            $terbuka = TagihanLangganan::query()
                ->whereIn('Status', StatusTagihanLangganan::NilaiTerbuka())
                ->whereIn('Jenis', [JenisTagihanLangganan::Perpanjangan->value, JenisTagihanLangganan::Aktivasi->value])
                ->first();

            if ($terbuka !== null) {
                throw new PelanggaranAturanBisnis(
                    'TagihanMasihTerbuka',
                    "Tagihan {$terbuka->Nomor} untuk paket {$langganan->Paket->Nama} masih terbuka. Batalkan tagihan itu dulu supaya tagihan berikutnya memakai paket baru.",
                    'KodePaket',
                );
            }

            $tujuan = Paket::query()->where('Kode', strtoupper(trim($kodePaket)))->first();
            $gratis = $tujuan !== null && $tujuan->Kode === (string) config('tenant.KodePaketGratis');

            if ($tujuan === null || $tujuan->Id === $langganan->IdPaket || $tujuan->Status !== StatusPaket::Aktif || $tujuan->HargaNegosiasi) {
                throw new PelanggaranAturanBisnis('PaketTidakTersedia', 'Paket ini tidak bisa dipilih. Pilih paket lain dari daftar.', 'KodePaket');
            }

            if (! $gratis) {
                $this->PastikanLebihMurah($langganan, $tujuan);
            }

            $outletAktif = $this->daftar->AmbilOutletAktif();
            $kelebihan = $this->penilai->Nilai($idTenant, $tujuan, $this->daftar->AmbilIdOutletAktif());
            [$outletTetap, $anggotaTetap] = $this->pemvalidasi->Periksa($kelebihan, $outletAktif, $this->daftar->AmbilAnggotaAktif($idTenant), $uuidOutletTetap, $uuidAnggotaTetap);

            $permintaan = PermintaanTurunPaket::query()->create([
                'IdTenant' => $idTenant,
                'IdLangganan' => $langganan->Id,
                'IdPaketAsal' => $langganan->IdPaket,
                'IdPaketTujuan' => $tujuan->Id,
                'Status' => StatusPermintaanTurunPaket::Menunggu,
                'BerlakuPada' => $selesai,
                'UuidOutletTetap' => $outletTetap,
                'UuidAnggotaTetap' => $anggotaTetap,
                'Kelebihan' => $kelebihan,
                'IdPenggunaPengaju' => $idPengguna,
            ]);

            $this->audit->Catat('langganan.turun-paket-ajukan', $permintaan, nilaiBaru: [
                'PaketAsal' => $langganan->Paket->Kode,
                'PaketTujuan' => $tujuan->Kode,
                'BerlakuPada' => $selesai->toIso8601ZuluString(),
                'Kelebihan' => $kelebihan['Lebih'],
            ], idTenant: $idTenant, idPengguna: $idPengguna);

            return $permintaan;
        });
    }

    private function PastikanLebihMurah(Langganan $langganan, Paket $tujuan): void
    {
        $banding = $this->pembanding->Bandingkan($langganan, $tujuan)
            ?? throw new PelanggaranAturanBisnis('HargaBelumTersedia', 'Harga paket belum tersedia. Hubungi tim kami.', 'KodePaket');

        if (! $banding['LebihMurah']) {
            throw new PelanggaranAturanBisnis('BukanTurunPaket', "Paket {$tujuan->Nama} tidak lebih murah dari paket {$langganan->Paket->Nama}. Untuk paket yang lebih mahal, pakai Naik paket.", 'KodePaket');
        }
    }
}
