<?php

declare(strict_types=1);

use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanTokoOnline;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * BR-17.3: aplikasi kasir hanya menampilkan menu & polling Pesanan toko online bila data awal membawa
 * `TokoOnline.Aktif = true`. Dua sakelar (pengaturan toko dan sakelar outlet) harus sama-sama hidup.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('BR-17.3 data awal kasir membawa status toko online', function (): void {
    it('toko aktif di outlet ini: data awal menyatakan TokoOnline.Aktif = true', function (): void {
        $k = BantuanTokoOnline::Siapkan($this);

        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->assertJsonPath('TokoOnline.Aktif', true);
    });

    it('pengaturan toko dimatikan atau sakelar outlet mati: data awal menyatakan false', function (): void {
        $k = BantuanTokoOnline::Siapkan($this);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        PengaturanTokoOnline::query()->update(['Aktif' => false]);
        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->assertJsonPath('TokoOnline.Aktif', false);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        PengaturanTokoOnline::query()->update(['Aktif' => true]);
        $k['Outlet']->forceFill(['TokoOnlineAktif' => false])->save();
        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->assertJsonPath('TokoOnline.Aktif', false);
    });
});
