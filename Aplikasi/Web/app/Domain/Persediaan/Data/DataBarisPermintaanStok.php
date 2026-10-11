<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Data;

use App\Domain\Bersama\Nilai\Kuantitas;

/** Satu baris masukan `AjukanPermintaanStok` (D-105): produk dan jumlah yang diminta, satuan dasar. */
final readonly class DataBarisPermintaanStok
{
    public function __construct(
        public int $idProduk,
        public Kuantitas $jumlah,
    ) {}
}
