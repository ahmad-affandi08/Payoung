<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Tenant\Kueri\RingkasanLanggananTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * F-00: selama langganan `Ditangguhkan`, tenant hanya boleh masuk, melihat (termasuk laporan), export, dan membayar
 * tagihan. Di back-office `/kelola` semua permintaan yang mengubah data (bukan GET/HEAD) ditolak, kecuali rute yang
 * dibutuhkan untuk keluar dari penangguhan atau menjaga akun: langganan (bayar & unggah bukti), keamanan akun,
 * bantuan (tiket dukungan), dan persetujuan dokumen legal. Keluar (`/keluar`) berada di luar `/kelola`.
 * Berjalan setelah `IdentifikasiTenantSesi`.
 */
final class BatasiTenantDitangguhkan
{
    public const KODE_GALAT = 'TenantDitangguhkan';

    public const PESAN = 'Langganan usaha ini sedang ditangguhkan, jadi data tidak bisa diubah. Anda tetap bisa melihat data, '
        .'mengekspor, dan membayar tagihan di menu Langganan.';

    /** Rute yang tetap boleh mengubah data saat ditangguhkan. */
    private const RUTE_DIKECUALIKAN = [
        'kelola.langganan.*',
        'kelola.keamanan',
        'kelola.keamanan.*',
        'kelola.bantuan.*',
        'kelola.obrolan.*',
        'kelola.persetujuan-legal',
        'kelola.persetujuan-legal.*',
    ];

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly RingkasanLanggananTenant $langganan,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $idTenant = $this->konteks->Ambil();

        if ($request->isMethod('GET') || $request->isMethod('HEAD')
            || $idTenant === null
            || $request->routeIs(...self::RUTE_DIKECUALIKAN)
            || ! $this->langganan->CekDitangguhkan($idTenant)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json([
                'Galat' => ['Kode' => self::KODE_GALAT, 'Pesan' => self::PESAN, 'Detail' => new \stdClass],
            ], 423);
        }

        return back()->withErrors(['Umum' => self::PESAN]);
    }
}
