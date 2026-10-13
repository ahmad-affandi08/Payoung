<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Data;

use Carbon\CarbonImmutable;

/**
 * Masukan retur grosir (F-12, §9.7, BR-12.7): surat jalan mana yang barangnya kembali, dan baris mana saja.
 * `uuidAkunRefund` = akun kas/bank sumber pengembalian uang, wajib hanya bila nilai retur melebihi sisa tagihan faktur.
 */
final readonly class DataReturGrosir
{
    /**
     * @param  list<DataBarisReturGrosir>  $baris
     */
    public function __construct(
        public string $uuidSuratJalan,
        public CarbonImmutable $tanggal,
        public string $alasan,
        public array $baris,
        public ?string $catatan = null,
        public ?string $uuidAkunRefund = null,
    ) {}
}
