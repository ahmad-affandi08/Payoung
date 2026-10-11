<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Karyawan;

use App\Domain\Karyawan\Aksi\SimpanKaryawan;
use App\Domain\Karyawan\Model\Karyawan;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Permintaan\Kelola\Karyawan\SimpanKaryawanPermintaan;
use Illuminate\Http\RedirectResponse;

/**
 * Ubah data karyawan (F-18, EMP-01, panel di halaman Tim). Daftar, tambah, dan nonaktif/aktif ada di Tim
 * (`TimKontroler`, D-101). Butuh `karyawan.kelola` (dipasang di rute). Karyawan tenant lain = 404 (`MilikTenant`).
 */
final class KaryawanKontroler extends DasarKelolaKontroler
{
    public function Perbarui(SimpanKaryawanPermintaan $permintaan, string $karyawan, SimpanKaryawan $simpan): RedirectResponse
    {
        $k = $simpan->Jalankan($permintaan->AmbilData($this->Pelaku()->Id), $this->Cari($karyawan));

        return back()->with('Kilat', "Karyawan {$k->Nama} disimpan.");
    }

    private function Cari(string $uuid): Karyawan
    {
        return Karyawan::query()->where('Uuid', $uuid)->firstOrFail();
    }
}
