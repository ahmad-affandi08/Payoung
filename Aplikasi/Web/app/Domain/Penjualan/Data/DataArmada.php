<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Data;

/** Masukan master armada pengiriman (F-12 §9.7). */
final readonly class DataArmada
{
    public function __construct(
        public string $nama,
        public string $nomorKendaraan,
        public ?string $namaPengemudi = null,
        public ?string $keterangan = null,
    ) {}
}
