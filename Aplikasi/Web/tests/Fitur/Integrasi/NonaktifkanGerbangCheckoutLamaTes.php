<?php

declare(strict_types=1);

use App\Domain\Integrasi\Model\GerbangPembayaranTenant;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanGerbangTenant;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-314, migrasi 2026_12_23_000270: gerbang QRIS toko pindah dari DOKU Checkout ke DOKU SNAP. Gerbang tersimpan tanpa
 * Merchant ID QRIS (isian khas SNAP) dinonaktifkan dan dikembalikan ke BelumDiuji, yang sudah berisian SNAP tidak
 * tersentuh. Kredensial tidak dihapus.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('D-314 gerbang Checkout lama dinonaktifkan, gerbang SNAP yang lengkap tidak tersentuh, kredensial tetap', function (): void {
    $lama = BantuanOrganisasi::BuatTenant('Toko Kelontong Berkah Solo');
    $baru = BantuanOrganisasi::BuatTenant('Warung Makan Sederhana Klaten');
    BantuanGerbangTenant::Aktifkan($lama['Tenant']->Id, pengaturan: ['IdKlien' => BantuanGerbangTenant::ID_KLIEN_UJI], kredensial: ['KunciRahasia' => BantuanGerbangTenant::KUNCI_RAHASIA_UJI]);
    BantuanGerbangTenant::Aktifkan($baru['Tenant']->Id);

    (require database_path('migrations/2026_12_23_000270_NonaktifkanGerbangTenantCheckoutLama.php'))->up();
    // Idempoten: dijalankan dua kali tidak mengubah hasil.
    (require database_path('migrations/2026_12_23_000270_NonaktifkanGerbangTenantCheckoutLama.php'))->up();

    BantuanOrganisasi::AturKonteks($lama['Tenant']->Id);
    $gerbangLama = GerbangPembayaranTenant::query()->sole();
    expect($gerbangLama->Aktif)->toBeFalse()
        ->and($gerbangLama->StatusUji->value)->toBe('BelumDiuji')
        ->and($gerbangLama->PesanUji)->toContain('DOKU SNAP')
        ->and($gerbangLama->Kredensial['KunciRahasia'])->toBe(BantuanGerbangTenant::KUNCI_RAHASIA_UJI);

    BantuanOrganisasi::AturKonteks($baru['Tenant']->Id);
    $gerbangBaru = GerbangPembayaranTenant::query()->sole();
    expect($gerbangBaru->Aktif)->toBeTrue()->and($gerbangBaru->StatusUji->value)->toBe('Berhasil');
});
