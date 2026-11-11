<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Katalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /kelola/produk/harga-otomatis/pengaturan` (D-266): `{Aktif, BatasPerubahanPersen}`. Izin dan fitur paket dijaga rute.
 */
final class UbahPengaturanHargaOtomatisPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Aktif' => ['required', 'boolean'],
            'BatasPerubahanPersen' => ['required', 'string', 'max:8'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['BatasPerubahanPersen' => 'batas aman'];
    }
}
