<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Kasir;

use App\Domain\Pajak\Data\BatasBiayaLayanan;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * D-263: pajak & biaya layanan satu outlet dari Atur kasir. Persen biaya layanan berupa string desimal bertitik
 * (misal "7.5"), 0 sampai batas maksimum, dibandingkan dengan BigDecimal (tidak pernah float).
 */
final class UbahPajakOutletKasirPermintaan extends FormRequest
{
    public const POLA_PERSEN = '/^\d{1,2}(\.\d{1,2})?$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Outlet' => ['required', 'string', 'ulid'],
            'PungutPbjt' => ['required', 'boolean'],
            'BiayaLayananAktif' => ['required', 'boolean'],
            'PersenBiayaLayanan' => [
                'nullable',
                'required_if_accepted:BiayaLayananAktif',
                'string',
                'regex:'.self::POLA_PERSEN,
                function (string $atribut, mixed $nilai, Closure $gagal): void {
                    if (is_string($nilai) && preg_match(self::POLA_PERSEN, $nilai) === 1
                        && BigDecimal::of($nilai)->isGreaterThan(BatasBiayaLayanan::PERSEN_MAKSIMAL)) {
                        $gagal('Biaya layanan 0 sampai '.BatasBiayaLayanan::PERSEN_MAKSIMAL.' persen.');
                    }
                },
            ],
            'HargaTermasukPajak' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'PersenBiayaLayanan.required_if_accepted' => 'Isi persen biaya layanan.',
            'PersenBiayaLayanan.regex' => 'Biaya layanan 0 sampai '.BatasBiayaLayanan::PERSEN_MAKSIMAL.' persen.',
        ];
    }

    public function AmbilPersen(): string
    {
        return $this->filled('PersenBiayaLayanan') ? $this->string('PersenBiayaLayanan')->toString() : '0';
    }
}
