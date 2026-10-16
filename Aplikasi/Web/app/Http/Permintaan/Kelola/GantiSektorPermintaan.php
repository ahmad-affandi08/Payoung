<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ganti jenis usaha satu outlet (D-165): outlet, jenis usaha baru, dan apakah sisa template lama yang belum dipakai
 * ikut dibersihkan. Dipakai untuk pratinjau (GET, query) dan penerapan (POST).
 */
final class GantiSektorPermintaan extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'UuidOutlet' => ['required', 'string', 'size:26'],
            'KodeTemplate' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9-]+$/'],
            'Bersihkan' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'UuidOutlet.required' => 'Pilih outlet yang jenis usahanya diganti.',
            'KodeTemplate.required' => 'Pilih jenis usaha yang baru.',
        ];
    }
}
