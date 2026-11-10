<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Laporan;

/**
 * Satu baris blok ringkasan di kop laporan (mis. "Total Penjualan" = 20818500 Uang).
 */
final readonly class ItemRingkasan
{
    public function __construct(
        public string $label,
        public string|int|RumusExcel|null $nilai,
        public JenisKolom $jenis = JenisKolom::Uang,
    ) {}
}
