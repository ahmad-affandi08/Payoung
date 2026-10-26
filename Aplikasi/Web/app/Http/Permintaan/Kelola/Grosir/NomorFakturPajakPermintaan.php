<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Grosir;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Nomor Faktur Pajak dari e-Faktur/Coretax (BR-12.4). Boleh diisi setelah faktur dicatat, karena nomornya memang
 * baru didapat setelah faktur diterbitkan; boleh dikosongkan kembali bila salah ketik.
 */
final class NomorFakturPajakPermintaan extends FormRequest
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

    public function AmbilNomor(): ?string
    {
        return AturanGrosir::Teks($this->validated('NomorFakturPajak'));
    }
}
