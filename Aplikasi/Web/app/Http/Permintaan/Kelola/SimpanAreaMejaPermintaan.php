<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Data\DataAreaMeja;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form area meja F-10a: `{ Nama, Urutan?, MinimumCharge? }` (D-144).
 */
final class SimpanAreaMejaPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Nama' => ['required', 'string', 'max:60'],
            'Urutan' => ['nullable', 'integer', 'min:0', 'max:999'],
            'MinimumCharge' => ['nullable', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['MinimumCharge.regex' => 'Minimum charge berupa angka rupiah tanpa titik ribuan, misal 150000.'];
    }

    public function AmbilData(): DataAreaMeja
    {
        return new DataAreaMeja(
            $this->string('Nama')->toString(),
            $this->integer('Urutan'),
            $this->filled('MinimumCharge') ? Uang::Dari($this->string('MinimumCharge')->toString()) : null,
        );
    }
}
