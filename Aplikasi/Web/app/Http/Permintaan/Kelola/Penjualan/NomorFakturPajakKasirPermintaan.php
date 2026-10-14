<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Penjualan;

use Illuminate\Foundation\Http\FormRequest;

/** TAX-07: Nomor Faktur Pajak dari Coretax; boleh dikosongkan kembali bila salah ketik. */
final class NomorFakturPajakKasirPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['NomorFakturPajak' => ['nullable', 'string', 'max:30']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['NomorFakturPajak' => 'nomor Faktur Pajak'];
    }
}
