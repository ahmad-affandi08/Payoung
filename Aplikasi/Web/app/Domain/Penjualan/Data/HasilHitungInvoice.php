<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Data;

use App\Domain\Bersama\Nilai\Uang;

/**
 * Hasil hitung invoice (F-21): angka dokumen (sama bentuknya dengan dokumen grosir) beserta rincian per baris yang
 * dibutuhkan jurnal: pajak baris, pendapatan baris (bruto dikurangi pajak inklusif), dan dasar pengenaan pajak baris
 * (untuk dasar PPh). Σ baris selalu sama dengan angka dokumen karena alokasinya memakai sisa terbesar di mesin kalkulasi.
 */
final readonly class HasilHitungInvoice
{
    /**
     * @param  list<array{Pajak: Uang, Pendapatan: Uang, Dpp: Uang}>  $baris
     */
    public function __construct(
        public HasilHitungGrosir $dokumen,
        public array $baris,
    ) {}
}
