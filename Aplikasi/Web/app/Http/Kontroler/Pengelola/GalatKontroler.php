<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola;

use App\Http\Kontroler\Kontroler;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman galat Platform Pengelola berbahasa Indonesia (PRD §17.6.6 "Tanpa izin" & "Galat", §17.6.7).
 * Dipanggil dari penangan exception global untuk request ke subdomain pengelola.
 */
final class GalatKontroler extends Kontroler
{
    private const PESAN = [
        403 => ['Tidak punya akses', 'Peran Anda belum mencakup halaman ini. Hubungi Super Admin bila Anda membutuhkannya.'],
        404 => ['Halaman tidak ditemukan', 'Alamat ini tidak ada di Platform Pengelola. Periksa lagi tautannya.'],
        429 => ['Terlalu banyak permintaan', 'Tunggu sebentar, lalu coba lagi.'],
        500 => ['Terjadi galat di server', 'Tim teknis sudah bisa melihat masalah ini di log. Coba lagi beberapa saat lagi.'],
        503 => ['Sedang dalam pemeliharaan', 'Platform Pengelola sedang diperbarui. Coba lagi beberapa menit lagi.'],
    ];

    public static function UbahRespons(Response $respons, Request $request): Response
    {
        $status = $respons->getStatusCode();

        if ($request->getHost() !== config('pengelola.Domain') || $request->expectsJson()) {
            return $respons;
        }

        if ($status === 419) {
            return redirect()->back()->with('Kilat', 'Halaman sudah kedaluwarsa. Silakan ulangi langkah terakhir.');
        }

        if (! array_key_exists($status, self::PESAN) || ($status === 500 && config('app.debug'))) {
            return $respons;
        }

        [$judul, $keterangan] = self::PESAN[$status];
        Inertia::setRootView('Pengelola');

        return Inertia::render('Pengelola/Galat', [
            'NamaAplikasi' => config('app.name'),
            'Lingkungan' => app()->isProduction() ? 'Produksi' : (app()->environment('staging') ? 'Staging' : 'Lokal'),
            'Kilat' => null,
            'Status' => $status,
            'Judul' => $judul,
            'Keterangan' => $keterangan,
        ])->toResponse($request)->setStatusCode($status);
    }
}
