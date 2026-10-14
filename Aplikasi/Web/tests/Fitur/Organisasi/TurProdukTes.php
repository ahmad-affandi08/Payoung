<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\KodeTurProduk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\TurProduk;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * PLT-12: tur produk interaktif. Server hanya mencatat hasil tur per pengguna (Selesai/Dilewati), idempoten, bisa diulang,
 * terisolasi per pengguna dan per tenant, dan Beranda memuat daftar tur yang sudah dilihat.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('Beranda memuat tur yang sudah dilihat pengguna; kosong untuk pengguna baru', function (): void {
    $k = BantuanPenjualan::Siapkan($this);
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

    $this->get('/kelola')->assertInertia(fn (AssertableInertia $h) => $h->where('TurDilihat', []));
});

it('mencatat tur dilewati lalu selesai (idempoten: satu baris), dan Beranda mencerminkannya', function (): void {
    $k = BantuanPenjualan::Siapkan($this);
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

    $this->post('/kelola/tur/dashboard.v1', ['Status' => 'Dilewati'])->assertRedirect()->assertSessionHasNoErrors();
    $this->post('/kelola/tur/dashboard.v1', ['Status' => 'Dilewati'])->assertRedirect();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(TurProduk::query()->count())->toBe(1)->and(TurProduk::query()->first()?->Status->value)->toBe('Dilewati');

    $this->post('/kelola/tur/dashboard.v1', ['Status' => 'Selesai'])->assertRedirect();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(TurProduk::query()->count())->toBe(1)->and(TurProduk::query()->first()?->Status->value)->toBe('Selesai');

    $this->get('/kelola')->assertInertia(fn (AssertableInertia $h) => $h->where('TurDilihat', [KodeTurProduk::Dashboard->value]));
});

it('ulangi tur menghapus catatan sehingga ditawarkan lagi', function (): void {
    $k = BantuanPenjualan::Siapkan($this);
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->post('/kelola/tur/dashboard.v1', ['Status' => 'Selesai']);

    $this->delete('/kelola/tur/dashboard.v1')->assertRedirect();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    expect(TurProduk::query()->count())->toBe(0);
    $this->get('/kelola')->assertInertia(fn (AssertableInertia $h) => $h->where('TurDilihat', []));
});

it('kode tur tidak dikenal dijawab 404 dan status ngawur ditolak tanpa mencatat apa pun', function (): void {
    $k = BantuanPenjualan::Siapkan($this);
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

    $this->post('/kelola/tur/tidak-ada.v1', ['Status' => 'Selesai'])->assertNotFound();
    $this->delete('/kelola/tur/tidak-ada.v1')->assertNotFound();
    $this->post('/kelola/tur/dashboard.v1', ['Status' => 'Ngawur'])->assertSessionHasErrors('Status');
    $this->post('/kelola/tur/dashboard.v1', [])->assertSessionHasErrors('Status');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    expect(TurProduk::query()->count())->toBe(0);
});

it('hasil tur milik pengguna sendiri: pengguna lain di tenant sama dan tenant lain tidak ikut terpengaruh', function (): void {
    $k = BantuanPenjualan::Siapkan($this);
    $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Admin);
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->post('/kelola/tur/dashboard.v1', ['Status' => 'Selesai']);

    BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id);
    $this->get('/kelola')->assertInertia(fn (AssertableInertia $h) => $h->where('TurDilihat', []));
    // Ulangi oleh pengguna lain tidak menyentuh catatan Pemilik.
    $this->delete('/kelola/tur/dashboard.v1')->assertRedirect();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(TurProduk::query()->where('IdPengguna', $k['Pemilik']->Id)->count())->toBe(1);

    $lain = BantuanOrganisasi::BuatTenant('Toko Lain');
    BantuanOrganisasi::AturKonteks($lain['Tenant']->Id);
    expect(TurProduk::query()->count())->toBe(0);
});

it('wajib masuk: tanpa sesi diarahkan ke halaman masuk', function (): void {
    $this->post('/kelola/tur/dashboard.v1', ['Status' => 'Selesai'])->assertRedirect();
    expect(TurProduk::query()->withoutGlobalScopes()->count())->toBe(0);
});
