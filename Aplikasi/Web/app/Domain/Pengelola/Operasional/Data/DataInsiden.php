<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Data;

use App\Domain\Tenant\Enum\TingkatInsiden;
use Carbon\CarbonImmutable;

/** Isian pembukaan insiden layanan (P-11 PGL-21). Waktu dalam UTC; `komponen` berisi nilai `KomponenLayanan`. */
final readonly class DataInsiden
{
    /**
     * @param  list<string>  $komponen
     */
    public function __construct(
        public string $judul,
        public TingkatInsiden $tingkat,
        public string $dampak,
        public array $komponen,
        public ?int $persenTenantTerdampak,
        public bool $diumumkan,
        public string $kronologiAwal,
        public ?CarbonImmutable $mulaiPada = null,
    ) {}
}
