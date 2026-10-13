<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Dukungan;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Rentang tanggal bisnis alat "Bangun ulang ringkasan harian" (PGL-16 bagian 2); panjang rentang dijaga aksinya.
 */
final class BangunUlangRingkasanHarianPermintaan extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'Dari' => ['required', 'date_format:Y-m-d'],
            'Sampai' => ['required', 'date_format:Y-m-d', 'after_or_equal:Dari'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'Sampai.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
            'Dari.date_format' => 'Tanggal awal tidak sah.',
            'Sampai.date_format' => 'Tanggal akhir tidak sah.',
        ];
    }

    public function AmbilDari(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('Dari')->toString()) ?: throw new LogicException('Tanggal awal tidak sah.');
    }

    public function AmbilSampai(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('Sampai')->toString()) ?: throw new LogicException('Tanggal akhir tidak sah.');
    }
}
