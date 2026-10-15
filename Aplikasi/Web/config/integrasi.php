<?php

declare(strict_types=1);

/*
 * Nilai integrasi platform (P-05). Diisi saat aplikasi berjalan oleh PenerapKonfigurasiIntegrasi dari konfigurasi
 * aktif di Platform Pengelola. Kunci PascalCase (D-05).
 */
return [
    // Cloudflare Turnstile untuk registrasi tenant (BR-00.4).
    'Turnstile' => [
        'KunciSitus' => null,
        'KunciRahasia' => null,
    ],

    // Email transaksional dari konfigurasi aktif P-05 (prasyarat registrasi di produksi, F-00).
    'EmailAktif' => false,

    // Disk `Objek` (S3-compatible) tersedia bila konfigurasi penyimpanan aktif.
    'PenyimpananObjekAktif' => false,

    // D-162: halaman & aksi kredensial gerbang pembayaran milik toko (tenant menempel Client ID/Secret DOKU sendiri).
    // Kosong (null) = ikut edisi: hanya edisi Lisensi. Isi `true` untuk membukanya kembali di SaaS.
    'GerbangTokoTampil' => null,

    // BR-P05.3: uji berkala integrasi aktif.
    'MenitUjiBerkala' => 60,
];
