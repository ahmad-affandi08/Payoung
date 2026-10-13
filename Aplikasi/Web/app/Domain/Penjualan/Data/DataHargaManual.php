<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Data;

use App\Domain\Bersama\Nilai\Uang;

/**
 * Jejak perubahan harga satu baris oleh kasir (price engine lapis 1, izin `pos.harga.timpa`): harga sebelum diubah dan
 * alasannya. Harga yang dipakai transaksi tetap `HargaSatuan` baris itu.
 */
final readonly class DataHargaManual
{
    public function __construct(
        public Uang $hargaAsli,
        public string $alasan,
    ) {}
}
