<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Domain\Bersama\Web\AlamatDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-20: pemisahan domain pemasaran (`DOMAIN_PEMASARAN`, misal `payoung.id`) dan tenant (`DOMAIN_TENANT`, misal
 * `dashboard.payoung.id`). Di domain pemasaran hanya rute pemasaran yang dilayani; rute lain dialihkan ke domain tenant
 * dengan jalur & query yang sama (GET 302, selain itu 307 agar metode & isi tetap). Beranda di domain tenant dialihkan
 * ke back-office (tamu diarahkan ke halaman masuk oleh `auth`); respons domain tenant diberi `X-Robots-Tag: noindex`.
 * Tanpa kedua domain diatur, tidak melakukan apa-apa.
 */
final class ArahkanDomainAplikasi
{
    /**
     * Rute yang tetap dilayani di domain pemasaran. Dokumen legal & kompatibilitas juga dilayani di domain tenant.
     * Pusat Bantuan, portal pengembang, dan halaman status ikut di sini (D-158): tanpa itu `/pusat-bantuan` di domain
     * pemasaran dialihkan ke domain tenant yang `noindex`, padahal peta situs mendaftarkannya.
     */
    public const RUTE_PEMASARAN = ['beranda', 'legal.tampil', 'publik.kompatibilitas-perangkat', 'situs.halaman', 'situs.pratinjau', 'situs.peta', 'situs.prospek.kirim', 'situs.blog.daftar', 'situs.blog.artikel', 'situs.bantuan.daftar', 'situs.bantuan.artikel', 'situs.pengembang', 'publik.status', 'publik.status.data'];

    /** D-21: rute situs pemasaran yang di domain tenant dialihkan ke domain pemasaran. */
    public const RUTE_KHUSUS_PEMASARAN = ['situs.halaman', 'situs.pratinjau', 'situs.peta', 'situs.blog.daftar', 'situs.blog.artikel'];

    public function handle(Request $request, Closure $next): Response
    {
        $pemasaran = self::AmbilDomain('domain.Pemasaran');
        $tenant = self::AmbilDomain('domain.Tenant');

        if ($pemasaran === null || $tenant === null || $pemasaran === $tenant) {
            return $next($request);
        }

        $rute = $request->route()?->getName();
        $host = strtolower($request->getHost());

        if ($host === $pemasaran && ! in_array($rute, self::RUTE_PEMASARAN, true)) {
            return redirect()->away(
                self::BuatUrl($tenant, $request->getRequestUri()),
                $request->isMethod('GET') || $request->isMethod('HEAD') ? 302 : 307,
            );
        }

        if ($host === $tenant && $rute === 'beranda') {
            return redirect()->route('kelola.beranda');
        }

        if ($host === $tenant && in_array($rute, self::RUTE_KHUSUS_PEMASARAN, true)) {
            return redirect()->away(self::BuatUrl($pemasaran, $request->getRequestUri()));
        }

        $respons = $next($request);

        // D-21: domain tenant (back-office, struk digital, data toko) tidak diindeks mesin pencari.
        if ($host === $tenant) {
            $respons->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $respons;
    }

    /** Alamat [jalur] di domain tenant; jalur relatif bila domain belum dipisah (lihat `AlamatDomain`). */
    public static function BuatUrlTenant(string $jalur): string
    {
        return AlamatDomain::BuatUrlTenant($jalur);
    }

    /** Alamat [jalur] di domain pemasaran; jalur relatif bila domain belum dipisah. */
    public static function BuatUrlPemasaran(string $jalur): string
    {
        return AlamatDomain::BuatUrlPemasaran($jalur);
    }

    private static function BuatUrl(string $host, string $jalur): string
    {
        return AlamatDomain::BuatUrl($host, $jalur);
    }

    private static function AmbilDomain(string $kunci): ?string
    {
        return AlamatDomain::AmbilDomain($kunci);
    }
}
