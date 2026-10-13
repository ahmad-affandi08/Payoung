<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pemilik\V1;

use Illuminate\Foundation\Http\FormRequest;

/** `POST /api/pemilik/v1/persetujuan-dokumen/{jenis}/{uuid}/tolak` (OWN-04): alasan 5–255 karakter, kembali ke pembuat. */
final class TolakDokumenPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['Alasan' => ['required', 'string', 'min:5', 'max:255']];
    }
}
