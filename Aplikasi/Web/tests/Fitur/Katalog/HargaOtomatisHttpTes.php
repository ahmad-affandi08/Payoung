<?php

declare(strict_types=1);

use App\Domain\Katalog\Harga\Model\AturanHargaOtomatis;
use App\Domain\Katalog\Harga\Model\PengaturanHargaOtomatis;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-266 halaman dan rute harga otomatis: izin produk.harga.ubah, fitur harga.otomatis (paket Pro ke atas), kontrak props
 * halaman, dan pratinjau "Harga dari harga beli".
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function TenantHargaOtomatis(string $kodePaket): array
{
    $t = BantuanOrganisasi::BuatTenant('Toko Bangunan Sumber Makmur Klaten');
    BantuanFitur::PindahPaket($t['Tenant']->Id, $kodePaket);

    return $t;
}

describe('paket Pro ke atas', function (): void {
    it('halaman memuat pengaturan, aturan, usulan, dan pilihan', function (): void {
        $t = TenantHargaOtomatis('PRO');
        $minuman = BantuanKatalog::BuatKategori('Minuman');
        BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

        $this->get('/kelola/produk/harga-otomatis')->assertOk()->assertInertia(fn (Assert $h) => $h
            ->component('Kelola/Produk/HargaOtomatis')
            ->where('Pengaturan.Aktif', false)
            ->where('Pengaturan.BatasPerubahanPersen', '20.00')
            ->where('Izin.Ubah', true)
            ->has('Aturan', 0)
            ->has('Usulan', 0)
            ->has('OpsiKategori', 1)
            ->where('OpsiKategori.0.Uuid', $minuman->Uuid)
            ->where('OpsiPembulatan', [0, 100, 500, 1000])
            ->has('OpsiCakupan', 3)
            ->has('OpsiArah', 2));
    });

    it('menyalakan saklar, menyimpan dan menghapus aturan', function (): void {
        $t = TenantHargaOtomatis('PRO');
        BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

        $this->put('/kelola/produk/harga-otomatis/pengaturan', ['Aktif' => true, 'BatasPerubahanPersen' => '25'])
            ->assertRedirect('/kelola/produk/harga-otomatis')->assertSessionHasNoErrors();
        $this->post('/kelola/produk/harga-otomatis/aturan', ['Cakupan' => 'Semua', 'PersenUntung' => '20', 'Pembulatan' => 500, 'Arah' => 'NaikTurun', 'Aktif' => true])
            ->assertRedirect('/kelola/produk/harga-otomatis')->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $aturan = AturanHargaOtomatis::query()->sole();
        expect(PengaturanHargaOtomatis::query()->sole()->Aktif)->toBeTrue()
            ->and((string) PengaturanHargaOtomatis::query()->sole()->BatasPerubahanPersen)->toBe('25.00')
            ->and($aturan->Pembulatan)->toBe(500);

        $this->get('/kelola/produk/harga-otomatis')->assertInertia(fn (Assert $h) => $h
            ->where('Pengaturan.Aktif', true)
            ->has('Aturan', 1)
            ->where('Aturan.0.Sasaran', 'Semua produk berstok'));

        $this->delete("/kelola/produk/harga-otomatis/aturan/{$aturan->Uuid}")->assertRedirect('/kelola/produk/harga-otomatis')->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(AturanHargaOtomatis::query()->count())->toBe(0);
    });

    it('isian salah ditolak dengan pesan di bidangnya', function (): void {
        $t = TenantHargaOtomatis('PRO');
        BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

        $this->put('/kelola/produk/harga-otomatis/pengaturan', ['Aktif' => true, 'BatasPerubahanPersen' => '500'])->assertSessionHasErrors('BatasPerubahanPersen');
        $this->post('/kelola/produk/harga-otomatis/aturan', ['Cakupan' => 'Kategori', 'PersenUntung' => '20', 'Pembulatan' => 500, 'Arah' => 'NaikTurun', 'Aktif' => true])->assertSessionHasErrors('UuidKategori');
        $this->post('/kelola/produk/harga-otomatis/aturan', ['Cakupan' => 'Semua', 'PersenUntung' => 'banyak', 'Pembulatan' => 500, 'Arah' => 'NaikTurun', 'Aktif' => true])->assertSessionHasErrors('PersenUntung');
        $this->post('/kelola/produk/harga-otomatis/aturan', ['Cakupan' => 'Semua', 'PersenUntung' => '20', 'Pembulatan' => 250, 'Arah' => 'NaikTurun', 'Aktif' => true])->assertSessionHasErrors('Pembulatan');
    });

    it('pratinjau harga dari harga beli mengembalikan baris dan ringkasan', function (): void {
        $t = TenantHargaOtomatis('PRO');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $produk = BantuanKatalog::BuatProduk([], '11000.00');

        $this->postJson('/kelola/produk/harga-dari-modal/pratinjau', ['Uuid' => [$produk->Uuid], 'PersenUntung' => '20', 'Pembulatan' => 0])
            ->assertOk()
            ->assertJsonPath('Data.Baris.0.Status', 'TanpaHargaBeli')
            ->assertJsonPath('Data.Ringkasan.TanpaHargaBeli', 1);
        $this->postJson('/kelola/produk/harga-dari-modal/pratinjau', ['Uuid' => [], 'PersenUntung' => '20', 'Pembulatan' => 0])->assertUnprocessable();
    });
});

describe('di luar paket atau izin', function (): void {
    it('Starter: halaman tetap bisa dibuka, tetapi mengubah pengaturan, aturan, dan harga massal ditolak', function (): void {
        $t = TenantHargaOtomatis('STARTER');
        BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

        $this->get('/kelola/produk/harga-otomatis')->assertOk();
        $this->put('/kelola/produk/harga-otomatis/pengaturan', ['Aktif' => true, 'BatasPerubahanPersen' => '20'])->assertSessionHasErrors('Umum');
        $this->post('/kelola/produk/harga-otomatis/aturan', ['Cakupan' => 'Semua', 'PersenUntung' => '20', 'Pembulatan' => 0, 'Arah' => 'NaikTurun', 'Aktif' => true])->assertSessionHasErrors('Umum');
        $this->postJson('/kelola/produk/harga-dari-modal/pratinjau', ['Uuid' => [], 'PersenUntung' => '20', 'Pembulatan' => 0])->assertForbidden();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(PengaturanHargaOtomatis::query()->count())->toBe(0)
            ->and(AturanHargaOtomatis::query()->count())->toBe(0);
    });

    it('Bisnis juga memuat fitur ini', function (): void {
        $t = TenantHargaOtomatis('BISNIS');
        BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

        $this->put('/kelola/produk/harga-otomatis/pengaturan', ['Aktif' => true, 'BatasPerubahanPersen' => '20'])->assertSessionHasNoErrors();
    });

    it('kasir tidak boleh mengubah harga otomatis', function (): void {
        $t = TenantHargaOtomatis('PRO');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Kasir);

        $this->put('/kelola/produk/harga-otomatis/pengaturan', ['Aktif' => true, 'BatasPerubahanPersen' => '20'])->assertForbidden();
        $this->post('/kelola/produk/harga-otomatis/jalankan')->assertForbidden();
    });
});
