<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pos\V1;

use App\Domain\Organisasi\Aksi\MasukPinKasir;
use App\Domain\Organisasi\Aksi\UbahPinKasir;
use App\Http\Kontroler\Kontroler;
use App\Http\Perantara\AutentikasiPerangkat;
use App\Http\Permintaan\Pos\V1\MasukPinPermintaan;
use App\Http\Permintaan\Pos\V1\UbahPinPermintaan;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/pos/v1/kasir/masuk-pin` (F-02 langkah 4, §20.2): verifikasi PIN kasir secara online. Aplikasi lalu
 * mengirim `X-Id-Kasir` (Uuid pengguna) pada permintaan berikutnya (F-06+).
 */
final class KasirKontroler extends Kontroler
{
    public function MasukPin(MasukPinPermintaan $permintaan, MasukPinKasir $masuk): JsonResponse
    {
        $hasil = $masuk->Jalankan(
            AutentikasiPerangkat::AmbilPerangkat($permintaan),
            $permintaan->string('UuidPengguna')->toString(),
            $permintaan->string('Pin')->toString(),
        );

        return response()->json([
            'Pengguna' => ['Uuid' => $hasil['Pengguna']->Uuid, 'Nama' => $hasil['Pengguna']->Nama],
            'Pemilik' => $hasil['Pemilik'],
            'Izin' => $hasil['Izin'],
        ]);
    }

    /** D-301: kasir mengganti PIN miliknya (PIN lama diperiksa dengan batas percobaan yang sama dengan masuk PIN). */
    public function UbahPin(UbahPinPermintaan $permintaan, UbahPinKasir $ubah): JsonResponse
    {
        $ubah->Jalankan(
            AutentikasiPerangkat::AmbilPerangkat($permintaan),
            $permintaan->string('UuidPengguna')->toString(),
            $permintaan->string('PinLama')->toString(),
            $permintaan->string('PinBaru')->toString(),
        );

        return response()->json(['Berhasil' => true]);
    }
}
