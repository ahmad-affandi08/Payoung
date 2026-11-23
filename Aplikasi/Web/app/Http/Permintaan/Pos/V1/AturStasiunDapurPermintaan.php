<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pos\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * D-310 `POST /api/pos/v1/dapur/rute/kategori/{uuid}` dan `.../produk/{uuid}`: `{UuidPengguna, UuidStasiun|null}`.
 * `UuidStasiun` kosong = ikut aturan induk (kategori induk atau stasiun bawaan; produk: ikut kategori).
 */
final class AturStasiunDapurPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'UuidPengguna' => ['required', 'ulid'],
            'UuidStasiun' => ['nullable', 'ulid'],
        ];
    }

    public function AmbilUuidStasiun(): ?string
    {
        $nilai = $this->validated('UuidStasiun');

        return is_string($nilai) && $nilai !== '' ? strtoupper($nilai) : null;
    }
}
