<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Data\DataMeja;
use App\Domain\Organisasi\Enum\BentukMeja;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form meja F-10a: `{ Nama, Area?, Kapasitas, Bentuk, Urutan?, MinimumCharge? }`. `Area` = Uuid area meja outlet yang
 * sama; `MinimumCharge` (D-144) kosong = ikut minimum area.
 */
final class SimpanMejaPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Nama' => ['required', 'string', 'max:30'],
            'Area' => ['nullable', 'ulid'],
            'Kapasitas' => ['required', 'integer', 'min:1', 'max:99'],
            'Bentuk' => ['required', Rule::enum(BentukMeja::class)],
            'Urutan' => ['nullable', 'integer', 'min:0', 'max:999'],
            'MinimumCharge' => ['nullable', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'Kapasitas.min' => 'Kapasitas minimal 1 orang.',
            'Kapasitas.max' => 'Kapasitas maksimal 99 orang.',
            'MinimumCharge.regex' => 'Minimum charge berupa angka rupiah tanpa titik ribuan, misal 150000.',
        ];
    }

    public function AmbilData(): DataMeja
    {
        return new DataMeja(
            nama: $this->string('Nama')->toString(),
            uuidArea: $this->filled('Area') ? $this->string('Area')->toString() : null,
            kapasitas: $this->integer('Kapasitas'),
            bentuk: BentukMeja::from($this->string('Bentuk')->toString()),
            urutan: $this->integer('Urutan'),
            minimumCharge: $this->filled('MinimumCharge') ? Uang::Dari($this->string('MinimumCharge')->toString()) : null,
        );
    }
}
