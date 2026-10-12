<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pos\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/pos/v1/shift/kasir-terbuka?UuidPengguna=`: apakah kasir ini masih punya shift terbuka di perangkat lain pada
 * outlet perangkat pemanggil (BR-06.1).
 */
final class KasirTerbukaPermintaan extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'UuidPengguna' => ['required', 'string', 'size:26'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'UuidPengguna.*' => 'Pilih kasir yang akan membuka shift.',
        ];
    }
}
