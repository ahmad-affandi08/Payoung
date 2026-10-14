<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Data;

use App\Domain\Bersama\Nilai\Uang;

/**
 * Isian area meja per outlet (F-10a).
 */
final readonly class DataAreaMeja
{
    public function __construct(
        public string $nama,
        public int $urutan,
        public ?Uang $minimumCharge = null,
    ) {}
}
