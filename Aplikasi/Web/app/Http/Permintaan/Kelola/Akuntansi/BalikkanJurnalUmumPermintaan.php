<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Akuntansi;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Isian dokumen pembalik jurnal umum (FIN-05): tanggal pembalik dan alasan koreksi.
 */
final class BalikkanJurnalUmumPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'Alasan' => ['required', 'string', 'min:5', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Tanggal' => 'tanggal pembalik', 'Alasan' => 'alasan'];
    }

    public function AmbilTanggal(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->validated('Tanggal')) ?: CarbonImmutable::today();
    }

    public function AmbilAlasan(): string
    {
        return (string) $this->validated('Alasan');
    }
}
