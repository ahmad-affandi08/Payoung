<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Aplikasi;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Balasan pelapor dari aplikasi pada tiketnya (P-09). Tanpa lampiran.
 */
class BalasTiketAplikasiPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['Isi' => ['required', 'string', 'min:2', 'max:10000']];
    }

    public function AmbilIsi(): string
    {
        return str_replace("\r\n", "\n", $this->string('Isi')->trim()->toString());
    }
}
