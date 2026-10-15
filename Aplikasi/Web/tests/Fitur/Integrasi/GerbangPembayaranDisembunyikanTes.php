<?php

declare(strict_types=1);

use App\Domain\Integrasi\Model\GerbangPembayaranTenant;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Penjualan\BantuanGerbangTenant;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-162: di edisi SaaS tenant tidak lagi diminta mendaftar di dasbor DOKU dan menempel Client ID/Secret key. Halaman
 * gerbang dialihkan ke Aktivasi QRIS (foto KTP), dan aksi simpan/uji/aktifkan tidak ada. Edisi Lisensi tetap memakainya
 * (lihat `GerbangPembayaranTenantTes`). Gerbang yang sudah terlanjur aktif tetap berjalan untuk kasir & toko online.
 */
beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('D-162 gerbang pembayaran milik toko disembunyikan di edisi SaaS', function (): void {
    it('alamat lama dialihkan ke Aktivasi QRIS', function (): void {
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

    it('panduan awal mengarahkan ke Aktivasi QRIS, bukan ke gerbang', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/metode-pembayaran')
            ->assertInertia(fn ($h) => $h
                ->where('GerbangPembayaran.Aktif', false)
                ->where('GerbangPembayaran.Tautan', '/kelola/pembayaran/aktivasi-qris')
                ->where('GerbangPembayaran.NamaMenu', 'Aktivasi QRIS')
                ->where('GerbangPembayaran.Petunjuk', fn (string $teks) => str_contains($teks, 'foto KTP')));
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
