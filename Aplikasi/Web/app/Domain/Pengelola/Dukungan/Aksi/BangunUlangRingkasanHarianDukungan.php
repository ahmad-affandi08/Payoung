<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Dukungan\Enum\AlatDukungan;
use App\Domain\Dukungan\Enum\StatusHasilAlatDukungan;
use App\Domain\Laporan\Aksi\BangunUlangRingkasanPenjualanHarian;
use App\Domain\Laporan\Model\RingkasanPenjualanHarian;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pengelola\Dukungan\Data\HasilKerjaAlat;
use App\Domain\Pengelola\Dukungan\Layanan\PelaksanaAlatDukungan;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Alat bantu (ubah): hitung ulang `RingkasanPenjualanHarian` dari dokumen sumber untuk rentang tanggal bisnis (PGL-16
 * bagian 2, BR-P09.3), dengan `BangunUlangRingkasanPenjualanHarian` yang sama dengan `laporan:bangun-ulang-ringkasan`.
 * Rentang paling lama `MAKS_HARI` hari dan tidak boleh melewati hari ini. Hasil memuat Σ bersih, Σ transaksi, dan jumlah
 * baris sebelum dan sesudah.
 */
final class BangunUlangRingkasanHarianDukungan
{
    public const MAKS_HARI = 31;

    public function __construct(
        private readonly PelaksanaAlatDukungan $pelaksana,
        private readonly BangunUlangRingkasanPenjualanHarian $bangunUlang,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
    ) {}

    public function Jalankan(PenggunaPengelola $petugas, int $idTenant, CarbonImmutable $dari, CarbonImmutable $sampai): HasilKerjaAlat
    {
        $dari = $dari->startOfDay();
        $sampai = $sampai->startOfDay();
        $parameter = ['Dari' => $dari->toDateString(), 'Sampai' => $sampai->toDateString()];

        return $this->pelaksana->Jalankan($petugas, $idTenant, AlatDukungan::BangunUlangRingkasanHarian, $parameter, function () use ($dari, $sampai): HasilKerjaAlat {
            if ($sampai->lt($dari)) {
                throw new PelanggaranAturanBisnis('RentangTidakValid', 'Tanggal akhir tidak boleh sebelum tanggal awal.', 'Sampai');
            }

            if ($dari->diffInDays($sampai) + 1 > self::MAKS_HARI) {
                throw new PelanggaranAturanBisnis('RentangTerlaluPanjang', 'Rentang paling lama '.self::MAKS_HARI.' hari.', 'Sampai');
            }

            if ($sampai->gt(CarbonImmutable::parse($this->tanggalBisnis->Hitung(null)->toDateString()))) {
                throw new PelanggaranAturanBisnis('TanggalDiMasaDepan', 'Tanggal akhir tidak boleh melewati hari ini menurut zona waktu tenant.', 'Sampai');
            }

            $sebelum = $this->Keadaan($dari, $sampai);

            for ($hari = $dari; $hari->lte($sampai); $hari = $hari->addDay()) {
                $this->bangunUlang->Jalankan($hari);
            }

            $sesudah = $this->Keadaan($dari, $sampai);
            $sama = $sebelum === $sesudah;

            return new HasilKerjaAlat(
                $sama ? StatusHasilAlatDukungan::TanpaPerubahan : StatusHasilAlatDukungan::Berhasil,
                $sama
                    ? 'Ringkasan harian sudah sama dengan dokumen sumber. Tidak ada yang berubah.'
                    : "Ringkasan {$dari->toDateString()} sampai {$sampai->toDateString()} dibangun ulang: bersih {$sebelum['TotalBersih']} menjadi {$sesudah['TotalBersih']}.",
                $sebelum,
                $sesudah,
            );
        });
    }

    /**
     * @return array{JumlahBaris: int, TotalBersih: string, TotalTransaksi: int}
     */
    private function Keadaan(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $bersih = BigDecimal::zero();
        $transaksi = 0;
        $baris = 0;

        foreach (RingkasanPenjualanHarian::query()->whereBetween('TanggalBisnis', [$dari->toDateString(), $sampai->toDateString()])->get(['Bersih', 'JumlahTransaksi']) as $ringkasan) {
            $bersih = $bersih->plus($ringkasan->Bersih);
            $transaksi += $ringkasan->JumlahTransaksi;
            $baris++;
        }

        return ['JumlahBaris' => $baris, 'TotalBersih' => (string) $bersih->toScale(2), 'TotalTransaksi' => $transaksi];
    }
}
