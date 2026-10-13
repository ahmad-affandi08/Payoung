<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Situs\Kueri\BantuanKontekstual;
use App\Http\Kontroler\Kontroler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PLT-12: bantuan kontekstual di back-office (`GET /kelola/pusat-bantuan?jalur=&cari=`): artikel Pusat Bantuan yang
 * relevan dengan halaman yang sedang dibuka. Terbuka untuk semua anggota tenant (isinya publik); hanya SaaS.
 */
final class PusatBantuanKontroler extends Kontroler
{
    public function Ambil(Request $permintaan, BantuanKontekstual $bantuan): JsonResponse
    {
        $jalur = $permintaan->query('jalur');
        $cari = $permintaan->query('cari');

        return response()->json($bantuan->Ambil(is_string($jalur) ? $jalur : '/kelola', is_string($cari) ? $cari : null));
    }
}
