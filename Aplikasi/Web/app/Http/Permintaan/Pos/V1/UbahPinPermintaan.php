<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pos\V1;

use Illuminate\Foundation\Http\FormRequest;

final class UbahPinPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'UuidPengguna' => ['required', 'string', 'size:26'],
            'PinLama' => ['required', 'string', 'regex:/^\d{6}$/'],
            'PinBaru' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['PinLama.regex' => 'PIN harus 6 angka.', 'PinBaru.regex' => 'PIN harus 6 angka.'];
    }
}
