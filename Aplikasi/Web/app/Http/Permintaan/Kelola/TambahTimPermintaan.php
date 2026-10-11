<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Karyawan\Data\DataKaryawan;

/**
 * D-101: satu formulir "Tambah anggota tim". `Akses` memilih apa yang dibuat:
 * - `Kasir`: akun masuk aplikasi kasir dengan PIN (tanpa email);
 * - `Dashboard`: akun dengan email + kata sandi awal untuk dashboard (PIN opsional);
 * - `Tanpa`: hanya data karyawan (dijadwalkan & digaji, tanpa akun masuk).
 * Untuk dua yang pertama, aturan peran/outlet/PIN/kata sandi sama dengan tambah pengguna (D-22).
 */
final class TambahTimPermintaan extends TambahPenggunaPermintaan
{
    private const ANGKA = ['nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'];

    protected function prepareForValidation(): void
    {
        // Kasir = tanpa email, apa pun yang sempat diketik sebelum pindah jenis.
        if ($this->input('Akses') === 'Kasir') {
            $this->merge(['Email' => null, 'KataSandi' => null]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $karyawan = [
            'LevelStaf' => ['nullable', 'string', 'max:40'],
            'GajiPokok' => self::ANGKA,
            'TarifLemburPerJam' => self::ANGKA,
            'PotonganTerlambatPerMenit' => self::ANGKA,
            'PotonganTidakMasukPerHari' => self::ANGKA,
        ];
        $akses = ['Akses' => ['required', 'string', 'in:Kasir,Dashboard,Tanpa']];

        if ($this->input('Akses') === 'Tanpa') {
            return $akses + $karyawan + [
                'Nama' => ['required', 'string', 'max:150'],
                'Jabatan' => ['nullable', 'string', 'max:80'],
                'UuidOutlet' => ['nullable', 'string', 'ulid'],
            ];
        }

        $akun = parent::rules();
        $akun['Email'] = $this->input('Akses') === 'Dashboard'
            ? ['required', 'string', 'email:rfc', 'max:191']
            : ['nullable'];

        return $akses + $karyawan + $akun;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'Akses.required' => 'Pilih apakah ia masuk lewat kasir, dashboard, atau tanpa akun.',
            'Akses.in' => 'Pilih apakah ia masuk lewat kasir, dashboard, atau tanpa akun.',
            'Email.required' => 'Isi email untuk masuk dashboard.',
            'GajiPokok.regex' => 'Gaji pokok harus angka dengan pemisah desimal titik (maks. 2 desimal).',
            'TarifLemburPerJam.regex' => 'Tarif lembur harus angka dengan pemisah desimal titik (maks. 2 desimal).',
            'PotonganTerlambatPerMenit.regex' => 'Potongan terlambat harus angka dengan pemisah desimal titik (maks. 2 desimal).',
            'PotonganTidakMasukPerHari.regex' => 'Potongan tidak masuk harus angka dengan pemisah desimal titik (maks. 2 desimal).',
        ];
    }

    public function AmbilJenisAkses(): string
    {
        return (string) $this->input('Akses');
    }

    public function AmbilUuidOutlet(): ?string
    {
        $uuid = trim((string) $this->input('UuidOutlet', ''));

        return $uuid === '' ? null : strtoupper($uuid);
    }

    /** Rincian gaji & komisi; nama, akun, dan outlet ditentukan oleh jalur yang memakainya. */
    public function AmbilRincianKaryawan(int $idPelaku): DataKaryawan
    {
        $teks = fn (string $kunci): ?string => ($nilai = trim((string) $this->input($kunci, ''))) === '' ? null : $nilai;
        $uang = fn (string $kunci): ?Uang => ($nilai = $teks($kunci)) === null ? null : Uang::Dari($nilai);

        return new DataKaryawan(
            nama: (string) $teks('Nama'),
            jabatan: $teks('Jabatan'),
            levelStaf: $teks('LevelStaf'),
            gajiPokok: $uang('GajiPokok'),
            uuidPengguna: null,
            uuidOutlet: $this->AmbilUuidOutlet(),
            idPengguna: $idPelaku,
            tarifLemburPerJam: $uang('TarifLemburPerJam'),
            potonganTerlambatPerMenit: $uang('PotonganTerlambatPerMenit'),
            potonganTidakMasukPerHari: $uang('PotonganTidakMasukPerHari'),
        );
    }
}
