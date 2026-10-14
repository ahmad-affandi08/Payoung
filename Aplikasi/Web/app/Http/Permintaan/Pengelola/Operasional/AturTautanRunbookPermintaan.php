<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Operasional;

use Illuminate\Foundation\Http\FormRequest;

/** Alamat dasar runbook alert (PGL-20 lanjutan). Keabsahan alamat dinilai `AturTautanRunbookOperasional`. */
final class AturTautanRunbookPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['TautanRunbook' => ['nullable', 'string', 'max:255']];
    }

    public function AmbilTautan(): ?string
    {
        $teks = $this->validated('TautanRunbook');

        return is_string($teks) ? $teks : null;
    }
}
