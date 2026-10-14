<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Penjualan;

use Illuminate\Foundation\Http\FormRequest;

/** TAX-07: alasan pembatalan Faktur Pajak per transaksi kasir. */
final class BatalkanFakturPajakPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['Alasan' => ['required', 'string', 'min:5', 'max:255']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Alasan' => 'alasan'];
    }
}
