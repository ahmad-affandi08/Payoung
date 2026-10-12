<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Tagihan;

use Illuminate\Foundation\Http\FormRequest;

/** Status PKP Payoung untuk penagihan (P-08, D-114). */
final class UbahStatusPkpPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Pkp' => ['required', 'boolean'],
            'Alasan' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Pkp' => 'status PKP', 'Alasan' => 'alasan perubahan'];
    }

    public function AmbilPkp(): bool
    {
        return $this->boolean('Pkp');
    }

    public function AmbilAlasan(): string
    {
        return $this->string('Alasan')->trim()->toString();
    }
}
