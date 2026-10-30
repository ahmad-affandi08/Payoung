<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Langganan;

use App\Domain\Tenant\Enum\SiklusTagihan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BuatTagihanLanggananPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'KodePaket' => ['required', 'string', 'max:30'],
            // D-249: lama langganan bebas dalam bulan (rentangnya diperiksa Aksi terhadap pengaturan konsol). `Siklus`
            // (Bulanan/Tahunan) tetap diterima supaya klien lama yang belum mengirim `JumlahBulan` tidak rusak.
            'JumlahBulan' => ['required_without:Siklus', 'nullable', 'integer', 'min:1', 'max:120'],
            'Siklus' => ['required_without:JumlahBulan', 'nullable', Rule::in([SiklusTagihan::Bulanan->value, SiklusTagihan::Tahunan->value])],
            'KodeKupon' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function AmbilJumlahBulan(): int
    {
        if ($this->filled('JumlahBulan')) {
            return $this->integer('JumlahBulan');
        }

        return SiklusTagihan::from($this->string('Siklus')->toString())->JumlahBulanBawaan() ?? 1;
    }

    public function AmbilKodeKupon(): ?string
    {
        return $this->filled('KodeKupon') ? $this->string('KodeKupon')->trim()->upper()->toString() : null;
    }
}
