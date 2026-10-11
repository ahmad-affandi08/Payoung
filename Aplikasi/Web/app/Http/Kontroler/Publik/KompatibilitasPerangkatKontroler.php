<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Tenant\Kueri\DaftarKompatibilitasPerangkat;
use App\Http\Kontroler\Kontroler;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman publik daftar kompatibilitas perangkat & printer (PRD §17.2.5a HCL, v1.98), tanpa login.
 * D-92: ikut bundle & shell situs pemasaran (`Situs/KompatibilitasPerangkat` + `BagikanDataSitus`).
 */
final class KompatibilitasPerangkatKontroler extends Kontroler
{
    public function Tampilkan(DaftarKompatibilitasPerangkat $kueri): Response
    {
        return Inertia::render('Situs/KompatibilitasPerangkat', ['Baris' => $kueri->Ambil(publik: true)]);
    }
}
