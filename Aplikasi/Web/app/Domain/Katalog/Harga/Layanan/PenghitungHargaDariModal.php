<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Rumus harga jual dari modal (D-266): harga = modal × (1 + untung/100), lalu dibulatkan ke kelipatan terdekat.
 * "Untung" dihitung dari modal (modal 10.000 dengan untung 20% menjadi 12.000), bukan margin dari harga jual. Dipakai
 * "Harga dari harga beli" massal dan harga otomatis, supaya hasilnya tidak bisa berbeda. Pembulatan memakai daftar
 * yang sama dengan perubahan harga massal. Semua hitungan `BigDecimal`/`Uang`, tidak pernah float (aturan emas #7).
 */
final class PenghitungHargaDariModal
{
    /** Persen untung paling banyak, sama dengan batas perubahan harga massal. */
    public const PERSEN_MAKSIMAL = 1000;

    /**
     * Persen untung dari isian pengguna ("20", "7,5"): 0 sampai 1000, maksimal dua desimal.
     *
     * @throws PelanggaranAturanBisnis UntungTidakValid
     */
    public static function AmbilPersen(string $nilai): BigDecimal
    {
        $teks = str_replace(',', '.', trim($nilai));

        if (preg_match('/^\d{1,4}(\.\d{1,2})?$/', $teks) !== 1) {
            throw new PelanggaranAturanBisnis('UntungTidakValid', 'Isi untung berupa angka, misal 20 atau 7,5 (persen dari modal).', 'PersenUntung');
        }

        $persen = BigDecimal::of($teks);

        if ($persen->isGreaterThan(self::PERSEN_MAKSIMAL)) {
            throw new PelanggaranAturanBisnis('UntungTidakValid', 'Untung paling banyak '.self::PERSEN_MAKSIMAL.' persen dari modal.', 'PersenUntung');
        }

        return $persen;
    }

    /**
     * @throws PelanggaranAturanBisnis PembulatanTidakDikenal
     */
    public static function PastikanPembulatanSah(int $pembulatan): void
    {
        if (! in_array($pembulatan, PenghitungPerubahanHarga::PEMBULATAN, true)) {
            throw new PelanggaranAturanBisnis('PembulatanTidakDikenal', 'Pembulatan tidak dikenal.', 'Pembulatan');
        }
    }

    /** Harga jual dari modal per satuan jual. */
    public static function Hitung(BigDecimal $modal, BigDecimal $persenUntung, int $pembulatan): Uang
    {
        $faktor = BigDecimal::one()->plus($persenUntung->dividedBy(100, 6, RoundingMode::HalfUp));
        $harga = Uang::Dari($modal->multipliedBy($faktor)->toScale(2, RoundingMode::HalfUp));

        return $pembulatan > 0 ? $harga->BulatkanKeKelipatan($pembulatan, RoundingMode::HalfUp) : $harga;
    }

    /** Perubahan harga dalam persen terhadap harga lama (dua desimal, bertanda). Harga lama nol tidak punya persentase. */
    public static function HitungPerubahanPersen(Uang $lama, Uang $baru): BigDecimal
    {
        if ($lama->BernilaiNol()) {
            return BigDecimal::zero()->toScale(2);
        }

        return BigDecimal::of($baru->Kurangi($lama)->KeString())->multipliedBy(100)->dividedBy(BigDecimal::of($lama->KeString()), 2, RoundingMode::HalfUp);
    }
}
