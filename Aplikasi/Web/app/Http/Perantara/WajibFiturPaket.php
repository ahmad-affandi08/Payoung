<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Http\Respons\GalatApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-111 (BR-P04.3): server selalu penentu hak fitur paket. Menolak rute yang dipetakan di `PetaFiturRute` bila
 * paket/add-on/override tenant tidak memuat fiturnya, apa pun cara pemanggilnya (URL langsung, aplikasi Kasir, Owner).
 *
 * Berjalan setelah tenant diidentifikasi (`IdentifikasiTenantSesi`, `AutentikasiPerangkat`, `IdentifikasiTenantPemilik`).
 * Penegakan bisa dimatikan sementara lewat `tenant.PenegakanFiturPaket` (`TENANT_PENEGAKAN_FITUR_PAKET=false`) selama
 * masa peralihan; tenant tertentu diberi pengecualian lewat override fitur dari konsol.
 */
final class WajibFiturPaket
{
    public const KODE_GALAT = 'FiturTidakAktif';

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PemeriksaFiturTenant $fitur,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $idTenant = $this->konteks->Ambil();

        if ($idTenant === null || ! (bool) config('tenant.PenegakanFiturPaket', true)) {
            return $next($request);
        }

        $aturan = $this->CariAturan($request);

        if ($aturan === null || $this->fitur->CekAktif($idTenant, $aturan['Fitur'])) {
            return $next($request);
        }

        $pesan = "{$aturan['Nama']} belum termasuk di paket usaha ini. Naik paket atau tambah add-on di menu Langganan untuk memakainya.";

        if ($request->is('api/*') || ($request->expectsJson() && ! $request->hasHeader('X-Inertia'))) {
            return GalatApi::Buat(self::KODE_GALAT, $pesan, 403, ['Fitur' => $aturan['Fitur']]);
        }

        // Halaman yang tidak boleh dibuka kembali ke beranda; aksi di halaman yang boleh dilihat kembali ke halaman itu.
        $kembali = $request->isMethod('GET') || $request->isMethod('HEAD') ? redirect()->route('kelola.beranda') : back();

        return $kembali->withErrors(['Umum' => $pesan]);
    }

    /**
     * @return array{Pola: string, Fitur: string, Mode: 'tulis'|'semua', Nama: string, Query?: array<string, string>}|null
     */
    private function CariAturan(Request $request): ?array
    {
        $nama = $request->route()?->getName();

        if (! is_string($nama)) {
            return null;
        }

        foreach (PetaFiturRute::ATURAN as $aturan) {
            if (! $request->routeIs($aturan['Pola']) || ! $this->CocokQuery($request, $aturan['Query'] ?? [])) {
                continue;
            }

            // Aturan pertama yang cocok menang, walau modenya tidak berlaku untuk metode ini.
            return $this->BerlakuUntuk($request, $nama, $aturan['Mode']) ? $aturan : null;
        }

        return null;
    }

    /** @param  array<string, string>  $syarat */
    private function CocokQuery(Request $request, array $syarat): bool
    {
        foreach ($syarat as $kunci => $nilai) {
            if ($request->query($kunci) !== $nilai) {
                return false;
            }
        }

        return true;
    }

    private function BerlakuUntuk(Request $request, string $nama, string $mode): bool
    {
        if ($mode === 'semua') {
            return true;
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return true;
        }

        // Halaman formulir tambah/ubah ikut ditutup supaya pengguna tidak mengisi formulir yang pasti ditolak.
        return in_array(substr($nama, (int) strrpos($nama, '.') + 1), ['buat', 'ubah'], true);
    }
}
