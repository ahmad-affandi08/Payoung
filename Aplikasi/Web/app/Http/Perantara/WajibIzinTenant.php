<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Izin tenant untuk rute back-office (PRD §19.1). Pemakaian: `->middleware(WajibIzinTenant::class.':outlet.kelola')`,
 * dipasang setelah `IdentifikasiTenantSesi`. Pemilik selalu lolos. Tanpa izin → halaman "Tanpa izin" (§17.6.6), 403.
 * Beberapa izin dipisah `|` = cukup salah satu (D-101: halaman Tim terbuka bagi pemegang `karyawan.lihat` atau
 * `pengguna.lihat`).
 */
final class WajibIzinTenant
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly AksesPengguna $akses,
    ) {}

    public function handle(Request $request, Closure $next, string $izin): Response
    {
        $idPengguna = Auth::guard('web')->id();
        $idTenant = $this->konteks->Ambil();

        if (is_int($idPengguna) && $idTenant !== null) {
            foreach (explode('|', $izin) as $kunci) {
                if ($this->akses->CekIzin($idTenant, $idPengguna, IzinTenant::from($kunci))) {
                    return $next($request);
                }
            }
        }

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            abort(403, 'Anda tidak punya akses ke halaman ini.');
        }

        return Inertia::render('Kelola/TanpaIzin')->toResponse($request)->setStatusCode(403);
    }
}
