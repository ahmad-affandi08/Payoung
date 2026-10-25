<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Operasional;

use App\Domain\Pengelola\Operasional\Aksi\JalankanJadwalSekarang;
use App\Domain\Pengelola\Operasional\Kueri\DaftarJadwalOtomatis;
use App\Domain\Pengelola\Operasional\Kueri\PenyimpananTenantTerbesar;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman Jadwal & penyimpanan di Operasional (D-209): riwayat tiap perintah terjadwal dengan tombol "Jalankan
 * sekarang", dan tenant dengan pemakaian penyimpanan terbesar.
 */
final class JadwalOtomatisKontroler extends Kontroler
{
    use PelakuPengelola;

    public function Daftar(DaftarJadwalOtomatis $jadwal, PenyimpananTenantTerbesar $penyimpanan): Response
    {
        return Inertia::render('Pengelola/Operasional/Jadwal', [
            'Jadwal' => $jadwal->Ambil(),
            'Penyimpanan' => $penyimpanan->Ambil(),
            'BolehKelola' => $this->AmbilPelaku()->PunyaIzin(IzinPengelola::OperasionalKelola),
        ]);
    }

    public function Jalankan(Request $permintaan, JalankanJadwalSekarang $jalankan): RedirectResponse
    {
        $perintah = trim((string) $permintaan->validate(['Perintah' => ['required', 'string', 'max:160']])['Perintah']);
        $jalankan->Jalankan($this->AmbilPelaku(), $perintah);

        return back()->with('Kilat', "Perintah {$perintah} diantrekan. Hasilnya muncul di riwayat dalam beberapa menit.");
    }
}
