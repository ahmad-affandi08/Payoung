<?php

declare(strict_types=1);

namespace App\Http\Respons;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * PWA halaman Absen (F-18 bagian 4, D-37, D-211): manifest dan service worker agar karyawan bisa memasang halaman
 * Absen ke layar utama HP dan tidak perlu membuka tautan terus-menerus. Dipakai kedua pintu masuk: halaman berlogin
 * `/kelola/absen` dan tautan pribadi `/{slug}/absen/{token}`. Cakupan dibatasi ke jalur halaman itu sendiri.
 */
final class ResponsPwaAbsensi
{
    /** @param string $jalur jalur halaman Absen tanpa garis miring akhir, mis. `/kelola/absen` */
    public static function Manifest(string $jalur, string $namaToko, string $namaKaryawan): JsonResponse
    {
        return response()->json([
            'name' => "Absen {$namaToko}",
            'short_name' => 'Absen',
            'description' => "Absen masuk & pulang {$namaKaryawan} di {$namaToko}",
            'lang' => 'id',
            'start_url' => $jalur,
            'scope' => $jalur,
            'id' => $jalur,
            'display' => 'standalone',
            'orientation' => 'portrait',
            // Sama dengan token `--color-brand-gelap` & `--color-permukaan` (D-15).
            'theme_color' => '#22383a',
            'background_color' => '#ffffff',
            'icons' => [
                ['src' => '/ikon-pwa-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/ikon-pwa-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'private, max-age=3600']);
    }

    /** Halaman ada di jalur tanpa garis miring akhir, satu tingkat di atas skrip ini, jadi cakupannya perlu diizinkan. */
    public static function PekerjaLayanan(string $jalur): Response
    {
        return response(view('Pwa.PekerjaAbsensi')->render(), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => $jalur,
        ]);
    }
}
