<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Enum;

/**
 * Lokasi tempat produk jenis Resep dibuat (BR-05.4). Nilainya sama dengan `JenisGudang::Dapur`/`Bar` di Organisasi;
 * pemetaannya dilakukan di sisi Penjualan/Persediaan supaya Katalog tidak bergantung pada domain Organisasi.
 */
enum LokasiProduksi: string
{
    case Dapur = 'Dapur';
    case Bar = 'Bar';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Dapur => 'Dapur',
            self::Bar => 'Bar',
        };
    }
}
