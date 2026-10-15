<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Satu pesan obrolan langsung dari pelapor (PLT-12, D-163). Teks polos saja; lampiran memakai tiket biasa.
 */
final class KirimPesanObrolanPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Isi' => ['required', 'string', 'min:1', 'max:'.(int) config('dukungan.Obrolan.MaksPanjangPesan')],
            'HalamanAsal' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function AmbilIsi(): string
    {
        return str_replace("\r\n", "\n", $this->string('Isi')->trim()->toString());
    }

    /**
     * @return array<string, mixed>
     */
    public function AmbilKonteks(): array
    {
        $halaman = $this->string('HalamanAsal')->trim()->toString();

        return $halaman === '' ? [] : ['HalamanAsal' => $halaman];
    }
}
