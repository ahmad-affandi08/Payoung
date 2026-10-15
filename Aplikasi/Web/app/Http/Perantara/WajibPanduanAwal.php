<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\PanduanAwal\Kueri\ProgresPanduan;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-24: tenant baru (`ProgresPanduanAwal.Wajib`) yang panduan awalnya belum selesai tidak bisa membuka back-office.
 * Anggota yang boleh mengelola panduan (Pemilik, Admin) dialihkan ke `/kelola/panduan-awal`; anggota lain melihat
 * halaman "usaha sedang disiapkan". Langganan, bantuan, keamanan akun, dan persetujuan legal tetap bisa dibuka supaya
 * tidak ada yang terkunci. Tenant lama (`Wajib` = false) tidak terpengaruh. Berjalan setelah `IdentifikasiTenantSesi`.
 */
final class WajibPanduanAwal
{
    public const RUTE_PANDUAN = 'kelola.panduan-awal';

    /** @var list<string> */
    private const RUTE_BEBAS = [
        'kelola.panduan-awal', 'kelola.panduan-awal.*',
        'kelola.langganan.*',
        'kelola.bantuan.*',
        'kelola.obrolan.*',
        'kelola.keamanan', 'kelola.keamanan.*',
        'kelola.persetujuan-legal', 'kelola.persetujuan-legal.*',
    ];

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly ProgresPanduan $progres,
        private readonly AksesPengguna $akses,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $pengguna = Auth::guard('web')->user();
        $idTenant = $this->konteks->Ambil();

        if ($request->routeIs(...self::RUTE_BEBAS)
            // PGL-16: petugas dukungan boleh membuka semua halaman sekalipun panduan awal belum selesai.
            || $request->attributes->has(PasangAksesDukungan::ATRIBUT)
            || ! $pengguna instanceof Pengguna
            || $idTenant === null
            || ! $this->progres->CekWajibBelumSelesai()) {
            return $next($request);
        }

        if ($this->akses->CekIzin($idTenant, $pengguna->Id, IzinTenant::PanduanAwalKelola)) {
            return redirect()->route(self::RUTE_PANDUAN);
        }

        return Inertia::render('Kelola/PanduanAwal/Menunggu')->toResponse($request)->setStatusCode(Response::HTTP_OK);
    }
}
