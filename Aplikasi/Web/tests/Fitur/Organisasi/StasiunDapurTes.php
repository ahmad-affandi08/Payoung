<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Organisasi\Aksi\SimpanStasiunDapur;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\StasiunDapur;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-10a stasiun dapur tingkat tenant (PRD "Rincian F-10a"): tambah/ubah/arsip (izin produk.kelola), nama unik per
 * tenant, batas stasiun aktif, kaitan kategori → stasiun lewat form kategori (tidak dikirim = tidak diubah, hanya
 * stasiun aktif tenant sendiri), template sektor membuat stasiun secara idempoten, audit, izin & isolasi tenant.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-10a stasiun dapur', function (): void {
    it('tambah, ubah, nama unik, arsip & pulihkan. Daftar urut & audit', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Kedai Kopi Senja');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $this->post('/kelola/stasiun-dapur', ['Nama' => 'Dapur', 'Urutan' => 2])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/kelola/stasiun-dapur', ['Nama' => 'Bar', 'Urutan' => 1])->assertSessionHasNoErrors();
        $this->post('/kelola/stasiun-dapur', ['Nama' => 'Bar'])->assertSessionHasErrors('Nama');
        $bar = StasiunDapur::query()->where('Nama', 'Bar')->sole();
        $this->put("/kelola/stasiun-dapur/{$bar->Uuid}", ['Nama' => 'Bar Kopi', 'Urutan' => 1])->assertSessionHasNoErrors();

        $this->get('/kelola/stasiun-dapur')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/StasiunDapur/Daftar')
            ->where('Stasiun.0', ['Uuid' => $bar->Uuid, 'Nama' => 'Bar Kopi', 'Urutan' => 1, 'Status' => 'Aktif'])
            ->where('Stasiun.1.Nama', 'Dapur')
            ->where('Izin.Kelola', true));

        $this->post("/kelola/stasiun-dapur/{$bar->Uuid}/arsipkan")->assertSessionHasNoErrors();
        $this->post("/kelola/stasiun-dapur/{$bar->Uuid}/arsipkan")->assertSessionHasErrors('Umum');
        expect($bar->refresh()->Status)->toBe(StatusOrganisasi::Diarsipkan);
        $this->get('/kelola/stasiun-dapur')->assertInertia(fn (AssertableInertia $h) => $h->where('Stasiun.0.Nama', 'Dapur')->where('Stasiun.1.Status', 'Diarsipkan'));
        $this->post("/kelola/stasiun-dapur/{$bar->Uuid}/pulihkan")->assertSessionHasNoErrors();

        expect(LogAudit::query()->whereIn('Peristiwa', ['stasiun-dapur.buat', 'stasiun-dapur.ubah', 'stasiun-dapur.arsipkan', 'stasiun-dapur.pulihkan'])->count())->toBe(5);
    });

    it('batas stasiun aktif', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();

        foreach (range(1, SimpanStasiunDapur::MAKS_AKTIF) as $i) {
            StasiunDapur::query()->create(['Nama' => "Stasiun {$i}"]);
        }

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $this->post('/kelola/stasiun-dapur', ['Nama' => 'Pastry'])->assertSessionHasErrors('Umum');
        expect(StasiunDapur::query()->count())->toBe(SimpanStasiunDapur::MAKS_AKTIF);
    });

    it('kategori → stasiun: dipilih lewat form, tidak dikirim = tidak diubah, kosong = bawaan. Stasiun arsip/tenant lain ditolak', function (): void {
        $b = BantuanKatalog::SiapkanTenantProduk('Warung Bakso Pak Kumis');
        $stasiunLain = StasiunDapur::query()->create(['Nama' => 'Dapur']);
        $t = BantuanKatalog::SiapkanTenantProduk('Kedai Kopi Senja');
        $bar = StasiunDapur::query()->create(['Nama' => 'Bar']);
        $arsip = StasiunDapur::query()->create(['Nama' => 'Pastry', 'Status' => 'Diarsipkan']);
        $kopi = BantuanKatalog::BuatKategori('Kopi');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $this->put("/kelola/kategori/{$kopi->Uuid}", ['Nama' => 'Kopi', 'UuidStasiunDapur' => $bar->Uuid])->assertSessionHasNoErrors();
        expect($kopi->refresh()->IdStasiunDapur)->toBe($bar->Id);

        // Klien lama tanpa bidang stasiun: rujukan tetap.
        $this->put("/kelola/kategori/{$kopi->Uuid}", ['Nama' => 'Kopi Susu'])->assertSessionHasNoErrors();
        expect($kopi->refresh()->IdStasiunDapur)->toBe($bar->Id)->and($kopi->Nama)->toBe('Kopi Susu');

        $this->put("/kelola/kategori/{$kopi->Uuid}", ['Nama' => 'Kopi Susu', 'UuidStasiunDapur' => $arsip->Uuid])->assertSessionHasErrors('UuidStasiunDapur');
        $this->put("/kelola/kategori/{$kopi->Uuid}", ['Nama' => 'Kopi Susu', 'UuidStasiunDapur' => $stasiunLain->Uuid])->assertSessionHasErrors('UuidStasiunDapur');
        expect($kopi->refresh()->IdStasiunDapur)->toBe($bar->Id);

        $this->get('/kelola/kategori')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Kategori.0.UuidStasiunDapur', $bar->Uuid)
            ->where('Kategori.0.NamaStasiunDapur', 'Bar')
            ->where('OpsiStasiunDapur', [['Nilai' => $bar->Uuid, 'Label' => 'Bar']]));

        $this->put("/kelola/kategori/{$kopi->Uuid}", ['Nama' => 'Kopi Susu', 'UuidStasiunDapur' => null])->assertSessionHasNoErrors();
        expect($kopi->refresh()->IdStasiunDapur)->toBeNull();
        expect(LogAudit::query()->where('Peristiwa', 'kategori.ubah')->latest('Id')->first()?->NilaiBaru)->toMatchArray(['IdStasiunDapur' => null]);
        unset($b);
    });

    it('template sektor membuat stasiun idempoten (FNB-CAF: Bar, Dapur). Nama yang sudah ada dilewati', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        $t = BantuanPanduanAwal::BuatTenant('Kedai Kopi Senja');
        StasiunDapur::query()->create(['Nama' => 'dapur', 'Status' => 'Diarsipkan']);

        $hasil = BantuanPanduanAwal::Terapkan($t['Outlet']);
        expect($hasil->jumlahStasiunDapur)->toBe(1)
            ->and(StasiunDapur::query()->orderBy('Id')->pluck('Nama')->all())->toBe(['dapur', 'Bar']);

        $lagi = BantuanPanduanAwal::Terapkan($t['Outlet']);
        expect($lagi->jumlahStasiunDapur)->toBe(0)->and(StasiunDapur::query()->count())->toBe(2)
            ->and(LogAudit::query()->where('Peristiwa', 'stasiun-dapur.tambah-template')->sole()->NilaiBaru)->toBe(['Nama' => ['Bar']]);
    });

    it('izin: kasir (produk.lihat) hanya melihat, tidak bisa mengubah. Isolasi tenant', function (): void {
        $a = BantuanKatalog::SiapkanTenantProduk('Kedai A');
        $stasiun = StasiunDapur::query()->create(['Nama' => 'Dapur']);

        BantuanKatalog::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/stasiun-dapur')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Izin.Kelola', false)->has('Stasiun', 1));
        $this->post('/kelola/stasiun-dapur', ['Nama' => 'Bar'])->assertForbidden();
        $this->post("/kelola/stasiun-dapur/{$stasiun->Uuid}/arsipkan")->assertForbidden();

        $b = BantuanKatalog::SiapkanTenantProduk('Kedai B');
        BantuanKatalog::MasukSebagai($this, $b['Tenant']->Id);
        $this->get('/kelola/stasiun-dapur')->assertInertia(fn (AssertableInertia $h) => $h->where('Stasiun', []));
        $this->put("/kelola/stasiun-dapur/{$stasiun->Uuid}", ['Nama' => 'Curian'])->assertNotFound();
        $this->post("/kelola/stasiun-dapur/{$stasiun->Uuid}/arsipkan")->assertNotFound();

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect($stasiun->refresh()->Nama)->toBe('Dapur')->and($stasiun->Status)->toBe(StatusOrganisasi::Aktif);
    });
});
