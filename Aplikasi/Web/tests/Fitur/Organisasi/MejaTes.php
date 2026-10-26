<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\BentukMeja;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\AreaMeja;
use App\Domain\Organisasi\Model\Meja;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Tenant\Model\Tenant;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-10a area & meja per outlet (PRD "Rincian F-10a"): tambah/ubah/arsip lewat rute outlet (izin outlet.kelola),
 * nama unik per outlet, area aktif di outlet yang sama, fitur paket `pos.mode-meja` wajib untuk menambah, data lama
 * tetap bisa diubah setelah fitur mati, area berisi meja aktif tidak bisa diarsipkan, audit, isolasi tenant & outlet.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function OutletUtamaMeja(Tenant $tenant): Outlet
{
    BantuanOrganisasi::AturKonteks($tenant->Id);

    return Outlet::query()->orderBy('Id')->firstOrFail();
}

describe('F-10a meja & area', function (): void {
    it('tambah area & meja, tampil di detail outlet. Nama unik per outlet. Ubah & audit', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Kedai Kopi Senja Rasa Nusantara');
        $outlet = OutletUtamaMeja($tenant);
        $alamat = "/kelola/outlet/{$outlet->Uuid}";
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $this->post("{$alamat}/area-meja", ['Nama' => 'Teras Belakang', 'Urutan' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $area = AreaMeja::query()->sole();
        $this->post("{$alamat}/meja", ['Nama' => '7', 'Area' => $area->Uuid, 'Kapasitas' => 4, 'Bentuk' => 'Bundar'])->assertSessionHasNoErrors();
        $this->post("{$alamat}/meja", ['Nama' => '10', 'Kapasitas' => 6, 'Bentuk' => 'Panjang'])->assertSessionHasNoErrors();
        $this->post("{$alamat}/meja", ['Nama' => '7', 'Kapasitas' => 2, 'Bentuk' => 'Persegi'])->assertSessionHasErrors('Nama');
        $this->post("{$alamat}/meja", ['Nama' => '8', 'Kapasitas' => 0, 'Bentuk' => 'Persegi'])->assertSessionHasErrors('Kapasitas');
        $this->post("{$alamat}/area-meja", ['Nama' => 'Teras Belakang'])->assertSessionHasErrors('Nama');

        $tujuh = Meja::query()->where('Nama', '7')->sole();
        expect($tujuh->IdAreaMeja)->toBe($area->Id)->and($tujuh->Bentuk)->toBe(BentukMeja::Bundar);

        $this->get($alamat)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Outlet/Detail')
            ->where('ModeMeja.Aktif', true)
            ->where('ModeMeja.Area.0', ['Uuid' => $area->Uuid, 'Nama' => 'Teras Belakang', 'Urutan' => 1, 'Status' => 'Aktif', 'JumlahMeja' => 1, 'MinimumCharge' => null])
            // Urutan alami: "7" sebelum "10".
            ->where('ModeMeja.Meja.0.Nama', '7')
            ->where('ModeMeja.Meja.0.NamaArea', 'Teras Belakang')
            ->where('ModeMeja.Meja.1.Nama', '10')
            ->where('ModeMeja.Meja.1.UuidArea', null)
            ->has('BentukMeja', 3));

        $this->put("{$alamat}/meja/{$tujuh->Uuid}", ['Nama' => '7A', 'Area' => '', 'Kapasitas' => 5, 'Bentuk' => 'Persegi'])->assertSessionHasNoErrors();
        expect($tujuh->refresh()->Nama)->toBe('7A')->and($tujuh->IdAreaMeja)->toBeNull()->and($tujuh->Kapasitas)->toBe(5);
        expect(LogAudit::query()->where('Peristiwa', 'meja.ubah')->sole()->NilaiBaru)->toMatchArray(['Nama' => '7A', 'IdAreaMeja' => null, 'Kapasitas' => 5, 'Bentuk' => 'Persegi'])
            ->and(LogAudit::query()->where('Peristiwa', 'meja.buat')->count())->toBe(2)
            ->and(LogAudit::query()->where('Peristiwa', 'area-meja.buat')->count())->toBe(1);
    });

    it('arsip: area berisi meja aktif ditolak. Meja diarsipkan lalu area. Pulihkan meja butuh area aktif', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $outlet = OutletUtamaMeja($tenant);
        $alamat = "/kelola/outlet/{$outlet->Uuid}";
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);
        $this->post("{$alamat}/area-meja", ['Nama' => 'VIP']);
        $area = AreaMeja::query()->sole();
        $this->post("{$alamat}/meja", ['Nama' => 'VIP 1', 'Area' => $area->Uuid, 'Kapasitas' => 8, 'Bentuk' => 'Panjang']);
        $meja = Meja::query()->sole();

        $this->post("{$alamat}/area-meja/{$area->Uuid}/arsipkan")->assertSessionHasErrors('Umum');
        $this->post("{$alamat}/meja/{$meja->Uuid}/arsipkan")->assertSessionHasNoErrors();
        $this->post("{$alamat}/area-meja/{$area->Uuid}/arsipkan")->assertSessionHasNoErrors();
        expect($area->refresh()->Status)->toBe(StatusOrganisasi::Diarsipkan)->and($meja->refresh()->Status)->toBe(StatusOrganisasi::Diarsipkan);

        $this->post("{$alamat}/meja/{$meja->Uuid}/pulihkan")->assertSessionHasErrors('Umum');
        // Area diarsipkan tidak bisa dipilih untuk meja baru.
        $this->post("{$alamat}/meja", ['Nama' => 'VIP 2', 'Area' => $area->Uuid, 'Kapasitas' => 8, 'Bentuk' => 'Panjang'])->assertSessionHasErrors('Area');
        $this->post("{$alamat}/area-meja/{$area->Uuid}/pulihkan")->assertSessionHasNoErrors();
        $this->post("{$alamat}/meja/{$meja->Uuid}/pulihkan")->assertSessionHasNoErrors();
        expect($meja->refresh()->Status)->toBe(StatusOrganisasi::Aktif)
            ->and(LogAudit::query()->whereIn('Peristiwa', ['meja.arsipkan', 'meja.pulihkan', 'area-meja.arsipkan', 'area-meja.pulihkan'])->count())->toBe(4);
    });

    it('fitur pos.mode-meja: paket Starter tidak bisa menambah. Data lama tetap bisa diubah & diarsipkan', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Toko Kelontong Berkah', 'STARTER');
        $outlet = OutletUtamaMeja($tenant);
        $alamat = "/kelola/outlet/{$outlet->Uuid}";
        // Data lama (misal sebelum turun paket).
        $lama = Meja::query()->create(['IdOutlet' => $outlet->Id, 'Nama' => '1', 'Kapasitas' => 2]);
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $this->post("{$alamat}/meja", ['Nama' => '2', 'Kapasitas' => 2, 'Bentuk' => 'Persegi'])->assertSessionHasErrors('Umum');
        $this->post("{$alamat}/area-meja", ['Nama' => 'Indoor'])->assertSessionHasErrors('Umum');
        expect(Meja::query()->count())->toBe(1)->and(AreaMeja::query()->count())->toBe(0);

        $this->put("{$alamat}/meja/{$lama->Uuid}", ['Nama' => '1', 'Kapasitas' => 3, 'Bentuk' => 'Persegi'])->assertSessionHasNoErrors();
        $this->post("{$alamat}/meja/{$lama->Uuid}/arsipkan")->assertSessionHasNoErrors();
        $this->get($alamat)->assertInertia(fn (AssertableInertia $h) => $h->where('ModeMeja.Aktif', false)->has('ModeMeja.Meja', 1));
    });

    it('izin outlet.kelola, isolasi tenant, dan batas outlet', function (): void {
        ['Tenant' => $a, 'Pemilik' => $pemilikA] = BantuanOrganisasi::BuatTenant('Kedai A');
        $outletA = OutletUtamaMeja($a);
        $meja = Meja::query()->create(['IdOutlet' => $outletA->Id, 'Nama' => '1']);
        $area = AreaMeja::query()->create(['IdOutlet' => $outletA->Id, 'Nama' => 'Indoor']);

        $kasir = BantuanOrganisasi::TambahAnggota($a->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $kasir, $a->Id);
        $this->post("/kelola/outlet/{$outletA->Uuid}/meja", ['Nama' => '2', 'Kapasitas' => 2, 'Bentuk' => 'Persegi'])->assertForbidden();
        $this->post("/kelola/outlet/{$outletA->Uuid}/meja/{$meja->Uuid}/arsipkan")->assertForbidden();

        ['Tenant' => $b, 'Pemilik' => $pemilikB] = BantuanOrganisasi::BuatTenant('Kedai B');
        $outletB = OutletUtamaMeja($b);
        BantuanOrganisasi::Masuk($this, $pemilikB, $b->Id);
        $this->post("/kelola/outlet/{$outletA->Uuid}/meja", ['Nama' => '2', 'Kapasitas' => 2, 'Bentuk' => 'Persegi'])->assertNotFound();
        $this->put("/kelola/outlet/{$outletB->Uuid}/meja/{$meja->Uuid}", ['Nama' => 'Curian', 'Kapasitas' => 2, 'Bentuk' => 'Persegi'])->assertNotFound();
        // Area tenant lain tidak bisa dipakai lewat Uuid.
        $this->post("/kelola/outlet/{$outletB->Uuid}/meja", ['Nama' => '1', 'Area' => $area->Uuid, 'Kapasitas' => 2, 'Bentuk' => 'Persegi'])->assertSessionHasErrors('Area');
        $this->post("/kelola/outlet/{$outletB->Uuid}/area-meja/{$area->Uuid}/arsipkan")->assertNotFound();

        BantuanOrganisasi::AturKonteks($a->Id);
        expect($meja->refresh()->Nama)->toBe('1')->and($area->refresh()->Status)->toBe(StatusOrganisasi::Aktif);
        unset($pemilikA);
    });
});

