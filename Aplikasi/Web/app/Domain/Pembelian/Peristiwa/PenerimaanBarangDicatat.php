<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Peristiwa;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Penerimaan barang (termasuk belanja stok) sudah tercatat dan harga belinya jadi harga beli terakhir produk (D-266).
 * Dikirim setelah commit. Penangannya di domain Katalog memeriksa aturan harga otomatis untuk produk-produk ini.
 */
final class PenerimaanBarangDicatat implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $idProduk
     */
    public function __construct(
        public readonly int $idTenant,
        public readonly int $idPenerimaanBarang,
        public readonly array $idProduk,
    ) {}
}
