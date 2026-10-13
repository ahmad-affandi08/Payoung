<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Katalog;

use App\Domain\Katalog\Enum\KanalKetersediaan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * F-17 BR-17.2 `POST /kelola/produk/{produk}/habis` (back-office): `{UuidOutlet, Habis, Kanal?}` (Kanal: Semua [bawaan], Kasir, TokoOnline, SelfOrder). Izin dijaga rute.
 */
final class UbahKetersediaanProdukPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'UuidOutlet' => ['required', 'ulid'],
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
