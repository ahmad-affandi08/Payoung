<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola;

use App\Domain\Dukungan\Aksi\BeriAksesDukungan;
use App\Domain\Dukungan\Enum\CakupanAksesDukungan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Isian Owner saat memberi izin akses tim dukungan (PGL-16): durasi 1/24/72 jam, cakupan, buka data pribadi pelanggan,
 * tiket yang dibantu (opsional), dan catatan.
 */
final class BeriAksesDukunganPermintaan extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'DurasiJam' => ['required', 'integer', Rule::in(BeriAksesDukungan::DURASI_JAM)],
            'Cakupan' => ['required', Rule::enum(CakupanAksesDukungan::class)],
            'DataPribadiTerbuka' => ['required', 'boolean'],
            'UuidTiket' => ['nullable', 'string', 'size:26'],
            'Catatan' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['DurasiJam.in' => 'Pilih durasi 1 jam, 24 jam, atau 72 jam.'];
    }

    public function AmbilDurasiJam(): int
    {
        return $this->integer('DurasiJam');
    }

    public function AmbilCakupan(): CakupanAksesDukungan
    {
        return CakupanAksesDukungan::from($this->string('Cakupan')->toString());
    }

    public function AmbilDataPribadiTerbuka(): bool
    {
        return $this->boolean('DataPribadiTerbuka');
    }

    public function AmbilUuidTiket(): ?string
    {
        $uuid = trim($this->string('UuidTiket')->toString());

        return $uuid === '' ? null : $uuid;
    }

    public function AmbilCatatan(): ?string
    {
        $catatan = trim($this->string('Catatan')->toString());

        return $catatan === '' ? null : $catatan;
    }
}
