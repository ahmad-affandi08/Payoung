<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Data;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\BentukMeja;

/**
 * Isian meja per outlet (F-10a). `uuidArea` kosong = tanpa area.
 */
final readonly class DataMeja
{
    public function __construct(
        public string $nama,
        public ?string $uuidArea,
        public int $kapasitas,
        public BentukMeja $bentuk,
        public int $urutan,
        public ?Uang $minimumCharge = null,
    ) {}
}
