<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pos\V1;

use App\Domain\Katalog\Enum\KanalKetersediaan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * F-17 BR-17.2 `POST /api/pos/v1/produk/{uuid}/habis`: `{UuidPengguna, Habis, Kanal?}`; tanpa `Kanal` berarti semua kanal (kontrak lama). Idempoten menurut keadaan akhir.
 */
final class UbahKetersediaanProdukPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'UuidPengguna' => ['required', 'ulid'],
            'Habis' => ['required', 'boolean'],
            'Kanal' => ['nullable', new Enum(KanalKetersediaan::class)],
        ];
    }

    public function AmbilKanal(): KanalKetersediaan
    {
        $nilai = $this->validated('Kanal');

        return is_string($nilai) ? (KanalKetersediaan::tryFrom($nilai) ?? KanalKetersediaan::Semua) : KanalKetersediaan::Semua;
    }
}
