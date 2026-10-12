<?php

declare(strict_types=1);

/*
 * Pendaftaran tenant & langganan (F-00, BR-00.3, BR-00.4, BR-00.6). Kunci PascalCase (D-05).
 */
return [
    // BR-00.6: paket trial bila calon tenant tidak memilih paket di halaman harga.
    'KodePaketTrialBawaan' => env('TENANT_PAKET_TRIAL', 'PRO'),

    // BR-00.3: paket tujuan saat trial berakhir tanpa pembayaran.
    'KodePaketGratis' => env('TENANT_PAKET_GRATIS', 'GRATIS'),

    // D-111 (BR-P04.3): server menolak rute fitur yang tidak ada di paket/add-on/override tenant (`PetaFiturRute`).
    // Matikan sementara hanya selama masa peralihan; pengecualian per tenant lewat override fitur di konsol.
    'PenegakanFiturPaket' => (bool) env('TENANT_PENEGAKAN_FITUR_PAKET', true),

    // BR-00.4: percobaan registrasi per IP per jam.
    'BatasRegistrasiPerJam' => 5,

    // Audit PAY-P1-03: tolak penulisan baris dengan `IdTenant` berbeda dari konteks tenant aktif. Bawaan mati (hanya
    // dicatat kritis di log); nyalakan di produksi (`TENANT_TOLAK_ID_BERBEDA=true`) setelah log bersih.
    'TolakIdTenantBerbeda' => (bool) env('TENANT_TOLAK_ID_BERBEDA', false),

    // BR-00.5: masa berlaku tautan verifikasi email.
    'JamBerlakuVerifikasiEmail' => 24,

    // BR-00.5: tolak email yang domainnya tidak punya server surat (cek DNS). Mati di lingkungan test (tanpa jaringan).
    'PeriksaDnsEmail' => (bool) env('TENANT_PERIKSA_DNS_EMAIL', env('APP_ENV') !== 'testing'),

    // BR-00.2: slug yang bentrok dengan rute sistem (§13.6).
    'SlugTerlarang' => [
        'daftar', 'masuk', 'keluar', 'lupa-kata-sandi', 'verifikasi-email', 'pilih-tenant', 'kelola', 'unduh',
        'internal', 'api', 'webhook', 'laporan-csp', 'sehat', 's', 'mitra', 'pengelola', 'harga', 'bantuan', 'legal',
        // X7 bagian 3: portal dokumentasi pengembang `/pengembang`.
        'pengembang',
        // Auth tenant: rute atur ulang kata sandi (BR-00.9).
        'atur-ulang-kata-sandi',
        // F-02: tautan undangan anggota.
        'undangan',
        // BR-00.5: tautan konfirmasi ganti email.
        'ganti-email',
    ],

    // F-01 langkah 1: logo usaha di disk privat (tanpa storage:link, tidak bisa di-hotlink).
    'DiskLogo' => env('TENANT_DISK_LOGO', 'local'),
    'UkuranMaksimalLogoKb' => 1024,
    'EkstensiLogo' => ['png', 'jpg', 'jpeg', 'webp'],

    // Audit E5 (UU PDP): ekspor data lengkap usaha (ZIP berisi CSV per tabel) di disk privat, berlaku beberapa hari.
    'DiskEkspor' => env('TENANT_DISK_EKSPOR', 'local'),
    'HariBerlakuEkspor' => 7,
    'JamJedaEkspor' => 24,

    // D-124 Pusat Unduhan: ekspor laporan besar disiapkan di latar belakang dan disimpan di disk privat beberapa hari.
    'DiskUnduhan' => env('TENANT_DISK_UNDUHAN', 'local'),
    'HariBerlakuUnduhan' => 7,
    'MaksUnduhanAktif' => 3,
    'MaksBarisEksporBesar' => 100000,
    'MenitBatasProsesUnduhan' => 60,

    // Audit E5 / P-07 (UU PDP): masa tunggu permintaan penghapusan data (bisa dibatalkan Owner) dan lama lampiran pembukuan
    // dipertahankan setelah akun ditutup (ketentuan perpajakan: dokumen pembukuan disimpan 10 tahun).
    'HariTungguPenghapusan' => 14,
    'TahunRetensiPembukuan' => 10,
];
