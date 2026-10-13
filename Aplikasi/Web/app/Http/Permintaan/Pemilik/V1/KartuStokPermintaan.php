<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pemilik\V1;

use Illuminate\Foundation\Http\FormRequest;

/** `GET /api/pemilik/v1/stok/kartu?produk=&gudang=&dari=&sampai=&halaman=` (OWN-06, riwayat kartu stok). */
final class KartuStokPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'produk' => ['required', 'string', 'size:26'],
            'gudang' => ['required', 'string', 'size:26'],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
            'halaman' => ['nullable', 'integer', 'min:1'],
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
