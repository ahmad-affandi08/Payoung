<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Karyawan;

use App\Domain\Karyawan\Aksi\KelolaTukarShift;
use App\Domain\Karyawan\Aksi\SimpanShiftKerja;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Shift bernama dan keputusan tukar shift pada halaman Jadwal (F-18, D-251). Semuanya `karyawan.kelola`.
 */
final class ShiftKerjaKontroler extends DasarKelolaKontroler
{
    public function Simpan(Request $permintaan, SimpanShiftKerja $simpan): RedirectResponse
    {
        $shift = $simpan->Jalankan(null, ...$this->BacaIsian($permintaan), idPengguna: $this->Pelaku()->Id);

        return back()->with('Kilat', "Shift {$shift->Nama} ditambahkan.");
    }

    public function Perbarui(Request $permintaan, string $shift, SimpanShiftKerja $simpan): RedirectResponse
    {
        $baris = $simpan->Jalankan($shift, ...$this->BacaIsian($permintaan), idPengguna: $this->Pelaku()->Id);

        return back()->with('Kilat', "Shift {$baris->Nama} disimpan.");
    }

    public function Hapus(string $shift, SimpanShiftKerja $simpan): RedirectResponse
    {
        $simpan->Hapus($shift, $this->Pelaku()->Id);

        return back()->with('Kilat', 'Shift dihapus. Jadwal yang sudah memakainya tidak berubah.');
    }

    public function PutuskanTukar(Request $permintaan, string $permintaanTukar, KelolaTukarShift $tukar): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Setuju' => ['required', 'boolean'],
            'Alasan' => ['nullable', 'string', 'max:200'],
        ]);
        $setuju = (bool) $valid['Setuju'];
        $tukar->Putuskan($permintaanTukar, $setuju, $valid['Alasan'] ?? null, $this->Pelaku()->Id);

        return back()->with('Kilat', $setuju ? 'Tukar shift disetujui dan jadwal sudah diperbarui.' : 'Tukar shift ditolak.');
    }

    /**
     * @return array{nama: string, jamMulai: string, jamSelesai: string, aktif: bool, urutan: int}
     */
    private function BacaIsian(Request $permintaan): array
    {
        $valid = $permintaan->validate([
            'Nama' => ['required', 'string', 'max:40'],
            'JamMulai' => ['required', 'string', 'size:5'],
            'JamSelesai' => ['required', 'string', 'size:5'],
            'Aktif' => ['required', 'boolean'],
            'Urutan' => ['nullable', 'integer', 'between:0,999'],
        ]);

        return [
            'nama' => (string) $valid['Nama'],
            'jamMulai' => (string) $valid['JamMulai'],
            'jamSelesai' => (string) $valid['JamSelesai'],
            'aktif' => (bool) $valid['Aktif'],
            'urutan' => (int) ($valid['Urutan'] ?? 0),
        ];
    }
}
