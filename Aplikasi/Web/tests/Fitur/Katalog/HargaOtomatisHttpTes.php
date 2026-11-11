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
            ->where('Umum.PersenUntung', '20')
            ->where('Umum.Pembulatan', 500)
            ->where('Umum.Arah', 'NaikTurun')
            ->has('Pengecualian', 0)
            ->has('Usulan', 0)
            ->has('OpsiKategori', 1)
            ->where('OpsiKategori.0.Uuid', $minuman->Uuid)
            ->where('OpsiPembulatan', [0, 100, 500, 1000]));
    });

    it('satu simpan menyalakan saklar, membuat untung utama, dan menyimpan untung khusus sekaligus', function (): void {
        $t = TenantHargaOtomatis('PRO');
        $minuman = BantuanKatalog::BuatKategori('Minuman');
        BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

        $this->put('/kelola/produk/harga-otomatis/pengaturan', [
            'Aktif' => true, 'BatasPerubahanPersen' => '25', 'PersenUntung' => '20', 'Pembulatan' => 500, 'Arah' => 'NaikTurun',
            'Khusus' => [['Cakupan' => 'Kategori', 'UuidKategori' => $minuman->Uuid, 'PersenUntung' => '35']],
        ])->assertRedirect('/kelola/produk/harga-otomatis')->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(PengaturanHargaOtomatis::query()->sole()->Aktif)->toBeTrue()
            ->and((string) PengaturanHargaOtomatis::query()->sole()->BatasPerubahanPersen)->toBe('25.00')
            ->and(AturanHargaOtomatis::query()->count())->toBe(2);

        $this->get('/kelola/produk/harga-otomatis')->assertInertia(fn (Assert $h) => $h
            ->where('Pengaturan.Aktif', true)
            ->where('Umum.PersenUntung', '20.0000')
            ->has('Pengecualian', 1)
            ->where('Pengecualian.0.Sasaran', 'Minuman')
            ->where('Pengecualian.0.PersenUntung', '35.0000'));

        // Simpan lagi dengan daftar kosong menghapus untung khusus.
        $this->put('/kelola/produk/harga-otomatis/pengaturan', [
            'Aktif' => true, 'BatasPerubahanPersen' => '25', 'PersenUntung' => '20', 'Pembulatan' => 500, 'Arah' => 'NaikTurun', 'Khusus' => [],
        ])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(AturanHargaOtomatis::query()->count())->toBe(1);
    });

    it('isian salah ditolak dengan pesan di bidangnya', function (): void {
        $t = TenantHargaOtomatis('PRO');
        BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

        $dasar = ['Aktif' => true, 'BatasPerubahanPersen' => '20', 'PersenUntung' => '20', 'Pembulatan' => 500, 'Arah' => 'NaikTurun', 'Khusus' => []];
        $this->put('/kelola/produk/harga-otomatis/pengaturan', [...$dasar, 'BatasPerubahanPersen' => '500'])->assertSessionHasErrors('BatasPerubahanPersen');
        $this->put('/kelola/produk/harga-otomatis/pengaturan', [...$dasar, 'PersenUntung' => 'banyak'])->assertSessionHasErrors('PersenUntung');
        $this->put('/kelola/produk/harga-otomatis/pengaturan', [...$dasar, 'Pembulatan' => 250])->assertSessionHasErrors('Pembulatan');
        $this->put('/kelola/produk/harga-otomatis/pengaturan', ['Aktif' => true])->assertSessionHasErrors(['BatasPerubahanPersen', 'PersenUntung', 'Pembulatan', 'Arah', 'Khusus']);
        $this->put('/kelola/produk/harga-otomatis/pengaturan', [...$dasar, 'Khusus' => [['Cakupan' => 'Kategori', 'UuidKategori' => null, 'PersenUntung' => '30']]])->assertSessionHasErrors('Khusus.0.UuidKategori');
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
        $this->postJson('/kelola/produk/harga-dari-modal/pratinjau', ['Uuid' => [], 'PersenUntung' => '20', 'Pembulatan' => 0])->assertForbidden();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(PengaturanHargaOtomatis::query()->count())->toBe(0)
            ->and(AturanHargaOtomatis::query()->count())->toBe(0);
    });

    it('Bisnis juga memuat fitur ini', function (): void {
        $t = TenantHargaOtomatis('BISNIS');
        BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

        $this->put('/kelola/produk/harga-otomatis/pengaturan', ['Aktif' => true, 'BatasPerubahanPersen' => '20', 'PersenUntung' => '20', 'Pembulatan' => 0, 'Arah' => 'HanyaNaik', 'Khusus' => []])->assertSessionHasNoErrors();
    });

    it('kasir tidak boleh mengubah harga otomatis', function (): void {
        $t = TenantHargaOtomatis('PRO');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Kasir);

        $this->put('/kelola/produk/harga-otomatis/pengaturan', ['Aktif' => true, 'BatasPerubahanPersen' => '20'])->assertForbidden();
        $this->post('/kelola/produk/harga-otomatis/jalankan')->assertForbidden();
    });
});
