<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Katalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * D-310 `POST /kelola/produk/{uuid}/stasiun-dapur`: `{UuidStasiun|null}` (kosong = ikut kategori).
 */
final class AturStasiunDapurProdukPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['UuidStasiun' => ['nullable', 'ulid']];
    }

    public function AmbilUuidStasiun(): ?string
    {
        $nilai = $this->validated('UuidStasiun');

        return is_string($nilai) && $nilai !== '' ? strtoupper($nilai) : null;
    }
}
