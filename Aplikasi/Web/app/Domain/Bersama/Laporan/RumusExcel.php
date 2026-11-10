<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Laporan;

/**
 * Sel Excel berumus (hanya untuk penulis Excel, D-255). `rumus` ditulis tanpa tanda sama dengan, memakai nama fungsi
 * bahasa Inggris dan pemisah koma (format berkas OOXML), dan hanya dirakit dari kode kita, tidak pernah dari isi data.
 * `nilai` adalah hasil yang dihitung server (BigDecimal, tanpa float) dan ikut ditulis ke berkas sebagai nilai tersimpan,
 * supaya pratinjau yang tidak menghitung ulang (HP, WhatsApp) tetap menampilkan angka yang benar.
 */
final readonly class RumusExcel
{
    public function __construct(
        public string $rumus,
        public string|int|null $nilai,
    ) {}
}
