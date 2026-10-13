<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Operasional;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Nomor WhatsApp penerima alert kritis (P-11, PGL-20): satu nomor per baris atau dipisah koma. Kosong = alert WhatsApp
 * mati. Keabsahan nomor dinilai `AturNomorWaAlertOperasional`.
 */
final class AturNomorWaAlertPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['NomorWa' => ['nullable', 'string', 'max:500']];
    }

    /**
     * @return list<string>
     */
    public function AmbilNomor(): array
    {
        $teks = $this->string('NomorWa')->toString();

        return array_values(array_filter(
            array_map('trim', preg_split('/[\r\n,;]+/', $teks) ?: []),
            fn (string $nomor): bool => $nomor !== '',
        ));
    }
}
