<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Tagihan;

use Illuminate\Foundation\Http\FormRequest;

/** Alasan tertulis untuk hapus-buku tagihan dan penolakan refund (P-08, D-112). */
final class AlasanTagihanPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['Alasan' => ['required', 'string', 'min:10', 'max:500']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Alasan' => 'alasan'];
    }

    public function AmbilAlasan(): string
    {
        return $this->string('Alasan')->trim()->toString();
    }
}
