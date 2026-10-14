<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Penjualan;

use Illuminate\Foundation\Http\FormRequest;

/** TAX-07: pembeli (pelanggan ber-NPWP/NIK) dan catatan Faktur Pajak per transaksi kasir. */
final class TerbitkanFakturPajakPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'UuidPelanggan' => ['required', 'string', 'ulid'],
            'Catatan' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['UuidPelanggan' => 'pelanggan', 'Catatan' => 'catatan'];
    }
}
