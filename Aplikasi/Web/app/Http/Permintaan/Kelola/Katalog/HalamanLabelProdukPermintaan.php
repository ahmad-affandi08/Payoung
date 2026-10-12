<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Katalog;

use App\Domain\Katalog\Kueri\BarisLabelProduk;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PRD-10 `GET /kelola/produk/label`: `?Produk[]=ulid` (semua satuan produk itu) dan/atau `?Item[]=ulidSatuanProduk:jumlah`
 * (satuan tertentu dengan jumlah stiker, mis. dari penerimaan barang).
 */
final class HalamanLabelProdukPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Produk' => ['nullable', 'array', 'max:'.BarisLabelProduk::MAKS_PRODUK],
            'Produk.*' => ['string', 'ulid'],
            'Item' => ['nullable', 'array', 'max:'.BarisLabelProduk::MAKS_BARIS],
            'Item.*' => ['string', 'regex:/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}(:[0-9]{1,6})?$/'],
        ];
    }

    /**
     * @return list<string>
     */
    public function AmbilUuidProduk(): array
    {
        return array_values(array_map(strval(...), (array) $this->validated('Produk', [])));
    }

    /**
     * @return list<array{Uuid: string, Jumlah: int}>
     */
    public function AmbilItem(): array
    {
        return array_values(array_map(function (mixed $nilai): array {
            [$uuid, $jumlah] = array_pad(explode(':', (string) $nilai, 2), 2, '1');

            return ['Uuid' => $uuid, 'Jumlah' => max(1, (int) $jumlah)];
        }, (array) $this->validated('Item', [])));
    }
}
