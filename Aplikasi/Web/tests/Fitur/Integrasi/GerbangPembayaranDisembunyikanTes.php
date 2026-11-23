<?php

declare(strict_types=1);

use App\Domain\Integrasi\Model\GerbangPembayaranTenant;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Penjualan\BantuanGerbangTenant;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-162 lalu D-312: gerbang pembayaran milik toko (tenant menempel Client ID/Secret key DOKU sendiri) terbuka secara
 * bawaan di semua edisi. Sakelar `integrasi.GerbangTokoTampil` = false menyembunyikannya (halaman dialihkan, aksi
 * simpan/uji/aktifkan tidak ada, 404), dan itulah yang diuji di bawah ini. Gerbang yang sudah terlanjur aktif tetap
 * berjalan untuk kasir & toko online. Keadaan bawaan yang terbuka diuji di `GerbangPembayaranTenantTes`.
 */
beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    config(['integrasi.GerbangTokoTampil' => false]);
});

describe('D-312 keadaan bawaan: gerbang pembayaran milik toko terbuka di edisi SaaS', function (): void {
    it('sakelar bawaan terbuka. Halaman gerbang tampil, panduan awal menautkannya, dan menu tersedia', function (): void {
        config(['integrasi.GerbangTokoTampil' => true]);
        expect(config('integrasi.GerbangTokoTampil'))->toBeTrue();
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/pembayaran/gerbang')->assertOk();
        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/metode-pembayaran')
            ->assertInertia(fn ($h) => $h
                ->where('GerbangPembayaran.Aktif', false)
                ->where('GerbangPembayaran.Tautan', '/kelola/pembayaran/gerbang')
                ->where('GerbangPembayaran.NamaMenu', 'Gerbang pembayaran')
                ->where('GerbangPembayaran.Petunjuk', fn (string $teks) => str_contains($teks, 'akun merchant toko') && ! str_contains($teks, 'foto KTP')));
    });
});

describe('D-162 gerbang pembayaran milik toko disembunyikan lewat sakelar integrasi.GerbangTokoTampil=false', function (): void {
    it('alamat lama dialihkan ke Metode pembayaran (Aktivasi QRIS juga disembunyikan, D-190)', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/pembayaran/gerbang')
            ->assertRedirect('/kelola/panduan-awal/metode-pembayaran');
    });

    it('alamat gerbang dialihkan ke Aktivasi QRIS bila halaman itu dibuka kembali lewat sakelar', function (): void {
        config(['integrasi.AktivasiQrisTampil' => true]);
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/pembayaran/gerbang')
            ->assertRedirect('/kelola/pembayaran/aktivasi-qris');
    });

    it('aksi simpan, uji, aktifkan, dan nonaktifkan tidak dilayani (404)', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();

        foreach (['', '/uji', '/aktifkan', '/nonaktifkan'] as $akhiran) {
            BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/pembayaran/gerbang'.$akhiran, [])
                ->assertNotFound();
        }

    });

    it('panduan awal mengarahkan ke Metode pembayaran (QRIS statis bernominal otomatis), bukan ke gerbang atau DOKU', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/metode-pembayaran')
            ->assertInertia(fn ($h) => $h
                ->where('GerbangPembayaran.Aktif', false)
                ->where('GerbangPembayaran.Tautan', '/kelola/panduan-awal/metode-pembayaran')
                ->where('GerbangPembayaran.NamaMenu', 'Metode pembayaran')
                ->where('GerbangPembayaran.Petunjuk', fn (string $teks) => str_contains($teks, 'QRIS statis') && ! str_contains($teks, 'foto KTP')));
    });

    it('gerbang yang sudah aktif sebelumnya tetap dipakai kasir', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        BantuanGerbangTenant::Aktifkan($tenant->Id);

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/metode-pembayaran')
            ->assertInertia(fn ($h) => $h->where('GerbangPembayaran.Aktif', true));

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(GerbangPembayaranTenant::query()->where('Aktif', true)->count())->toBe(1);
    });
});

describe('D-190 halaman Aktivasi QRIS (DOKU) disembunyikan', function (): void {
    it('alamat Aktivasi QRIS dialihkan ke Metode pembayaran dan aksi tulisnya 404', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/pembayaran/aktivasi-qris')
            ->assertRedirect('/kelola/panduan-awal/metode-pembayaran');

        foreach (['/draf', '/kirim', '/batal'] as $akhiran) {
            BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/pembayaran/aktivasi-qris'.$akhiran, [])
                ->assertNotFound();
        }
    });

    it('dibuka kembali lewat sakelar integrasi.AktivasiQrisTampil', function (): void {
        config(['integrasi.AktivasiQrisTampil' => true]);
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/pembayaran/aktivasi-qris')
            ->assertOk();
    });
});
