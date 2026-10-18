<?php

declare(strict_types=1);

use App\Domain\Integrasi\Billing\AdaptorTagihanDoku;
use App\Domain\Integrasi\Billing\GerbangBillingPlatform;
use App\Domain\Integrasi\GerbangPembayaran\Adaptor\AdaptorDoku;
use App\Domain\Integrasi\GerbangPembayaran\ProtokolDoku;
use App\Domain\Integrasi\Merchant\AdaptorPendaftarDoku;
use App\Domain\Integrasi\Merchant\GalatPartnerDoku;
use App\Domain\Integrasi\Merchant\HasilBisnisDoku;
use App\Domain\Integrasi\Merchant\KlienPartnerDoku;
use App\Domain\Integrasi\Merchant\PembentukBadanPendaftaranDoku;
use App\Domain\Integrasi\Snap\GerbangBillingSnap;
use App\Domain\Integrasi\Snap\KlienSnap;

/*
 * D-179: sistem mudah berganti penyedia pembayaran. Kelas khusus DOKU hanya dipakai di dalam `Domain/Integrasi`
 * (tempat adaptornya), ditambah pengujian koneksi P-05 yang memang per penyedia. Semua kode lain (aksi tenant,
 * rekonsiliasi, kontroler, perintah konsol, konsol pengelola) bicara lewat colokan `PenyediaTagihanPlatform`,
 * `PendaftarMerchantPembayaran`, dan `GerbangPembayaran`. Kalau tes ini gagal, jangan melonggarkannya: pakai colokannya,
 * atau tambahkan kemampuan yang kurang ke colokan itu. Cara menambah penyedia: `Panduan/PenyediaPembayaran.md`.
 * File penjaga: hanya manusia atau D-17 yang mengubah.
 */

$khususDoku = [
    GerbangBillingPlatform::class,
    AdaptorTagihanDoku::class,
    GerbangBillingSnap::class,
    KlienSnap::class,
    KlienPartnerDoku::class,
    AdaptorPendaftarDoku::class,
    PembentukBadanPendaftaranDoku::class,
    HasilBisnisDoku::class,
    GalatPartnerDoku::class,
    AdaptorDoku::class,
    ProtokolDoku::class,
];

foreach ($khususDoku as $kelas) {
    arch("{$kelas} hanya dipakai di Domain/Integrasi dan penguji koneksi P-05 (D-179)")
        ->expect($kelas)
        ->toOnlyBeUsedIn([
            'App\Domain\Integrasi',
            'App\Domain\Pengelola\Integrasi\Penguji\PengujiDokuBilling',
            'App\Domain\Pengelola\Integrasi\Penguji\PengujiDokuPartner',
            'App\Providers',
        ]);
}

arch('aksi tenant, pengelola, kontroler, dan perintah tidak mengimpor adaptor atau klien khusus penyedia')
    ->expect([
        'App\Domain\Tenant',
        'App\Domain\Pengelola\Tagihan',
        'App\Domain\Pengelola\Tenant',
        'App\Domain\Penjualan',
        'App\Http\Kontroler',
        'App\Console',
    ])
    ->not->toUse([
        'App\Domain\Integrasi\Billing\GerbangBillingPlatform',
        'App\Domain\Integrasi\Billing\AdaptorTagihanDoku',
        'App\Domain\Integrasi\Snap\GerbangBillingSnap',
        'App\Domain\Integrasi\Snap\KlienSnap',
        'App\Domain\Integrasi\Merchant\KlienPartnerDoku',
        'App\Domain\Integrasi\Merchant\AdaptorPendaftarDoku',
        'App\Domain\Integrasi\GerbangPembayaran\Adaptor',
    ]);

arch('adaptor penyedia mengimplementasikan colokannya')
    ->expect(AdaptorTagihanDoku::class)->toImplement('App\Domain\Integrasi\Billing\PenyediaTagihanPlatform')
    ->and(AdaptorPendaftarDoku::class)->toImplement('App\Domain\Integrasi\Merchant\PendaftarMerchantPembayaran')
    ->and(AdaptorDoku::class)->toImplement('App\Domain\Integrasi\GerbangPembayaran\GerbangPembayaran');
