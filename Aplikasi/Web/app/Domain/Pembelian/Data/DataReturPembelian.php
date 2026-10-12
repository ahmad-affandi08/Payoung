<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Data;

use Carbon\CarbonImmutable;

/**
 * Masukan `SimpanReturPembelian` (F-04 fase 1). `uuidAkunRefund` = akun kas/bank penerima pengembalian uang dari pemasok;
 * wajib bila nilai retur melebihi sisa hutang faktur (faktur sudah dibayar / belanja stok tunai).
 */
final readonly class DataReturPembelian
{
    /**
     * @param  list<DataBarisReturPembelian>  $baris
     */
    public function __construct(
        public string $uuidPenerimaan,
        public CarbonImmutable $tanggal,
        public string $alasan,
        public array $baris,
        public int $idPengguna,
        public ?string $uuidAkunRefund = null,
    ) {}
}
