<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Tagihan;

use Illuminate\Foundation\Http\FormRequest;

/** Lama langganan yang boleh dipilih tenant dan tangga diskonnya (P-08, D-249). Rentang diperiksa lagi oleh Aksi terhadap batas pengaman server. */
final class UbahLamaLanggananPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'MinimalBulan' => ['required', 'integer', 'min:1', 'max:120'],
            'MaksimalBulan' => ['required', 'integer', 'min:1', 'max:120', 'gte:MinimalBulan'],
            'TanggaDiskon' => ['present', 'array', 'max:12'],
            'TanggaDiskon.*.DariBulan' => ['required', 'integer', 'min:1', 'max:120'],
            'TanggaDiskon.*.Persen' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'Alasan' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'MinimalBulan' => 'lama minimal',
            'MaksimalBulan' => 'lama maksimal',
            'TanggaDiskon' => 'tangga diskon',
            'TanggaDiskon.*.DariBulan' => 'lama (bulan) pada tangga diskon',
            'TanggaDiskon.*.Persen' => 'persen diskon',
            'Alasan' => 'alasan perubahan',
        ];
    }

    /**
     * @return list<array{DariBulan: int, Persen: string}>
     */
    public function AmbilTangga(): array
    {
        $hasil = [];

        foreach ((array) $this->input('TanggaDiskon', []) as $baris) {
            $hasil[] = ['DariBulan' => (int) $baris['DariBulan'], 'Persen' => (string) $baris['Persen']];
        }

        return $hasil;
    }

    public function AmbilAlasan(): string
    {
        return $this->string('Alasan')->trim()->toString();
    }
}
