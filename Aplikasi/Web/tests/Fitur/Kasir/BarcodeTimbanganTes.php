<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * v3.55 barcode timbangan (§9.3): pengaturan tenant di halaman pengaturan kasir (izin outlet.kelola), awalan hanya
 * 21–29 (20 = barcode internal produk), bawaan mati, tersimpan dengan audit, dan ikut data awal kasir.
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

describe('Barcode timbangan (v3.55)', function (): void {
    it('bawaan mati. Diaktifkan dengan awalan & nilai. Ikut data awal. Awalan 20 ditolak, tanpa izin 403', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')
            ->assertJsonPath('Pengaturan.BarcodeTimbangan', ['Aktif' => false, 'Awalan' => ['27'], 'Nilai' => 'Berat']);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Admin);
        $this->get('/kelola/kasir/pengaturan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('BarcodeTimbangan.Aktif', false));

        $alamat = '/kelola/kasir/pengaturan/barcode-timbangan';
        $this->put($alamat, ['Aktif' => true, 'Awalan' => ['20'], 'Nilai' => 'Berat'])->assertSessionHasErrors('Awalan.0');
        $this->put($alamat, ['Aktif' => true, 'Awalan' => [], 'Nilai' => 'Berat'])->assertSessionHasErrors('Awalan');
        $this->put($alamat, ['Aktif' => true, 'Awalan' => ['28', '21'], 'Nilai' => 'Harga'])
            ->assertSessionHasNoErrors()->assertRedirect('/kelola/kasir/pengaturan');

        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')
            ->assertJsonPath('Pengaturan.BarcodeTimbangan', ['Aktif' => true, 'Awalan' => ['21', '28'], 'Nilai' => 'Harga']);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(LogAudit::query()->where('Peristiwa', 'kasir.barcode-timbangan.ubah')->count())->toBe(1);

        // Kirim ulang tanpa perubahan: tidak ada audit baru.
        $this->put($alamat, ['Aktif' => true, 'Awalan' => ['21', '28'], 'Nilai' => 'Harga'])->assertRedirect();
        expect(LogAudit::query()->where('Peristiwa', 'kasir.barcode-timbangan.ubah')->count())->toBe(1);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->put($alamat, ['Aktif' => false, 'Awalan' => ['27'], 'Nilai' => 'Berat'])->assertForbidden();
    });
});
