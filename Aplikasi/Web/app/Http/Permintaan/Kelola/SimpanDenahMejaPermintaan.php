<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola;

use App\Domain\Organisasi\Aksi\SimpanDenahMeja;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /kelola/outlet/{outlet}/denah-meja`: `{Meja: [{Uuid, PosisiX|null, PosisiY|null}]}`. Izin dijaga rute.
 */
final class SimpanDenahMejaPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Meja' => ['required', 'array', 'min:1', 'max:300'],
            'Meja.*.Uuid' => ['required', 'ulid'],
            'Meja.*.PosisiX' => ['present', 'nullable', 'integer', 'min:0', 'max:'.SimpanDenahMeja::BATAS],
            'Meja.*.PosisiY' => ['present', 'nullable', 'integer', 'min:0', 'max:'.SimpanDenahMeja::BATAS],
        ];
    }

    /**
     * @return list<array{Uuid: string, PosisiX: int|null, PosisiY: int|null}>
     */
    public function AmbilPosisi(): array
    {
        $hasil = [];

        foreach ((array) $this->input('Meja') as $baris) {
            $hasil[] = [
                'Uuid' => (string) $baris['Uuid'],
                'PosisiX' => $baris['PosisiX'] === null ? null : (int) $baris['PosisiX'],
                'PosisiY' => $baris['PosisiY'] === null ? null : (int) $baris['PosisiY'],
            ];
        }

        return $hasil;
    }
}
