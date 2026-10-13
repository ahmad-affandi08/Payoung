<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Persediaan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi tambah/ubah rak gudang (F-05c INV-07): lokasi stok (hanya saat membuat), kode, nama, urutan tampil.
 */
final class SimpanRakGudangPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'UuidGudang' => [$this->isMethod('POST') ? 'required' : 'nullable', 'ulid'],
            'Kode' => ['required', 'string', 'max:30'],
            'Nama' => ['required', 'string', 'max:100'],
            'Urutan' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['UuidGudang' => 'lokasi stok', 'Kode' => 'kode rak', 'Nama' => 'nama rak', 'Urutan' => 'urutan'];
    }
}
