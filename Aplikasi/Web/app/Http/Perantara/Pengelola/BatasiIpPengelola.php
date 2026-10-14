<?php

declare(strict_types=1);

namespace App\Http\Perantara\Pengelola;

use App\Domain\Pengelola\TimInternal\Layanan\AllowlistIpPengelola;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pembatasan alamat IP konsol (PGL-01, D-146): bila daftar IP diizinkan terisi, permintaan dari alamat lain ditolak
 * 403 sebelum halaman masuk pun terbuka. Daftar kosong = tanpa pembatasan. Alamat dibaca dari `$request->ip()`, yang
 * sudah memperhitungkan proksi tepercaya (`PROKSI_TEPERCAYA`).
 */
final class BatasiIpPengelola
{
    public function __construct(private readonly AllowlistIpPengelola $allowlist) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->allowlist->CekBoleh((string) $request->ip())) {
            abort(403, 'Alamat IP ini tidak diizinkan membuka konsol. Hubungi Super Admin.');
        }

        return $next($request);
    }
}
