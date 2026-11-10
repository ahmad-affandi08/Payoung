<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Outlet;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-263: pajak & biaya layanan per outlet bisa diubah dari Atur kasir (dulu hanya lewat langkah Pajak di Panduan awal).
 * Dibuktikan: halaman menampilkan nilai tersimpan, perubahan tersimpan dengan audit, biaya layanan bisa dimatikan,
 * PBJT butuh kota, batas persen, izin outlet.kelola, dan outlet yang tidak dikenal tidak ditemukan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->k = BantuanKasir::Siapkan($this);
    BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
    Outlet::query()->whereKey($this->k['Outlet']->Id)->update([
        'ProfilPajak' => ['Pkp' => false, 'PungutPbjt' => false, 'BiayaLayanan' => ['Aktif' => true, 'Persen' => '2.00'], 'HargaTermasukPajak' => false],
    ]);
    BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id, PeranTenantBawaan::Admin);
});

it('menampilkan nilai yang tersimpan di outlet, termasuk biaya layanan yang sedang aktif', function (): void {
    $this->get('/kelola/kasir/pajak')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Kasir/Pajak')
        ->where('UuidOutlet', $this->k['Outlet']->Uuid)
        ->where('Nilai.BiayaLayananAktif', true)
        ->where('Nilai.PersenBiayaLayanan', '2.00')
        ->where('Nilai.PungutPbjt', false)
        ->where('Nilai.HargaTermasukPajak', false)
        ->has('OpsiOutlet', 1));
});

it('mematikan biaya layanan: tersimpan, diaudit, dan kirim ulang tanpa perubahan tidak menambah audit', function (): void {
    $kirim = ['Outlet' => $this->k['Outlet']->Uuid, 'PungutPbjt' => false, 'BiayaLayananAktif' => false, 'PersenBiayaLayanan' => '', 'HargaTermasukPajak' => false];

    $this->put('/kelola/kasir/pajak', $kirim)
        ->assertSessionHasNoErrors()
        ->assertRedirect('/kelola/kasir/pajak?outlet='.$this->k['Outlet']->Uuid);

    $profil = Outlet::query()->whereKey($this->k['Outlet']->Id)->value('ProfilPajak');
    expect($profil['BiayaLayanan'])->toBe(['Aktif' => false, 'Persen' => '0.00'])
        ->and(LogAudit::query()->where('Peristiwa', 'outlet.pajak.ubah')->count())->toBe(1);

    $this->put('/kelola/kasir/pajak', $kirim)->assertSessionHasNoErrors();
    expect(LogAudit::query()->where('Peristiwa', 'outlet.pajak.ubah')->count())->toBe(1);

    $this->get('/kelola/kasir/pajak')->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Nilai.BiayaLayananAktif', false)
        ->where('Nilai.PersenBiayaLayanan', '0.00'));
});

it('mengubah persen biaya layanan dan harga termasuk pajak', function (): void {
    $this->put('/kelola/kasir/pajak', ['Outlet' => $this->k['Outlet']->Uuid, 'PungutPbjt' => false, 'BiayaLayananAktif' => true, 'PersenBiayaLayanan' => '7.5', 'HargaTermasukPajak' => true])
        ->assertSessionHasNoErrors();

    $profil = Outlet::query()->whereKey($this->k['Outlet']->Id)->value('ProfilPajak');
    expect($profil['BiayaLayanan'])->toBe(['Aktif' => true, 'Persen' => '7.50'])
        ->and($profil['HargaTermasukPajak'])->toBeTrue();
});

it('menolak persen di atas batas, persen kosong saat aktif, dan PBJT tanpa kota outlet', function (): void {
    $dasar = ['Outlet' => $this->k['Outlet']->Uuid, 'PungutPbjt' => false, 'BiayaLayananAktif' => true, 'PersenBiayaLayanan' => '2', 'HargaTermasukPajak' => false];

    $this->put('/kelola/kasir/pajak', [...$dasar, 'PersenBiayaLayanan' => '99'])->assertSessionHasErrors('PersenBiayaLayanan');
    $this->put('/kelola/kasir/pajak', [...$dasar, 'PersenBiayaLayanan' => ''])->assertSessionHasErrors('PersenBiayaLayanan');

    Outlet::query()->whereKey($this->k['Outlet']->Id)->update(['KodeKota' => null]);
    $this->put('/kelola/kasir/pajak', [...$dasar, 'PungutPbjt' => true])->assertSessionHasErrors('PungutPbjt');

    // Tidak ada yang berubah.
    expect(Outlet::query()->whereKey($this->k['Outlet']->Id)->value('ProfilPajak')['BiayaLayanan'])->toBe(['Aktif' => true, 'Persen' => '2.00']);
});

it('kasir tanpa izin outlet.kelola ditolak, dan outlet yang tidak dikenal tidak ditemukan', function (): void {
    $this->put('/kelola/kasir/pajak', ['Outlet' => (string) Str::ulid(), 'PungutPbjt' => false, 'BiayaLayananAktif' => false, 'PersenBiayaLayanan' => '', 'HargaTermasukPajak' => false])
        ->assertNotFound();

    BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id, PeranTenantBawaan::Kasir);
    $this->get('/kelola/kasir/pajak')->assertForbidden();
    $this->put('/kelola/kasir/pajak', ['Outlet' => $this->k['Outlet']->Uuid, 'PungutPbjt' => false, 'BiayaLayananAktif' => false, 'PersenBiayaLayanan' => '', 'HargaTermasukPajak' => false])
        ->assertForbidden();
});
