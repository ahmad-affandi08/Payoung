<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Data;

use App\Domain\Katalog\Harga\Enum\ArahHargaOtomatis;
use App\Domain\Katalog\Harga\Enum\CakupanAturanHarga;

/** Isian satu aturan harga otomatis dari back-office (D-266). `persenUntung` masih teks isian pengguna. */
final class DataAturanHargaOtomatis
{
    public function __construct(
        public readonly CakupanAturanHarga $cakupan,
        public readonly ?string $uuidKategori,
        public readonly ?string $uuidProduk,
        public readonly string $persenUntung,
        public readonly int $pembulatan,
        public readonly ArahHargaOtomatis $arah,
        public readonly bool $aktif,
    ) {}
}
