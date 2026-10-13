<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pemilik\V1;

use Illuminate\Foundation\Http\FormRequest;

/** `GET /api/pemilik/v1/laporan/laba-rugi?dari=&sampai=&outlet=` (OWN-05, laba rugi sederhana). */
final class LaporanLabaRugiPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'dari' => ['required', 'date_format:Y-m-d'],
            'sampai' => ['required', 'date_format:Y-m-d', 'after_or_equal:dari'],
            'outlet' => ['nullable', 'string', 'max:26'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['sampai.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.'];
    }
}
