<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola;

use App\Domain\Organisasi\Layanan\JamOperasionalOutlet;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Jam operasional outlet (F-e): `{ Jam: { Senin: { Buka, Tutup } | null, ... } | null }`; `Jam` null menghapus pengaturan.
 * Aturan jam (format, buka ≠ tutup, minimal satu hari buka) diperiksa `JamOperasionalOutlet::Normalkan`.
 */
final class AturJamOperasionalOutletPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $aturan = ['Jam' => ['present', 'nullable', 'array']];

        foreach (JamOperasionalOutlet::HARI as $nama) {
            $aturan["Jam.{$nama}"] = ['nullable', 'array'];
            $aturan["Jam.{$nama}.Buka"] = ['nullable', 'string', 'max:5'];
            $aturan["Jam.{$nama}.Tutup"] = ['nullable', 'string', 'max:5'];
        }

        return $aturan;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function AmbilJam(): ?array
    {
        $jam = $this->validated('Jam');

        return is_array($jam) ? $jam : null;
    }
}
