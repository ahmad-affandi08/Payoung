<?php

declare(strict_types=1);

use App\Domain\Organisasi\Aksi\RapikanNamaGudangBawaan;
use App\Domain\Organisasi\Model\Gudang;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Nama bawaan tenant baru tidak lagi generik: kode outlet dari nama usaha (PembuatKodeOutlet) dan gudang tokonya
 * "Gudang {nama usaha}". Tenant lama yang masih "Gudang Outlet Utama" dirapikan satu kali; nama buatan pemilik tidak disentuh.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('tenant baru: kode outlet dari nama usaha dan gudang bernama sesuai usahanya', function (): void {
    ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant('Raja Sayur');
    BantuanOrganisasi::AturKonteks($tenant->Id);
    $gudang = Gudang::query()->sole();

    expect($gudang->Nama)->toBe('Gudang Raja Sayur')->and($gudang->Kode)->toBe('RSY');
});

it('merapikan hanya nama bawaan lama; kode gudang & nama buatan pemilik tidak berubah; aman diulang', function (): void {
    ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant('Warung Sembako Berkah');
    BantuanOrganisasi::AturKonteks($tenant->Id);
    $toko = Gudang::query()->sole();
    $toko->forceFill(['Nama' => RapikanNamaGudangBawaan::NAMA_LAMA])->save();
    $lain = Gudang::query()->create(['IdTenant' => $tenant->Id, 'IdOutlet' => $toko->IdOutlet, 'Kode' => 'GDB', 'Nama' => 'Gudang Belakang', 'Jenis' => $toko->Jenis]);

    expect(app(RapikanNamaGudangBawaan::class)->Jalankan($tenant->Id))->toBe(1);

    expect($toko->refresh()->Nama)->toBe('Gudang Warung Sembako Berkah')
        ->and($toko->Kode)->toBe($toko->Kode)
        ->and($lain->refresh()->Nama)->toBe('Gudang Belakang')
        ->and(app(RapikanNamaGudangBawaan::class)->Jalankan($tenant->Id))->toBe(0);
});
