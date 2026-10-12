<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Tagihan;

use Illuminate\Foundation\Http\FormRequest;

/** Batas refund tanpa persetujuan kedua, Rupiah penuh (P-08, BR-P08.2, D-113). */
final class UbahBatasRefundPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Batas' => ['required', 'string', 'regex:/^\d{1,10}$/'],
            'Alasan' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Batas' => 'batas refund', 'Alasan' => 'alasan perubahan'];
    }

    public function AmbilAlasan(): string
    {
        return $this->string('Alasan')->trim()->toString();
    }
}
