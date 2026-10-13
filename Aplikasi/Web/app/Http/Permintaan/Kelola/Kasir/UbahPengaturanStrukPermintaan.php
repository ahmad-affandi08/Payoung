<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Kasir;

use App\Domain\Tenant\Data\DataPengaturanStruk;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Isian pengaturan struk (PRD v1.79). Teks kosong = bawaan aplikasi; baris kepala kosong diabaikan.
 */
final class UbahPengaturanStrukPermintaan extends FormRequest
{
    private const SAKLAR = ['TampilkanLogo', 'TampilkanAlamat', 'TampilkanTelepon', 'TampilkanNpwp', 'TampilkanKasir', 'TampilkanPelanggan', 'TampilkanHemat'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $baris = DataPengaturanStruk::PANJANG_BARIS_MAKSIMAL;

        return [
            ...array_fill_keys(self::SAKLAR, ['required', 'boolean']),
            // POS-11 (ditambah setelah v1.79): boleh tidak dikirim, bawaan hidup.
            'TampilkanStrukDigital' => ['sometimes', 'boolean'],
            'TampilkanUmpanBalik' => ['sometimes', 'boolean'],
            // D-76: saklar & logo khusus satu outlet (kosong = pengaturan tenant). Logo: gambar, maks 1 MB.
            'UuidOutlet' => ['nullable', 'string', 'max:40'],
            'Logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'HapusLogo' => ['sometimes', 'boolean'],
            'NamaDicetak' => ['nullable', 'string', "max:{$baris}"],
            // Batas 3 baris diperiksa setelah baris kosong dibuang (Aksi); di sini hanya batas wajar masukan.
            'TeksKepala' => ['present', 'array', 'max:10'],
            'TeksKepala.*' => ['nullable', 'string', "max:{$baris}"],
            'CatatanKaki' => ['nullable', 'string', 'max:'.DataPengaturanStruk::PANJANG_CATATAN_KAKI_MAKSIMAL],
            'TeksPenutup' => ['nullable', 'string', "max:{$baris}"],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $baris = DataPengaturanStruk::PANJANG_BARIS_MAKSIMAL;

        return [
            'NamaDicetak.max' => "Nama di struk paling banyak {$baris} karakter agar muat satu baris.",
            'TeksKepala.max' => 'Teks kepala struk paling banyak '.DataPengaturanStruk::JUMLAH_TEKS_KEPALA_MAKSIMAL.' baris.',
            'TeksKepala.*.max' => "Setiap baris kepala paling banyak {$baris} karakter.",
            'CatatanKaki.max' => 'Catatan kaki paling banyak '.DataPengaturanStruk::PANJANG_CATATAN_KAKI_MAKSIMAL.' karakter.',
            'TeksPenutup.max' => "Kalimat penutup paling banyak {$baris} karakter.",
        ];
    }

    public function AmbilData(): DataPengaturanStruk
    {
        $kepala = [];

        foreach ((array) $this->input('TeksKepala', []) as $teks) {
            $teks = self::Rapikan($teks);

            if ($teks !== null) {
                $kepala[] = $teks;
            }
        }

        return new DataPengaturanStruk(
            tampilkanLogo: $this->boolean('TampilkanLogo'),
            namaDicetak: self::Rapikan($this->input('NamaDicetak')),
            teksKepala: $kepala,
            tampilkanAlamat: $this->boolean('TampilkanAlamat'),
            tampilkanTelepon: $this->boolean('TampilkanTelepon'),
            tampilkanNpwp: $this->boolean('TampilkanNpwp'),
            tampilkanKasir: $this->boolean('TampilkanKasir'),
            tampilkanPelanggan: $this->boolean('TampilkanPelanggan'),
            tampilkanHemat: $this->boolean('TampilkanHemat'),
            catatanKaki: self::Rapikan($this->input('CatatanKaki')),
            teksPenutup: self::Rapikan($this->input('TeksPenutup')),
            tampilkanStrukDigital: $this->boolean('TampilkanStrukDigital', true),
            tampilkanUmpanBalik: $this->boolean('TampilkanUmpanBalik', true),
        );
    }

    private static function Rapikan(mixed $nilai): ?string
    {
        $teks = is_string($nilai) ? trim($nilai) : '';

        return $teks === '' ? null : $teks;
    }
}
