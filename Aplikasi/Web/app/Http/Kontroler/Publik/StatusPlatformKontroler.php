<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Tenant\Kueri\StatusPlatformPublik;
use App\Http\Kontroler\Kontroler;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman status layanan publik (P-11 PGL-21), tanpa login: `/status` di shell situs pemasaran dan `/status/data` untuk
 * halaman status statis di hosting terpisah (`status:ekspor`) atau pemantau eksternal. Hanya insiden yang diumumkan.
 */
final class StatusPlatformKontroler extends Kontroler
{
    public function Tampilkan(StatusPlatformPublik $status): Response
    {
        return Inertia::render('Situs/StatusPlatform', ['Status' => $status->Ambil()]);
    }

    public function Json(StatusPlatformPublik $status): JsonResponse
    {
        return response()->json($status->Ambil(), 200, [
            'Cache-Control' => 'public, max-age='.StatusPlatformPublik::DETIK_TEMBOLOK,
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
