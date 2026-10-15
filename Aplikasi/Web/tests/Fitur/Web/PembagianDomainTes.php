<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-20 (PRD §14.1): domain pemasaran (`DOMAIN_PEMASARAN`), tenant (`DOMAIN_TENANT`), dan Platform Pengelola
 * (`PENGELOLA_DOMAIN`) cukup diatur lewat .env. Pemasaran hanya melayani beranda, legal, dan kompatibilitas perangkat;
 * sisanya dialihkan ke domain tenant dengan jalur yang sama. Tanpa pengaturan, semua di satu host seperti sebelumnya.
 */

beforeEach(function (): void {
    config([
        'app.url' => 'https://dashboard.payoung.test',
        'domain.Pemasaran' => 'payoung.test',
        'domain.Tenant' => 'dashboard.payoung.test',
    ]);
});

describe('D-20 pembagian domain', function (): void {
    it('domain pemasaran: beranda dilayani dengan tautan masuk & daftar ke domain tenant; legal & kompatibilitas tetap dilayani', function (): void {
        $this->get('https://payoung.test/')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Situs/Halaman')
            ->where('Situs.TombolMasuk.Tautan', 'https://dashboard.payoung.test/masuk')
            ->where('Situs.TombolDaftar.Tautan', 'https://dashboard.payoung.test/daftar'));
        $this->get('https://payoung.test/kompatibilitas-perangkat')->assertOk();
    });

    it('domain pemasaran: Pusat Bantuan, portal pengembang, dan status dilayani langsung tanpa pengalihan atau noindex (Search Console)', function (): void {
        foreach (['/pusat-bantuan', '/pengembang', '/status'] as $jalur) {
            $respons = $this->get('https://payoung.test'.$jalur);
            $respons->assertOk();
            expect($respons->headers->get('X-Robots-Tag'))->toBeNull();
        }
    });

    it('domain pemasaran: rute tenant dialihkan ke domain tenant dengan jalur & query sama (GET 302, POST 307)', function (): void {
        $this->get('https://payoung.test/masuk')->assertRedirect('https://dashboard.payoung.test/masuk');
        $this->get('https://payoung.test/kelola/penjualan?halaman=2')->assertRedirect('https://dashboard.payoung.test/kelola/penjualan?halaman=2');
        $this->get('https://payoung.test/s/1a.01K5AAAAAAAAAAAAAAAAAAAAAA')->assertRedirect('https://dashboard.payoung.test/s/1a.01K5AAAAAAAAAAAAAAAAAAAAAA');
        $this->post('https://payoung.test/masuk', ['Email' => 'a@b.id'])
            ->assertStatus(307)
            ->assertHeader('Location', 'https://dashboard.payoung.test/masuk');
    });

    it('domain tenant: halaman masuk dilayani, beranda dialihkan ke back-office (tamu lalu ke masuk)', function (): void {
        $this->get('https://dashboard.payoung.test/masuk')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Autentikasi/Masuk')
            ->where('UrlPemasaran', 'https://payoung.test/'));
        $this->get('https://dashboard.payoung.test/')->assertRedirect(route('kelola.beranda'));
        $this->get('https://dashboard.payoung.test/kelola')->assertRedirect();
    });

    it('domain tenant: pengguna masuk membuka back-office; domain pengelola tidak melayani rute tenant', function (): void {
        BantuanPendaftaran::SiapkanPrasyarat();
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Toko Domain Terpisah');
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $this->get('https://dashboard.payoung.test/kelola')->assertOk();
        // Rute pengelola didaftarkan saat boot dari PENGELOLA_DOMAIN (consol.payoung.id di produksi).
        $this->get(BantuanPengelola::Url('/kelola'))->assertNotFound();
        $this->get(BantuanPengelola::Url('/masuk'))->assertOk();
    });

    it('tanpa domain diatur: semua dilayani di satu host dengan tautan relatif', function (): void {
        config(['domain.Pemasaran' => null, 'domain.Tenant' => null]);

        $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Situs.TombolMasuk.Tautan', '/masuk')
            ->where('Situs.TombolDaftar.Tautan', '/daftar'));
        $this->get('/masuk')->assertOk();
    });
});
