<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pemilik\V1;

use App\Domain\Organisasi\Aksi\CabutPerangkat;
use App\Domain\Organisasi\Kueri\DaftarPerangkat;
use App\Domain\Organisasi\Model\Perangkat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Perangkat POS di Aplikasi Owner (OWN-08): `GET /api/pemilik/v1/perangkat` (izin `perangkat.lihat`) = status per outlet
 * (terakhir aktif, outbox tertunda, versi aplikasi), dan `POST /api/pemilik/v1/perangkat/{uuid}/cabut` (izin
 * `perangkat.kelola`) = cabut perangkat yang hilang atau dijual (BR-02.3, aksi yang sama dengan back-office). Keduanya
 * dibatasi outlet akses pengguna; perangkat di luar akses diperlakukan tidak ada (404).
 */
final class PerangkatKontroler extends DasarPemilikKontroler
{
    public function Daftar(Request $permintaan, DaftarPerangkat $perangkat): JsonResponse
    {
        return response()->json(['Perangkat' => $perangkat->AmbilStatus($this->IdOutletBoleh($permintaan))]);
    }

    public function Cabut(Request $permintaan, string $perangkat, CabutPerangkat $cabut, DaftarPerangkat $kueri): JsonResponse
    {
        $idOutlet = $this->IdOutletBoleh($permintaan);
        $baris = Perangkat::query()
            ->where('Uuid', strtoupper($perangkat))
            ->when($idOutlet !== null, fn ($kueriPerangkat) => $kueriPerangkat->whereIn('IdOutlet', $idOutlet ?? []))
            ->first();
        abort_if($baris === null, 404);

        return response()->json(['Perangkat' => $kueri->StatusSatu($cabut->Jalankan($baris))]);
    }
}
