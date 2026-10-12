<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Data;

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;

/**
 * Perubahan sebagian produk (F-03 `PerbaruiProdukSebagian`, dipakai impor). Null = tidak diubah.
 * `kosongkanHargaTermasukPajak = true` mengembalikan `HargaTermasukPajak` ke null (ikut outlet).
 */
final readonly class DataPerubahanProduk
{
    public function __construct(
        public ?string $nama = null,
        public ?string $namaStruk = null,
        public ?JenisProduk $jenis = null,
        public ?int $idKategori = null,
        public ?string $merek = null,
        public ?int $idKelompokPajak = null,
        public ?bool $hargaTermasukPajak = null,
        public bool $kosongkanHargaTermasukPajak = false,
        public ?bool $tampilDiPos = null,
        public ?bool $tampilOnline = null,
        public ?bool $bolehMinus = null,
        public ?PelacakanProduk $pelacakan = null,
        public ?bool $favorit = null,
    ) {}
}
