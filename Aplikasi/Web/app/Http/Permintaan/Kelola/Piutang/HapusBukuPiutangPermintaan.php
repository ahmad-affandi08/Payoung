<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Piutang;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/** Isian hapus-buku piutang (E8): tanggal pembukuan dan alasan (5–255 karakter). */
final class HapusBukuPiutangPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'Alasan' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Tanggal' => 'tanggal', 'Alasan' => 'alasan'];
    }

    public function AmbilTanggal(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('Tanggal'));
    }

    public function AmbilAlasan(): string
    {
        return (string) $this->validated('Alasan');
    }
}
