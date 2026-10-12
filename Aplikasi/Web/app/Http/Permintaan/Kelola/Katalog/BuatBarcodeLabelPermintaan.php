<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Katalog;

use App\Domain\Katalog\Aksi\BuatBarcodeInternalBanyak;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PRD-10 `POST /kelola/produk/label/barcode-internal`: `{Satuan: [ulid satuan produk]}`. Izin `produk.kelola` dijaga rute.
 */
final class BuatBarcodeLabelPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Satuan' => ['required', 'array', 'min:1', 'max:'.BuatBarcodeInternalBanyak::MAKS],
            'Satuan.*' => ['required', 'string', 'ulid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Satuan' => 'satuan produk'];
    }
}
