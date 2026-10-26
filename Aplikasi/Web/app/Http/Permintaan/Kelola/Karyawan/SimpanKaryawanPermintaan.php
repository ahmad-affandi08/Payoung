<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Karyawan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Karyawan\Data\DataKaryawan;
use App\Domain\Karyawan\Enum\DasarGaji;
use App\Domain\Karyawan\Enum\SiklusGaji;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Isian karyawan (F-18): nama, jabatan, level staf (komisi bagian 2), gaji pokok, akun tertaut, outlet utama. */
final class SimpanKaryawanPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Nama' => ['required', 'string', 'max:150'],
            'Jabatan' => ['nullable', 'string', 'max:80'],
            'LevelStaf' => ['nullable', 'string', 'max:40'],
            'GajiPokok' => ['nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'TarifLemburPerJam' => ['nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'PotonganTerlambatPerMenit' => ['nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'PotonganTidakMasukPerHari' => ['nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'SiklusGaji' => ['nullable', 'string', Rule::enum(SiklusGaji::class)],
            'DasarGaji' => ['nullable', 'string', Rule::enum(DasarGaji::class)],
            'UuidPengguna' => ['nullable', 'string', 'ulid'],
            'UuidOutlet' => ['nullable', 'string', 'ulid'],
            // D-180: kosong = tidak diubah (karyawan baru: bawaan menurut peran).
            'IkutAbsensi' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $angka = 'harus angka dengan pemisah desimal titik (maks. 2 desimal).';

        return [
            'GajiPokok.regex' => "Gaji pokok {$angka}",
            'SiklusGaji.*' => 'Pilih siklus gaji: bulanan atau mingguan.',
            'DasarGaji.*' => 'Pilih dasar gaji: tetap per siklus atau upah per hari masuk.',
            'TarifLemburPerJam.regex' => "Tarif lembur {$angka}",
            'PotonganTerlambatPerMenit.regex' => "Potongan terlambat {$angka}",
            'PotonganTidakMasukPerHari.regex' => "Potongan tidak masuk {$angka}",
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Nama' => 'nama', 'Jabatan' => 'jabatan', 'LevelStaf' => 'level staf', 'GajiPokok' => 'gaji pokok', 'TarifLemburPerJam' => 'tarif lembur', 'PotonganTerlambatPerMenit' => 'potongan terlambat', 'PotonganTidakMasukPerHari' => 'potongan tidak masuk', 'UuidPengguna' => 'akun', 'UuidOutlet' => 'outlet'];
    }

    public function AmbilData(int $idPengguna): DataKaryawan
    {
        $teks = fn (string $kunci): ?string => is_string($this->validated($kunci)) && $this->validated($kunci) !== '' ? (string) $this->validated($kunci) : null;
        $gaji = $teks('GajiPokok');
        $uang = fn (string $kunci): ?Uang => ($nilai = $teks($kunci)) === null ? null : Uang::Dari($nilai);

        return new DataKaryawan(
            nama: (string) $this->validated('Nama'),
            jabatan: $teks('Jabatan'),
            levelStaf: $teks('LevelStaf'),
            gajiPokok: $gaji === null ? null : Uang::Dari($gaji),
            uuidPengguna: $teks('UuidPengguna'),
            uuidOutlet: $teks('UuidOutlet'),
            idPengguna: $idPengguna,
            tarifLemburPerJam: $uang('TarifLemburPerJam'),
            potonganTerlambatPerMenit: $uang('PotonganTerlambatPerMenit'),
            potonganTidakMasukPerHari: $uang('PotonganTidakMasukPerHari'),
            ikutAbsensi: $this->filled('IkutAbsensi') ? $this->boolean('IkutAbsensi') : null,
            siklusGaji: $this->filled('SiklusGaji') ? SiklusGaji::from((string) $this->input('SiklusGaji')) : null,
            dasarGaji: $this->filled('DasarGaji') ? DasarGaji::from((string) $this->input('DasarGaji')) : null,
        );
    }
}
