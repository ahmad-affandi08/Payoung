<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Pembelian;

use Illuminate\Foundation\Http\FormRequest;

/** Pemasok untuk PO dari permintaan pembelian; kosong = pemasok usulan permintaan. */
final class JadikanPesananPembelianPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['UuidPemasok' => ['nullable', 'string', 'ulid']];
    }

    public function AmbilUuidPemasok(): ?string
    {
        return AturanPembelian::Teks($this->validated('UuidPemasok'));
    }
}
