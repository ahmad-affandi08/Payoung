<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Dukungan\Layanan\KonteksAksesDukungan;
use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use App\Domain\Organisasi\Model\Pengguna;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menetapkan tenant aktif back-office dari sesi (PRD §13.4, BR-00.1). Keanggotaan diperiksa ulang setiap request,
 * sehingga anggota yang dikeluarkan langsung kehilangan akses. Tanpa tenant aktif → pemilih tenant.
 */
final class IdentifikasiTenantSesi
{
    public const KUNCI_SESI = 'IdTenantAktif';

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly KeanggotaanPengguna $keanggotaan,
        private readonly KonteksAksesDukungan $dukungan,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $pengguna = Auth::guard('web')->user();
        $idTenant = $request->session()->get(self::KUNCI_SESI);

        // PGL-16: petugas dukungan bukan anggota tenant; yang sah hanyalah identitas teknis pada sesi yang baru saja
        // diperiksa `PasangAksesDukungan` (konteksnya hanya terisi bila sesi, izin induk, dan petugasnya masih berlaku).
        $sesiDukunganSah = $pengguna instanceof Pengguna && is_int($idTenant) && $this->dukungan->CekPengguna($idTenant, $pengguna->Id);

        if (! $pengguna instanceof Pengguna || ! is_int($idTenant) || (! $sesiDukunganSah && ! $this->keanggotaan->CekAnggota($pengguna->Id, $idTenant))) {
            $request->session()->forget(self::KUNCI_SESI);

            return redirect()->route('pilih-tenant');
        }

        $this->konteks->Atur($idTenant);

        return $next($request);
    }
}
