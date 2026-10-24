<?php

declare(strict_types=1);

use App\Domain\Organisasi\Model\Outlet;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-48: fitur khusus sektor (bengkel, reservasi, kanvas, stasiun dapur, ...) disaring menurut sektor tenant. Server
 * membagikan kode sektor outlet + jenis usaha tambahan ke back-office (`SektorOutlet`) dan ke aplikasi kasir
 * (`data-awal` → `KodeSektor`). Kosong = sektor belum diketahui, semua fitur tampil.
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

describe('Sektor outlet untuk pemfilteran fitur (D-48)', function (): void {
    it('data awal kasir: kosong sebelum template diterapkan; lalu template outlet + jenis usaha tambahan', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $dataAwal = fn () => $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk();

        $dataAwal()->assertJsonPath('KodeSektor', []);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        Outlet::query()->whereKey($k['Outlet']->Id)->update(['TemplateSektor' => 'RTL-GEN']);
        $dataAwal()->assertJsonPath('KodeSektor', ['RTL-GEN']);

        $k['Tenant']->forceFill(['Pengaturan' => ['Sektor' => ['RTL-GEN', 'SVC-WRK']]])->save();
        $dataAwal()->assertJsonPath('KodeSektor', ['RTL-GEN', 'SVC-WRK']);
    });

    it('back-office: prop SektorOutlet memuat sektor outlet dan jenis usaha tambahan (toko kelontong + bengkel)', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        Outlet::query()->whereKey($k['Outlet']->Id)->update(['TemplateSektor' => 'RTL-GEN']);
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->get('/kelola')->assertInertia(fn (AssertableInertia $h) => $h->where('SektorOutlet', ['RTL-GEN']));

        $k['Tenant']->forceFill(['Pengaturan' => ['Sektor' => ['RTL-GEN', 'SVC-WRK']]])->save();
        $this->get('/kelola')->assertInertia(fn (AssertableInertia $h) => $h->where('SektorOutlet', ['RTL-GEN', 'SVC-WRK']));
    });
});
