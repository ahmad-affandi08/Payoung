<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Domain\Lisensi\Enum\EdisiAplikasi;
use App\Domain\Pengelola\Operasional\Layanan\PencatatMetrikApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Metrik API untuk dasbor operasional (P-11, PGL-20): jumlah, galat server (5xx), dan latensi per kelompok API (POS,
 * Owner, API publik). Dipasang global; hanya jalur `/api/pos/`, `/api/pemilik/`, dan `/api/v1/` yang dicatat. Pencatatan
 * hanya satu baca dan satu tulis berkas (store cache `metrik`), jadi tidak menambah kueri database.
 */
final class CatatMetrikApi
{
    public function __construct(private readonly PencatatMetrikApi $pencatat) {}

    public function handle(Request $request, Closure $next): Response
    {
        $mulai = hrtime(true);
        $respons = $next($request);

        $kelompok = PencatatMetrikApi::TentukanKelompok($request->path());

        // Edisi Lisensi tidak punya konsol/dasbor operasional (penyiramnya tidak dijadwalkan), jadi tidak dicatat.
        if ($kelompok !== null && ! EdisiAplikasi::CekLisensi()) {
            $this->pencatat->Catat($kelompok, $respons->getStatusCode(), intdiv(hrtime(true) - $mulai + 500_000, 1_000_000));
        }

        return $respons;
    }
}
