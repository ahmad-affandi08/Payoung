<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Rumus perubahan harga massal yang dipakai bersama (F-03): naik/turun dengan persen atau nominal lalu dibulatkan ke
 * kelipatan tertentu. Satu rumus untuk "Ubah harga" massal di daftar Produk (`UbahHargaProdukMassal`, mengubah harga
 * dasar) dan "Atur harga ojol" (`AturHargaKanal`, mengisi daftar harga kanal), supaya hasilnya tidak bisa berbeda.
 * Semua hitungan memakai `Uang`/`BigDecimal`, tidak pernah float (aturan emas #7).
 */
final class PenghitungPerubahanHarga
{
    public const MODE = ['NaikPersen', 'TurunPersen', 'NaikNominal', 'TurunNominal'];

    public const PEMBULATAN = [0, 100, 500, 1000];

    /**
     * Memeriksa pilihan cara ubah dan pembulatan.
     *
     * @throws PelanggaranAturanBisnis ModeTidakDikenal, PembulatanTidakDikenal
     */
    public static function PastikanPilihanSah(string $mode, int $pembulatan): void
    {
        if (! in_array($mode, self::MODE, true)) {
            throw new PelanggaranAturanBisnis('ModeTidakDikenal', 'Cara ubah harga tidak dikenal.', 'Mode');
        }

        if (! in_array($pembulatan, self::PEMBULATAN, true)) {
            throw new PelanggaranAturanBisnis('PembulatanTidakDikenal', 'Pembulatan tidak dikenal.', 'Pembulatan');
        }
    }

    /**
     * Angka perubahan dari isian pengguna ("10", "2500", "7,5"): lebih dari 0, maksimal dua desimal, persen paling
     * banyak 1000.
     *
     * @throws PelanggaranAturanBisnis NilaiTidakValid
     */
    public static function AmbilAngka(string $nilai, bool $persen): BigDecimal
    {
        $teks = str_replace(',', '.', trim($nilai));

        if (preg_match('/^\d{1,9}(\.\d{1,2})?$/', $teks) !== 1) {
            throw new PelanggaranAturanBisnis('NilaiTidakValid', 'Isi angka lebih dari 0, tanpa tanda minus.', 'Nilai');
        }

        $angka = BigDecimal::of($teks);

        if ($angka->isLessThanOrEqualTo(0) || ($persen && $angka->isGreaterThan(1000))) {
            throw new PelanggaranAturanBisnis('NilaiTidakValid', $persen ? 'Persen harus lebih dari 0 dan paling banyak 1000.' : 'Isi angka lebih dari 0.', 'Nilai');
        }

        return $angka;
    }

    /** Harga baru dari harga lama. Bisa negatif (penurunan terlalu besar); pemanggil yang menolaknya. */
    public static function Hitung(Uang $lama, string $mode, BigDecimal $angka, int $pembulatan): Uang
    {
        $baru = match ($mode) {
            'NaikPersen' => $lama->Kali(BigDecimal::one()->plus($angka->dividedBy(100, 6, RoundingMode::HalfUp))),
            'TurunPersen' => $lama->Kali(BigDecimal::one()->minus($angka->dividedBy(100, 6, RoundingMode::HalfUp))),
            'NaikNominal' => $lama->Tambah(Uang::Dari($angka)),
            default => $lama->Kurangi(Uang::Dari($angka)),
        };

        return $pembulatan > 0 && ! $baru->BernilaiNegatif() ? $baru->BulatkanKeKelipatan($pembulatan, RoundingMode::HalfUp) : $baru;
    }
}
