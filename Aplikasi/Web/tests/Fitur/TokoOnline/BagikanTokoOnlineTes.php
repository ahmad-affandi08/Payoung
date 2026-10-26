<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Penjualan\Layanan\KodeStrukDigital;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Model\OverrideTenant;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanTokoOnline;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-110: cara pembeli tahu toko online outlet. Pembeli tidak menemukan toko sendiri, jadi pemilik mendapat tautan,
 * QR, dan poster cetak untuk dibagikan, dan struk digital pelanggan memuat tautan toko bila toko sedang menerima pesanan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('D-110 bagikan toko online', function (): void {
    it('halaman toko online memuat tautan dan QR. Poster memuat nama toko, tautan, QR, dan status aktif', function (): void {
        $k = BantuanTokoOnline::Siapkan($this);
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->get('/kelola/toko-online')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('TautanPublik', fn (string $url): bool => str_ends_with($url, $k['AlamatToko']))
            ->where('QrSvg', fn (string $svg): bool => str_contains($svg, '<svg')));

        $this->get('/kelola/toko-online/poster')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/TokoOnline/Poster')
            ->where('Tautan', fn (string $url): bool => str_ends_with($url, $k['AlamatToko']))
            ->where('QrSvg', fn (string $svg): bool => str_contains($svg, '<svg'))
            ->where('Aktif', true)
            ->where('NamaToko', fn (string $nama): bool => $nama !== ''));
    });

    it('poster butuh izin toko-online.kelola. Kasir ditolak', function (): void {
        $k = BantuanTokoOnline::Siapkan($this);
        $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);

        BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id)->get('/kelola/toko-online/poster')->assertForbidden();
    });

    it('struk digital memuat tautan toko online hanya bila outlet sedang menerima pesanan', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $produk, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        BantuanKasir::KirimRingkas($this, $k['Token'], [$item]);
        $kode = KodeStrukDigital::Buat($k['Tenant']->Id, $item['Uuid']);

        $this->get('/s/'.$kode)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Struk.TokoOnline', null));

        OverrideTenant::query()->create([
            'IdTenant' => $k['Tenant']->Id, 'Jenis' => JenisOverride::Fitur, 'Kunci' => 'kanal.toko-online',
            'BerakhirPada' => now()->addDays(30), 'Alasan' => 'Uji bagikan toko', 'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
        ]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $k['Outlet']->forceFill(['TokoOnlineAktif' => true])->save();
        PengaturanTokoOnline::query()->create(['Aktif' => true, 'MinimalPesanan' => '0.00']);

        $this->get('/s/'.$kode)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Struk.TokoOnline', fn (?string $url): bool => is_string($url) && str_contains($url, '?outlet='.$k['Outlet']->Uuid)));

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        PengaturanTokoOnline::query()->update(['Aktif' => false]);
        $this->get('/s/'.$kode)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Struk.TokoOnline', null));
    });
});
