<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Tenant;

use App\Domain\Pengelola\AnalitikSitus\Data\RentangAnalitik;
use App\Domain\Pengelola\AnalitikSitus\Kueri\LaporanAnalitikSitus;
use App\Domain\Pengelola\AnalitikSitus\Layanan\GalatAnalitikGoogle;
use App\Domain\Pengelola\AnalitikSitus\Layanan\KlienGoogleAnalytics;
use App\Http\Kontroler\Kontroler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Analitik situs dari Google Analytics 4 (D-215, `/analitik/situs`): data yang sama dengan yang ada di Google Analytics,
 * dibaca lewat Data API dengan akun layanan dari integrasi P-05. Hanya angka agregat pengunjung situs (tanpa data
 * pribadi), jadi izinnya sama dengan analitik platform: `analitik.lihat`. Halaman memuat kerangka; tiap tab mengambil
 * laporannya sendiri lewat `Data` supaya satu tab yang lambat atau ditolak Google tidak menahan yang lain.
 */
final class AnalitikSitusKontroler extends Kontroler
{
    public function Tampilkan(Request $permintaan): Response
    {
        $klien = KlienGoogleAnalytics::Aktif();
        $rentang = RentangAnalitik::Bangun($permintaan->query('dari'), $permintaan->query('sampai'));

        return Inertia::render('Pengelola/AnalitikSitus/Tampil', [
            'Status' => [
                'Aktif' => $klien->CekAktif(),
                'IdProperti' => $klien->CekAktif() ? $klien->AmbilIdProperti() : null,
            ],
            'Rentang' => $rentang->UntukTampilan(),
            'MaksHari' => RentangAnalitik::MAKS_HARI,
        ]);
    }

    public function Data(Request $permintaan, LaporanAnalitikSitus $laporan): JsonResponse
    {
        $nama = $permintaan->query('laporan');

        if (! is_string($nama) || ! in_array($nama, LaporanAnalitikSitus::DAFTAR_LAPORAN, true)) {
            return response()->json(['Galat' => 'Laporan tidak dikenal.'], 422);
        }

        $klien = KlienGoogleAnalytics::Aktif();

        if (! $klien->CekAktif()) {
            return response()->json(['Galat' => 'Google Analytics belum dihubungkan. Atur di Integrasi › Google Analytics.']);
        }

        $rentang = RentangAnalitik::Bangun($permintaan->query('dari'), $permintaan->query('sampai'));

        try {
            $hasil = $laporan->Ambil($klien, $nama, $rentang, $permintaan->boolean('segarkan'));
        } catch (GalatAnalitikGoogle $galat) {
            return response()->json(['Galat' => $galat->getMessage()]);
        }

        return response()->json($hasil + ['Rentang' => $rentang->UntukTampilan(), 'Pembanding' => $rentang->Sebelumnya()->UntukTampilan()]);
    }
}
