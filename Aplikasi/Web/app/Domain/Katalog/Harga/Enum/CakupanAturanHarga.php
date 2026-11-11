<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Enum;

/**
 * Cakupan aturan harga otomatis (D-266). Yang paling khusus menang: Produk, lalu Kategori, lalu Semua.
 */
enum CakupanAturanHarga: string
{
    case Semua = 'Semua';
    case Kategori = 'Kategori';
    case Produk = 'Produk';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Semua => 'Semua produk',
            self::Kategori => 'Satu kategori',
            self::Produk => 'Satu produk',
        };
    }
}
