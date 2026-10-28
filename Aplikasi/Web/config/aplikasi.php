<?php

declare(strict_types=1);

/*
 * Versi aplikasi POS dan Owner per platform (cadangan bila belum ada `RilisAplikasi`) untuk `GET /api/pos/v1/konfigurasi-aplikasi`
 * dan `GET /api/pemilik/v1/konfigurasi-aplikasi` (PRD §14.6, F-02b). Kunci PascalCase
 * (D-05). Versi semantik `MAJOR.MINOR.PATCH` (bagian `+BUILD` diabaikan saat dibandingkan). Aplikasi di bawah
 * `VersiMinimal` tetap boleh mengirim outbox tertunda lalu mengunci layar jual sampai diperbarui.
 *
 * TODO P-10: pindah ke tabel `RilisAplikasi` (kanal Beta/Stabil, rilis bertahap) dan flag fitur remote.
 */
return [
    'Pemilik' => [
        'Android' => [
            'VersiTerbaru' => env('APLIKASI_PEMILIK_ANDROID_VERSI_TERBARU', '1.0.0'),
            'VersiMinimal' => env('APLIKASI_PEMILIK_ANDROID_VERSI_MINIMAL', '1.0.0'),
            'TautanUnduh' => env('APLIKASI_PEMILIK_ANDROID_TAUTAN_UNDUH'),
        ],
        'Ios' => [
            'VersiTerbaru' => env('APLIKASI_PEMILIK_IOS_VERSI_TERBARU', '1.0.0'),
            'VersiMinimal' => env('APLIKASI_PEMILIK_IOS_VERSI_MINIMAL', '1.0.0'),
            'TautanUnduh' => env('APLIKASI_PEMILIK_IOS_TAUTAN_UNDUH'),
        ],
    ],
    'Pos' => [
        'Android' => [
            'VersiTerbaru' => env('APLIKASI_POS_ANDROID_VERSI_TERBARU', '1.0.0'),
            'VersiMinimal' => env('APLIKASI_POS_ANDROID_VERSI_MINIMAL', '1.0.0'),
            // Bawaan = halaman Play Store (v7.35), supaya halaman Perangkat & panduan awal di back-office langsung
            // bisa mengarahkan pemilik usaha mengunduh aplikasi. Rilis P-10 (`UrlUnduh`) menang atas nilai ini.
            'TautanUnduh' => env('APLIKASI_POS_ANDROID_TAUTAN_UNDUH', 'https://play.google.com/store/apps/details?id=id.payoung.kasir'),
        ],
        'Ios' => [
            'VersiTerbaru' => env('APLIKASI_POS_IOS_VERSI_TERBARU', '1.0.0'),
            'VersiMinimal' => env('APLIKASI_POS_IOS_VERSI_MINIMAL', '1.0.0'),
            'TautanUnduh' => env('APLIKASI_POS_IOS_TAUTAN_UNDUH'),
        ],
        'Windows' => [
            'VersiTerbaru' => env('APLIKASI_POS_WINDOWS_VERSI_TERBARU', '1.0.0'),
            'VersiMinimal' => env('APLIKASI_POS_WINDOWS_VERSI_MINIMAL', '1.0.0'),
            'TautanUnduh' => env('APLIKASI_POS_WINDOWS_TAUTAN_UNDUH'),
        ],
    ],
];
