<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Persediaan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi menempatkan/melepas produk dari rak (F-05c INV-07): daftar Uuid produk dan/atau satu kategori (semua produk
 * berstok kategori itu beserta sub-kategorinya; hanya untuk menempatkan).
 */
final class ProdukRakPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'UuidProduk' => ['nullable', 'array', 'max:500'],
            'UuidProduk.*' => ['ulid'],
            'UuidKategori' => ['nullable', 'ulid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['UuidProduk' => 'produk', 'UuidKategori' => 'kategori'];
    }

    /**
     * @return list<string>
     */
    public function AmbilUuidProduk(): array
    {
        return array_values(array_map('strval', (array) $this->validated('UuidProduk', [])));
    }

    public function AmbilUuidKategori(): ?string
    {
        $nilai = $this->validated('UuidKategori');

        return is_string($nilai) && $nilai !== '' ? $nilai : null;
    }
}
