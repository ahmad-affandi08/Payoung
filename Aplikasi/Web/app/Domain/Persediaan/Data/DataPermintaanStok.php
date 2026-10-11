<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Data;

use Carbon\CarbonImmutable;

/**
 * Masukan `AjukanPermintaanStok` (F-05b, D-105): lokasi *tujuan* = yang meminta dan akan menerima, lokasi *asal* = yang
 * diminta mengirim. `uuid` klien untuk idempotensi.
 */
final readonly class DataPermintaanStok
{
    /**
     * @param  list<DataBarisPermintaanStok>  $baris
     */
    public function __construct(
        public ?string $uuid,
        public int $idGudangAsal,
        public int $idGudangTujuan,
        public CarbonImmutable $tanggal,
        public ?string $catatan,
        public array $baris,
    ) {}
}
