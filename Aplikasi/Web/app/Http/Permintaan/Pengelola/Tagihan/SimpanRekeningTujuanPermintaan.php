<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Tagihan;

use App\Domain\Pengelola\Tagihan\Aksi\SimpanRekeningTujuanPlatform;
use Illuminate\Foundation\Http\FormRequest;

/** Daftar rekening tujuan transfer penagihan (P-08, D-114). */
final class SimpanRekeningTujuanPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Rekening' => ['present', 'array', 'max:'.SimpanRekeningTujuanPlatform::MAKS],
            'Rekening.*.NamaBank' => ['required', 'string', 'max:100'],
            'Rekening.*.NomorRekening' => ['required', 'string', 'max:50', 'regex:/^[0-9][0-9 .\-]{3,49}$/'],
            'Rekening.*.AtasNama' => ['required', 'string', 'max:150'],
            'Alasan' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'Rekening' => 'daftar rekening',
            'Rekening.*.NamaBank' => 'nama bank',
            'Rekening.*.NomorRekening' => 'nomor rekening',
            'Rekening.*.AtasNama' => 'atas nama',
            'Alasan' => 'alasan perubahan',
        ];
    }

    /**
     * @return list<array{NamaBank: string, NomorRekening: string, AtasNama: string}>
     */
    public function AmbilRekening(): array
    {
        $hasil = [];

        foreach ((array) $this->input('Rekening', []) as $baris) {
            $baris = (array) $baris;
            $hasil[] = [
                'NamaBank' => (string) ($baris['NamaBank'] ?? ''),
                'NomorRekening' => (string) ($baris['NomorRekening'] ?? ''),
                'AtasNama' => (string) ($baris['AtasNama'] ?? ''),
            ];
        }

        return $hasil;
    }

    public function AmbilAlasan(): string
    {
        return $this->string('Alasan')->trim()->toString();
    }
}
