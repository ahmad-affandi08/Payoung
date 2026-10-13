<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Data;

use App\Domain\Bersama\Nilai\Uang;

/**
 * Anggaran satu akun untuk dua belas bulan (Januari = indeks 0). Nol = tidak dianggarkan pada bulan itu.
 */
final readonly class DataBarisAnggaran
{
    /**
     * @param  list<Uang>  $perBulan
     */
    public function __construct(
        public string $uuidAkun,
        public array $perBulan,
    ) {}
}
