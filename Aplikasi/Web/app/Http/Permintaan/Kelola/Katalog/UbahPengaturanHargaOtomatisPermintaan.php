<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Katalog;

use App\Domain\Katalog\Harga\Enum\ArahHargaOtomatis;
use App\Domain\Katalog\Harga\Layanan\PenghitungPerubahanHarga;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /kelola/produk/harga-otomatis/pengaturan` (D-266): `{Aktif, BatasPerubahanPersen, PersenUntung, Pembulatan, Arah}`. Izin dan fitur paket dijaga rute.
 */
final class UbahPengaturanHargaOtomatisPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Aktif' => ['required', 'boolean'],
            'BatasPerubahanPersen' => ['required', 'string', 'max:8'],
            'PersenUntung' => ['required', 'string', 'max:10'],
            'Pembulatan' => ['required', 'integer', Rule::in(PenghitungPerubahanHarga::PEMBULATAN)],
            'Arah' => ['required', Rule::enum(ArahHargaOtomatis::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['BatasPerubahanPersen' => 'batas perubahan', 'PersenUntung' => 'untung', 'Pembulatan' => 'pembulatan'];
    }
}
