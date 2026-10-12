<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Data;

use App\Domain\Bersama\Nilai\Uang;

/**
 * Satu baris masukan `CatatJurnalUmum` (FIN-05). Akun dirujuk lewat Uuid publik; tepat satu sisi (debit atau kredit)
 * yang bernilai > 0. `idOutlet` = dimensi outlet baris (null = tingkat usaha).
 */
final readonly class DataBarisJurnalUmum
{
    public function __construct(
        public string $uuidAkun,
        public ?int $idOutlet,
        public Uang $debit,
        public Uang $kredit,
        public ?string $memo = null,
    ) {}
}
