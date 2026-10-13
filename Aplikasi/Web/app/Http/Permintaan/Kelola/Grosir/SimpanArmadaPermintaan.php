<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Grosir;

use App\Domain\Penjualan\Data\DataArmada;
use Illuminate\Foundation\Http\FormRequest;

/** Isian armada pengiriman (F-12 §9.7). */
final class SimpanArmadaPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Nama' => ['required', 'string', 'max:100'],
            'NomorKendaraan' => ['required', 'string', 'max:30'],
            'NamaPengemudi' => ['nullable', 'string', 'max:100'],
            'Keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Nama' => 'nama armada', 'NomorKendaraan' => 'nomor kendaraan', 'NamaPengemudi' => 'nama pengemudi', 'Keterangan' => 'keterangan'];
    }

    public function AmbilData(): DataArmada
    {
        return new DataArmada(
            (string) $this->validated('Nama'),
            (string) $this->validated('NomorKendaraan'),
            AturanGrosir::Teks($this->validated('NamaPengemudi')),
            AturanGrosir::Teks($this->validated('Keterangan')),
        );
    }
}
