<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pengajuan penghapusan data dan penutupan akun (UU PDP, P-07). Dua pengaman di samping kata sandi: frasa konfirmasi
 * yang diketik persis, dan (di aksi) ekspor data yang sudah diunduh. Kata sandi wajib kecuali akun Google tanpa kata sandi
 * (diperiksa `PastikanKataSandiPengguna`).
 */
final class AjukanPenghapusanDataPermintaan extends FormRequest
{
    public const FRASA = 'HAPUS DATA';

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'KataSandi' => ['nullable', 'string', 'max:200'],
            'Konfirmasi' => ['required', 'string', 'in:'.self::FRASA],
            'Alasan' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'Konfirmasi.required' => 'Ketik "'.self::FRASA.'" untuk melanjutkan.',
            'Konfirmasi.in' => 'Ketik "'.self::FRASA.'" persis seperti contoh untuk melanjutkan.',
        ];
    }

    public function AmbilKataSandi(): ?string
    {
        $nilai = $this->input('KataSandi');

        return is_string($nilai) ? $nilai : null;
    }

    public function AmbilAlasan(): ?string
    {
        $alasan = trim($this->string('Alasan')->toString());

        return $alasan === '' ? null : $alasan;
    }
}