describe('F-10a buat banyak meja', function (): void {
    it('Meja 1 sampai 12 dalam satu aksi: area, kapasitas, urutan. Nama bentrok menggagalkan semuanya. Batas dan izin', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Kedai Kopi Senja Massal');
        $outlet = OutletUtamaMeja($tenant);
        $alamat = "/kelola/outlet/{$outlet->Uuid}";
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);
        $this->post("{$alamat}/area-meja", ['Nama' => 'Indoor'])->assertSessionHasNoErrors();
        $area = AreaMeja::query()->sole();

        $this->post("{$alamat}/meja/massal", ['Awalan' => 'Meja', 'Mulai' => 1, 'Jumlah' => 12, 'Area' => $area->Uuid, 'Kapasitas' => 4, 'Bentuk' => 'Persegi'])
            ->assertSessionHasNoErrors()->assertSessionHas('Kilat', '12 meja ditambahkan: Meja 1 sampai Meja 12.');
        $meja = Meja::query()->orderBy('Urutan')->get();
        expect($meja)->toHaveCount(12)->and($meja->first()->Nama)->toBe('Meja 1')->and($meja->last()->Nama)->toBe('Meja 12')
            ->and($meja->every(fn (Meja $m): bool => $m->IdAreaMeja === $area->Id && $m->Kapasitas === 4))->toBeTrue()
            ->and(LogAudit::query()->where('Peristiwa', 'meja.buat')->count())->toBe(12);

        // Meja 10 sampai 14 bentrok di Meja 10: tidak satu pun ditambah.
        $this->post("{$alamat}/meja/massal", ['Awalan' => 'Meja', 'Mulai' => 10, 'Jumlah' => 5, 'Kapasitas' => 2, 'Bentuk' => 'Bundar'])->assertSessionHasErrors('Nama');
        expect(Meja::query()->count())->toBe(12);

        // Tanpa awalan: nomor saja. Batas jumlah, nomor, dan kapasitas dijaga.
        $this->post("{$alamat}/meja/massal", ['Awalan' => '', 'Mulai' => 20, 'Jumlah' => 3, 'Kapasitas' => 2, 'Bentuk' => 'Panjang'])
            ->assertSessionHas('Kilat', '3 meja ditambahkan: 20 sampai 22.');
        $this->post("{$alamat}/meja/massal", ['Mulai' => 1, 'Jumlah' => 101, 'Kapasitas' => 2, 'Bentuk' => 'Panjang'])->assertSessionHasErrors('Jumlah');
        $this->post("{$alamat}/meja/massal", ['Mulai' => 9999, 'Jumlah' => 5, 'Kapasitas' => 2, 'Bentuk' => 'Panjang'])->assertSessionHasErrors('Mulai');
        $this->post("{$alamat}/meja/massal", ['Mulai' => 30, 'Jumlah' => 2, 'Kapasitas' => 0, 'Bentuk' => 'Panjang'])->assertSessionHasErrors('Kapasitas');
        expect(Meja::query()->count())->toBe(15);

        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $kasir, $tenant->Id)
            ->post("{$alamat}/meja/massal", ['Mulai' => 40, 'Jumlah' => 2, 'Kapasitas' => 2, 'Bentuk' => 'Panjang'])->assertForbidden();
    });
});
