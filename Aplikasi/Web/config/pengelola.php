<?php

declare(strict_types=1);

/*
 * Platform Pengelola (P-01, PRD §13.8, D-07). Kunci konfigurasi PascalCase (D-05).
 */
return [
    // Subdomain Platform Pengelola, misal pengelola.{{app}}.id. Rute tenant tidak pernah dilayani di host ini.
    'Domain' => env('PENGELOLA_DOMAIN', 'pengelola.localhost'),

    // P-11 PGL-21: folder tujuan `status:ekspor` (status.json + index.html) untuk halaman status di hosting terpisah.
    // Kosong = tidak dijadwalkan; perintah tetap bisa dijalankan manual dengan argumen folder.
    'TujuanEksporStatus' => env('STATUS_TUJUAN_EKSPOR', ''),

    // Cookie sesi terpisah dari cookie tenant (BR-P01.4).
    'CookieSesi' => env('PENGELOLA_COOKIE_SESI', 'sesi_pengelola'),

    // BR-P01.2: sesi berakhir setelah 30 menit tidak aktif.
    'MenitSesiTidakAktif' => 30,

    // D-42: setelah kode 2FA benar, anggota boleh mempercayai browser-nya selama 90 hari (login cukup kata sandi).
    'HariPerangkatTepercaya' => 90,
    'CookiePerangkatTepercaya' => env('PENGELOLA_COOKIE_PERANGKAT', 'perangkat_tepercaya_pengelola'),

    // D-42: aksi berbahaya (tim, integrasi, tangguhkan tenant) meminta kode 2FA lagi bila kode terakhir > 15 menit lalu.
    'MenitKonfirmasiDuaFaktor' => 15,

    // P-01 langkah 3: undangan berlaku 48 jam.
    'JamBerlakuUndangan' => 48,

    // BR-P01.1: minimal 2 Super Admin aktif.
    'MinimalSuperAdminAktif' => 2,

    // Nama yang tampil di aplikasi autentikator (Google Authenticator, Aegis, dsb.).
    'NamaPenerbit2fa' => env('APP_NAME', 'Payoung').' Pengelola',
];
