<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Rilis\Data;

use App\Domain\Tenant\Enum\AplikasiRilis;
use App\Domain\Tenant\Enum\KanalRilis;

/** Isian draf rilis aplikasi (P-10 langkah 1). */
final readonly class DataRilis
{
    public function __construct(
        public AplikasiRilis $aplikasi,
        public string $platform,
        public KanalRilis $kanal,
        public string $versi,
        public ?int $build,
        public ?string $urlUnduh,
        public ?string $catatanRilis,
        public ?string $sha256Unduhan = null,
    ) {}
}
