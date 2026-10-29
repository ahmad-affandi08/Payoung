<?php

declare(strict_types=1);

use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Penjualan\BantuanTokoOnline;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-17 toko online tampil modern (D-238): logo tenant di kepala toko lewat rute publik `/{slug}/logo` (disk logo tetap
 * privat, tidak bisa di-hotlink dari luar rute ini) dan penanda `Favorit` produk untuk kartu sorotan.
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

it('tanpa logo: UrlLogo kosong dan rute logo 404', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);

    $this->get($k['AlamatToko'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Toko.UrlLogo', null));
    $this->get($k['AlamatToko'].'/logo')->assertNotFound();
});

it('dengan logo usaha: UrlLogo menunjuk rute publik dan gambar boleh disimpan peramban', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    Storage::fake((string) config('tenant.DiskLogo'));
    Storage::disk((string) config('tenant.DiskLogo'))->put("logo/{$k['Tenant']->Id}/logo.png", 'png-palsu');
    $tenant = Tenant::query()->findOrFail($k['Tenant']->Id);
    $tenant->Pengaturan = [...($tenant->Pengaturan ?? []), 'PathLogo' => "logo/{$k['Tenant']->Id}/logo.png"];
    $tenant->save();

    $this->get($k['AlamatToko'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Toko.UrlLogo', url($k['AlamatToko'].'/logo').'?outlet='.$k['Outlet']->Uuid));

    $respons = $this->get($k['AlamatToko'].'/logo?outlet='.$k['Outlet']->Uuid)->assertOk();
    expect($respons->headers->get('Cache-Control'))->toContain('public')
        ->and($respons->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('slug yang tidak dikenal tidak pernah menyajikan logo', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    Storage::fake((string) config('tenant.DiskLogo'));
    Storage::disk((string) config('tenant.DiskLogo'))->put("logo/{$k['Tenant']->Id}/logo.png", 'png-palsu');
    $tenant = Tenant::query()->findOrFail($k['Tenant']->Id);
    $tenant->Pengaturan = [...($tenant->Pengaturan ?? []), 'PathLogo' => "logo/{$k['Tenant']->Id}/logo.png"];
    $tenant->save();

    $this->get('/toko-yang-tidak-ada/logo')->assertNotFound();
});

it('produk favorit pemilik ditandai di menu toko online', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    $k['Kopi']->forceFill(['Favorit' => true])->save();

    $this->get($k['AlamatToko'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Menu.Produk', fn ($produk) => collect($produk)->firstWhere('Uuid', $k['Kopi']->Uuid)['Favorit'] === true
            && collect($produk)->firstWhere('Uuid', $k['Nasi']->Uuid)['Favorit'] === false));
});
