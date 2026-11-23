<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola;

use App\Domain\Organisasi\Data\DataStasiunDapur;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form stasiun dapur F-10a: `{ Nama, Urutan?, Salinan? }` (D-310: `Salinan` = stasiun checker).
 */
final class SimpanStasiunDapurPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Nama' => ['required', 'string', 'max:60'],
            'Urutan' => ['nullable', 'integer', 'min:0', 'max:999'],
            'Salinan' => ['nullable', 'boolean'],
        ];
    }

    public function AmbilData(): DataStasiunDapur
    {
        return new DataStasiunDapur($this->string('Nama')->toString(), $this->integer('Urutan'), $this->has('Salinan') ? $this->boolean('Salinan') : null);
    }
}
