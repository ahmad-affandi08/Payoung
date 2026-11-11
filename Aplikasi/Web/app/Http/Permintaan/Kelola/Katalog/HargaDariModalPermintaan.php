<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Katalog;

use App\Domain\Katalog\Harga\Kueri\PratinjauHargaDariModal;
use App\Domain\Katalog\Harga\Layanan\PenghitungPerubahanHarga;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /kelola/produk/harga-dari-modal/pratinjau` dan `POST /kelola/produk/harga-dari-modal` (D-266):
 * `{Uuid[], PersenUntung, Pembulatan}`. Izin dan fitur paket dijaga rute.
 */
final class HargaDariModalPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Uuid' => ['required', 'array', 'min:1', 'max:'.PratinjauHargaDariModal::MAKS],
            'Uuid.*' => ['required', 'ulid'],
            'PersenUntung' => ['required', 'string', 'max:10'],
            'Pembulatan' => ['required', 'integer', Rule::in(PenghitungPerubahanHarga::PEMBULATAN)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Uuid' => 'produk terpilih', 'PersenUntung' => 'untung', 'Pembulatan' => 'pembulatan'];
    }

    /**
     * @return list<string>
     */
    public function AmbilUuid(): array
    {
        /** @var list<string> $uuid */
        $uuid = array_values((array) $this->validated('Uuid'));

        return $uuid;
    }
}
