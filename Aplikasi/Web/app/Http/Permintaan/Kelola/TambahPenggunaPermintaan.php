<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola;

use App\Domain\Organisasi\Data\DataPenggunaBaru;
use Illuminate\Validation\Rules\Password;

/**
 * D-22: tambah pengguna langsung. Email kosong = karyawan hanya kasir (wajib PIN 6 angka); email terisi wajib kata
 * sandi awal (diganti pengguna saat pertama masuk).
 */
class TambahPenggunaPermintaan extends AksesAnggotaPermintaan
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Nama' => ['required', 'string', 'max:150'],
            'Email' => ['nullable', 'string', 'email:rfc', 'max:191'],
            'NoHp' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\- ]{8,20}$/'],
            'KataSandi' => ['nullable', 'required_with:Email', 'string', 'max:100', Password::min(8)->letters()->numbers()],
            'Pin' => ['nullable', 'required_without:Email', 'digits:6'],
            // Audit kemudahan pakai #34: sekalian catat sebagai karyawan (jadwal, absensi, gaji).
            'JugaKaryawan' => ['nullable', 'boolean'],
            'Jabatan' => ['nullable', 'string', 'max:80'],
            // D-46: menautkan akun baru ke karyawan yang sudah ada (tombol "Buatkan akun" di daftar karyawan).
            'UuidKaryawan' => ['nullable', 'string', 'ulid'],
            ...parent::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'KataSandi.required_with' => 'Isi kata sandi awal untuk pengguna yang memakai email.',
            'Pin.required_without' => 'Karyawan tanpa email wajib diberi PIN untuk masuk aplikasi kasir.',
            'Pin.digits' => 'PIN harus 6 angka.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Nama' => 'nama', 'NoHp' => 'nomor WhatsApp', 'KataSandi' => 'kata sandi awal'];
    }

    public function AmbilUuidKaryawan(): ?string
    {
        $uuid = trim((string) $this->input('UuidKaryawan', ''));

        return $uuid === '' ? null : strtoupper($uuid);
    }

    public function AmbilJabatan(): ?string
    {
        $jabatan = trim((string) $this->input('Jabatan', ''));

        return $jabatan === '' ? null : $jabatan;
    }

    public function AmbilPenggunaBaru(): DataPenggunaBaru
    {
        $teks = fn (string $kunci): ?string => ($nilai = trim((string) $this->input($kunci, ''))) === '' ? null : $nilai;

        return new DataPenggunaBaru(
            nama: (string) $teks('Nama'),
            email: $teks('Email'),
            noHp: $teks('NoHp'),
            kataSandi: $this->filled('KataSandi') ? $this->string('KataSandi')->toString() : null,
            pin: $teks('Pin'),
        );
    }
}
