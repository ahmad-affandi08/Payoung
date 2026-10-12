<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Dukungan;

use App\Domain\Dukungan\Enum\CakupanAksesDukungan;
use App\Domain\Dukungan\Enum\KategoriAksesDarurat;
use App\Domain\Pengelola\Dukungan\Aksi\BukaAksesDarurat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Isian akses darurat (PGL-16): kategori, alasan tertulis, durasi maksimal 4 jam, dan cakupan.
 */
final class BukaAksesDaruratPermintaan extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'Kategori' => ['required', Rule::enum(KategoriAksesDarurat::class)],
            'Alasan' => ['required', 'string', 'min:'.BukaAksesDarurat::MIN_PANJANG_ALASAN, 'max:500'],
            'DurasiJam' => ['required', 'integer', 'min:1', 'max:'.BukaAksesDarurat::MAKS_JAM],
            'Cakupan' => ['required', Rule::enum(CakupanAksesDukungan::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'Alasan.min' => 'Tulis alasan akses darurat dengan jelas, minimal '.BukaAksesDarurat::MIN_PANJANG_ALASAN.' karakter.',
            'DurasiJam.max' => 'Akses darurat paling lama '.BukaAksesDarurat::MAKS_JAM.' jam.',
        ];
    }

    public function AmbilKategori(): KategoriAksesDarurat
    {
        return KategoriAksesDarurat::from($this->string('Kategori')->toString());
    }

    public function AmbilCakupan(): CakupanAksesDukungan
    {
        return CakupanAksesDukungan::from($this->string('Cakupan')->toString());
    }

    public function AmbilAlasan(): string
    {
        return trim($this->string('Alasan')->toString());
    }

    public function AmbilDurasiJam(): int
    {
        return $this->integer('DurasiJam');
    }
}
