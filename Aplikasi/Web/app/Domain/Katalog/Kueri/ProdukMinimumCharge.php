<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Kueri;

use App\Domain\Katalog\Model\Produk;

/**
 * Produk "Selisih minimum charge" tenant aktif (D-144), disiapkan `SiapkanProdukMinimumCharge`. Dipakai API meja POS
 * (kasir menambahkan selisih sebagai baris) dan pemeriksaan penjualan di server.
 */
final class ProdukMinimumCharge
{
    public const SKU = 'MINIMUM-CHARGE';

    /** @return array{Id: int, Uuid: string}|null null bila belum disiapkan atau sudah dihapus. */
    public function Ambil(): ?array
    {
        $produk = Produk::query()->where('Sku', self::SKU)->first();

        return $produk === null ? null : ['Id' => $produk->Id, 'Uuid' => $produk->Uuid];
    }
}
