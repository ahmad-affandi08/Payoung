<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Persediaan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi mulai stok opname (F-05b): lokasi stok, kategori atau rak opsional (keduanya kosong = seluruh produk; tidak boleh keduanya), hitung buta, catatan,
 * dan `Uuid` klien opsional untuk idempotensi.
 */
final class MulaiStokOpnamePermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Uuid' => ['nullable', 'ulid'],
            'UuidGudang' => ['required', 'ulid'],
            'UuidKategori' => ['nullable', 'ulid'],
            'UuidRak' => ['nullable', 'ulid'],
            'HitungButa' => ['required', 'boolean'],
            'Catatan' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['UuidGudang' => 'lokasi stok', 'UuidKategori' => 'kategori', 'UuidRak' => 'rak', 'HitungButa' => 'hitung buta', 'Catatan' => 'catatan'];
    }
}
