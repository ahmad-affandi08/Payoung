<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola;

use App\Domain\Pengelola\Tenant\Kueri\DasborBisnisPengelola;
use App\Domain\Pengelola\Tenant\Kueri\RingkasanBerandaPengelola;
use App\Http\Kontroler\Kontroler;
use Inertia\Inertia;
use Inertia\Response;

/** Beranda konsol: ringkasan tenant, tagihan, dukungan, dan kondisi sistem sesuai izin pelaku, plus butir yang perlu tindakan. */
final class BerandaKontroler extends Kontroler
{
    use PelakuPengelola;

    public function Tampilkan(RingkasanBerandaPengelola $ringkasan, DasborBisnisPengelola $bisnis): Response
    {
        $pelaku = $this->AmbilPelaku();

        return Inertia::render('Pengelola/Beranda', [
            'Ringkasan' => $ringkasan->Ambil($pelaku),
            // D-207: bahan bisnis pusat komando (MRR, tren, per paket/sektor, tenant berisiko, konversi, aktivitas).
            'Bisnis' => $bisnis->Ambil($pelaku),
        ]);
    }
}
