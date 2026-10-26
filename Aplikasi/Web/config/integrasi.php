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

    // D-216: pengirim email kampanye (domain terpisah dari email transaksional) dari integrasi `EmailKampanye`.
    'EmailKampanyeAktif' => false,

    // Disk `Objek` (S3-compatible) tersedia bila konfigurasi penyimpanan aktif.
    'PenyimpananObjekAktif' => false,

    // D-162: halaman & aksi kredensial gerbang pembayaran milik toko (tenant menempel Client ID/Secret DOKU sendiri).
    // Kosong (null) = ikut edisi: hanya edisi Lisensi. Isi `true` untuk membukanya kembali di SaaS.
    'GerbangTokoTampil' => null,

    // D-190: halaman Aktivasi QRIS lewat DOKU (KYB merchant, D-83) disembunyikan di SaaS karena DOKU hanya dipakai
    // untuk tagihan langganan; QRIS toko = QRIS statis milik toko yang diberi nominal di kasir (D-189). Isi `true`
    // untuk membukanya kembali (kode, rute, dan data pendaftaran tetap ada). Tidak berlaku di edisi Lisensi.
    'AktivasiQrisTampil' => false,

    // D-179: penyedia pembayaran tagihan langganan Payoung sendiri (`PembuatPenyediaTagihan::PENYEDIA`). Mengganti
    // penyedia = tulis adaptornya, daftarkan, lalu ubah nilai ini (`Panduan/PenyediaPembayaran.md`).
    'PenyediaTagihan' => 'Doku',

    // D-179: penyedia pendaftaran merchant (KYB) untuk aktivasi QRIS tenant (`PembuatPendaftarMerchant::PENYEDIA`).
    'PenyediaPendaftaranMerchant' => 'Doku',

    // BR-P05.3: uji berkala integrasi aktif.
    'MenitUjiBerkala' => 60,
];
