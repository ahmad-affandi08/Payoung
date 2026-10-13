<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Aksi\UbahKetersediaanProduk;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\KanalKetersediaan;
use App\Domain\Katalog\Kueri\MenuPesanSendiri;
use App\Domain\Katalog\Kueri\ProdukHabisOutlet;
use App\Domain\Katalog\Model\ProdukHabis;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPesanSendiri;
use Tests\Pendukung\Penjualan\BantuanTokoOnline;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-17 BR-17.2 (tandai habis / "86"): produk yang ditandai habis di satu outlet hilang dari menu self-order QR meja
 * dan toko online outlet itu, keranjang yang masih memuatnya ditolak saat dihitung ulang/checkout, dan pesanan yang
 * sudah masuk tidak disentuh. Per outlet, idempoten, tanpa efek stok/jurnal, terisolasi antar tenant.
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

describe('F-17 BR-17.2 tandai habis dari back-office', function (): void {
    it('Pemilik menandai habis lalu tersedia lagi; idempoten dan hanya keadaan yang berubah dicatat audit', function (): void {
        $k = BantuanTokoOnline::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $masuk = fn () => $this->actingAs($k['Pemilik'])->withSession(['IdTenantAktif' => $k['Tenant']->Id]);
        $alamat = "/kelola/produk/{$k['Kopi']->Uuid}/habis";

        $masuk()->post($alamat, ['UuidOutlet' => $k['Outlet']->Uuid, 'Habis' => true])->assertSessionHasNoErrors()->assertRedirect();
        $masuk()->post($alamat, ['UuidOutlet' => $k['Outlet']->Uuid, 'Habis' => true])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(ProdukHabis::query()->where('IdProduk', $k['Kopi']->Id)->count())->toBe(1)
            ->and(LogAudit::query()->where('Peristiwa', 'produk.habis')->count())->toBe(1);

        $masuk()->post($alamat, ['UuidOutlet' => $k['Outlet']->Uuid, 'Habis' => false])->assertSessionHasNoErrors();
        $masuk()->post($alamat, ['UuidOutlet' => $k['Outlet']->Uuid, 'Habis' => false])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(ProdukHabis::query()->count())->toBe(0)
            ->and(LogAudit::query()->where('Peristiwa', 'produk.tersedia')->count())->toBe(1);
    });

    it('produk induk varian ditolak (tandai per varian) dan produk atau outlet yang tidak dikenal 404', function (): void {
        $k = BantuanTokoOnline::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $masuk = fn () => $this->actingAs($k['Pemilik'])->withSession(['IdTenantAktif' => $k['Tenant']->Id]);
        $induk = BantuanKatalog::BuatProduk(['Nama' => 'Kaos Polos Katun Combed 30s', 'Jenis' => JenisProduk::IndukVarian]);

        $masuk()->post("/kelola/produk/{$induk->Uuid}/habis", ['UuidOutlet' => $k['Outlet']->Uuid, 'Habis' => true])->assertSessionHasErrors();
        $masuk()->post('/kelola/produk/'.strtoupper((string) Str::ulid()).'/habis', ['UuidOutlet' => $k['Outlet']->Uuid, 'Habis' => true])->assertNotFound();
        $masuk()->post("/kelola/produk/{$k['Kopi']->Uuid}/habis", ['UuidOutlet' => strtoupper((string) Str::ulid()), 'Habis' => true])->assertNotFound();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(ProdukHabis::query()->count())->toBe(0);
    });
    it('halaman detail produk memuat keadaan habis per outlet; induk varian tanpa panel', function (): void {
        $k = BantuanTokoOnline::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $masuk = fn () => $this->actingAs($k['Pemilik'])->withSession(['IdTenantAktif' => $k['Tenant']->Id]);
        $induk = BantuanKatalog::BuatProduk(['Nama' => 'Kaos Polos Katun Combed 30s', 'Jenis' => JenisProduk::IndukVarian]);

        $masuk()->get("/kelola/produk/{$k['Kopi']->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Ketersediaan.0.UuidOutlet', $k['Outlet']->Uuid)->where('Ketersediaan.0.Habis', false));

        $masuk()->post("/kelola/produk/{$k['Kopi']->Uuid}/habis", ['UuidOutlet' => $k['Outlet']->Uuid, 'Habis' => true])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $masuk()->get("/kelola/produk/{$k['Kopi']->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Ketersediaan.0.Habis', true));
        $masuk()->get("/kelola/produk/{$induk->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Ketersediaan', null));
    });
});

describe('F-17 BR-17.2 dampak ke toko online dan self-order', function (): void {
    it('toko online: produk habis hilang dari menu dan checkout menolaknya; tersedia lagi diterima', function (): void {
        $k = BantuanTokoOnline::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        $this->get($k['AlamatToko'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->has('Menu.Produk', 2));

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        app(UbahKetersediaanProduk::class)->Jalankan($k['Outlet']->Id, $k['Kopi']->Uuid, true, idPenggunaBackOffice: $k['Pemilik']->Id);

        $this->get($k['AlamatToko'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->has('Menu.Produk', 1)->where('Menu.Produk.0.Nama', $k['Nasi']->Nama));
        $this->postJson($k['AlamatToko'].'/keranjang/hitung', BantuanTokoOnline::Kiriman($k))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'ProdukTidakTersedia');
        $this->postJson($k['AlamatToko'].'/pesan', BantuanTokoOnline::Kiriman($k))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'ProdukTidakTersedia');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        app(UbahKetersediaanProduk::class)->Jalankan($k['Outlet']->Id, $k['Kopi']->Uuid, false, idPenggunaBackOffice: $k['Pemilik']->Id);

        $this->postJson($k['AlamatToko'].'/pesan', BantuanTokoOnline::Kiriman($k))->assertCreated();
    });

    it('self-order QR meja: produk habis hilang dari menu dan keranjang yang memuatnya ditolak', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        app(UbahKetersediaanProduk::class)->Jalankan($k['Outlet']->Id, $k['Nasi']->Uuid, true, idPenggunaBackOffice: $k['Pemilik']->Id);

        $this->get($k['Alamat'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->has('Menu.Produk', 1)->where('Menu.Produk.0.Nama', $k['Kopi']->Nama));
        $this->postJson("{$k['Alamat']}/hitung", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'ProdukTidakTersedia');
    });

    it('habis di satu outlet tidak memengaruhi tenant lain: isolasi tenant', function (): void {
        $a = BantuanTokoOnline::Siapkan($this);
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        app(UbahKetersediaanProduk::class)->Jalankan($a['Outlet']->Id, $a['Kopi']->Uuid, true, idPenggunaBackOffice: $a['Pemilik']->Id);
        $idProdukA = $a['Kopi']->Id;

        $b = BantuanKasir::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);

        expect(ProdukHabis::query()->where('IdProduk', $idProdukA)->count())->toBe(0);
        $this->withToken($b['Token'])->getJson('/api/pos/v1/produk-habis')->assertOk()->assertExactJson(['Produk' => []]);
    });
});

describe('F-17 BR-17.2 tandai habis dari aplikasi POS', function (): void {
    it('kasir menandai habis lalu tersedia lagi; daftar produk habis outlet perangkat ikut berubah; idempoten', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Soto Ayam Kampung Special'], '27000.00');
        $alamat = "/api/pos/v1/produk/{$produk->Uuid}/habis";

        $this->withToken($k['Token'])->postJson($alamat, ['UuidPengguna' => $k['Kasir']->Uuid, 'Habis' => true])
            ->assertOk()->assertJson(['Uuid' => $produk->Uuid, 'Habis' => true]);
        $this->withToken($k['Token'])->postJson($alamat, ['UuidPengguna' => $k['Kasir']->Uuid, 'Habis' => true])->assertOk();
        $this->withToken($k['Token'])->getJson('/api/pos/v1/produk-habis')->assertOk()->assertExactJson(['Produk' => [$produk->Uuid]]);

        $this->withToken($k['Token'])->postJson($alamat, ['UuidPengguna' => $k['Kasir']->Uuid, 'Habis' => false])
            ->assertOk()->assertJson(['Habis' => false]);
        $this->withToken($k['Token'])->getJson('/api/pos/v1/produk-habis')->assertOk()->assertExactJson(['Produk' => []]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(LogAudit::query()->where('Peristiwa', 'produk.habis')->count())->toBe(1)
            ->and(LogAudit::query()->where('Peristiwa', 'produk.tersedia')->count())->toBe(1);
    });

    it('pelaku yang bukan anggota tenant ditolak dan produk tak dikenal 404; tanpa token 401', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Soto Ayam Kampung Special'], '27000.00');

        $this->withToken($k['Token'])->postJson("/api/pos/v1/produk/{$produk->Uuid}/habis", ['UuidPengguna' => strtoupper((string) Str::ulid()), 'Habis' => true])
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'KasirTidakDitemukan');
        $this->withToken($k['Token'])->postJson('/api/pos/v1/produk/'.strtoupper((string) Str::ulid()).'/habis', ['UuidPengguna' => $k['Kasir']->Uuid, 'Habis' => true])
            ->assertNotFound();
        $this->flushHeaders()->postJson("/api/pos/v1/produk/{$produk->Uuid}/habis", ['UuidPengguna' => $k['Kasir']->Uuid, 'Habis' => true])->assertUnauthorized();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(ProdukHabis::query()->count())->toBe(0);
    });
});

describe('PRD-11 ketersediaan per outlet × kanal', function (): void {
    function KanalAda(int $idProduk, int $idOutlet): array
    {
        return ProdukHabis::query()->where('IdProduk', $idProduk)->where('IdOutlet', $idOutlet)->orderBy('Kanal')->get()->map(fn (ProdukHabis $b): string => $b->Kanal->value)->all();
    }

    it('habis hanya di toko online: hilang dari toko online, tetap ada di self-order dan kasir', function (): void {
        $k = BantuanTokoOnline::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        app(UbahKetersediaanProduk::class)->Jalankan($k['Outlet']->Id, $k['Kopi']->Uuid, true, idPenggunaBackOffice: $k['Pemilik']->Id, kanal: KanalKetersediaan::TokoOnline);

        $this->get($k['AlamatToko'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->has('Menu.Produk', 1)->where('Menu.Produk.0.Nama', $k['Nasi']->Nama));
        $this->postJson($k['AlamatToko'].'/keranjang/hitung', BantuanTokoOnline::Kiriman($k))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'ProdukTidakTersedia');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kueri = app(MenuPesanSendiri::class);
        $selfOrder = array_column($kueri->Ambil($k['Outlet']->Id, '/g/{uuid}', tampilOnline: false)['Produk'], 'Nama');
        $kasir = app(ProdukHabisOutlet::class)->AmbilUuid($k['Outlet']->Id);

        expect($selfOrder)->toContain($k['Kopi']->Nama)
            ->and($kasir)->toBe([])
            ->and(KanalAda($k['Kopi']->Id, $k['Outlet']->Id))->toBe(['TokoOnline']);
    });

    it('habis hanya di self-order: hilang dari QR meja dan kios, tetap ada di toko online', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        app(UbahKetersediaanProduk::class)->Jalankan($k['Outlet']->Id, $k['Nasi']->Uuid, true, idPenggunaBackOffice: $k['Pemilik']->Id, kanal: KanalKetersediaan::SelfOrder);

        $this->get($k['Alamat'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->has('Menu.Produk', 1)->where('Menu.Produk.0.Nama', $k['Kopi']->Nama));
        $this->postJson("{$k['Alamat']}/hitung", BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]))
            ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'ProdukTidakTersedia');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $online = array_column(app(MenuPesanSendiri::class)->Ambil($k['Outlet']->Id, '/g/{uuid}', tampilOnline: true)['Produk'], 'Nama');
        $k['Nasi']->forceFill(['TampilOnline' => true])->save();
        $online = array_column(app(MenuPesanSendiri::class)->Ambil($k['Outlet']->Id, '/g/{uuid}', tampilOnline: true)['Produk'], 'Nama');

        expect($online)->toContain($k['Nasi']->Nama);
    });

    it('habis hanya di kasir: daftar produk-habis POS memuatnya, menu online tidak terpengaruh', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Soto Ayam Kampung Special', 'TampilOnline' => true], '27000.00');
        $alamat = "/api/pos/v1/produk/{$produk->Uuid}/habis";

        $this->withToken($k['Token'])->postJson($alamat, ['UuidPengguna' => $k['Kasir']->Uuid, 'Habis' => true, 'Kanal' => 'Kasir'])->assertOk()->assertJson(['Habis' => true]);
        $this->withToken($k['Token'])->getJson('/api/pos/v1/produk-habis')->assertOk()->assertExactJson(['Produk' => [$produk->Uuid]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $online = array_column(app(MenuPesanSendiri::class)->Ambil($k['Outlet']->Id, '/g/{uuid}', tampilOnline: true)['Produk'], 'Nama');
        expect($online)->toContain('Soto Ayam Kampung Special');

        // Kanal rusak ditolak; tanpa Kanal berarti semua kanal (kontrak lama).
        $this->withToken($k['Token'])->postJson($alamat, ['UuidPengguna' => $k['Kasir']->Uuid, 'Habis' => true, 'Kanal' => 'Ojol'])->assertUnprocessable();
        $this->withToken($k['Token'])->postJson($alamat, ['UuidPengguna' => $k['Kasir']->Uuid, 'Habis' => true])->assertOk();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(KanalAda($produk->Id, $k['Outlet']->Id))->toBe(['Semua']);
    });

    it('transisi penyimpanan: Semua menggantikan kanal tunggal; kanal saat Semua tidak mengubah; tiga kanal digabung; tersedia di satu kanal memecah Semua', function (): void {
        $k = BantuanTokoOnline::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $ubah = fn (bool $habis, KanalKetersediaan $kanal) => app(UbahKetersediaanProduk::class)->Jalankan($k['Outlet']->Id, $k['Kopi']->Uuid, $habis, idPenggunaBackOffice: $k['Pemilik']->Id, kanal: $kanal);
        $id = [$k['Kopi']->Id, $k['Outlet']->Id];

        $ubah(true, KanalKetersediaan::Kasir);
        $ubah(true, KanalKetersediaan::Kasir);
        expect(KanalAda(...$id))->toBe(['Kasir']);

        $ubah(true, KanalKetersediaan::Semua);
        expect(KanalAda(...$id))->toBe(['Semua']);

        $ubah(true, KanalKetersediaan::TokoOnline);
        expect(KanalAda(...$id))->toBe(['Semua']);

        $ubah(false, KanalKetersediaan::TokoOnline);
        expect(KanalAda(...$id))->toBe(['Kasir', 'SelfOrder']);

        $ubah(true, KanalKetersediaan::TokoOnline);
        expect(KanalAda(...$id))->toBe(['Semua']);

        $ubah(false, KanalKetersediaan::Semua);
        expect(KanalAda(...$id))->toBe([]);

        $ubah(true, KanalKetersediaan::SelfOrder);
        $ubah(false, KanalKetersediaan::SelfOrder);
        $ubah(false, KanalKetersediaan::SelfOrder);
        expect(KanalAda(...$id))->toBe([]);
    });

    it('audit hanya saat keadaan efektif berubah dan memuat kanal serta daftar kanal yang habis', function (): void {
        $k = BantuanTokoOnline::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $ubah = fn (bool $habis, KanalKetersediaan $kanal) => app(UbahKetersediaanProduk::class)->Jalankan($k['Outlet']->Id, $k['Kopi']->Uuid, $habis, idPenggunaBackOffice: $k['Pemilik']->Id, kanal: $kanal);

        $ubah(true, KanalKetersediaan::Kasir);
        $ubah(true, KanalKetersediaan::Semua);
        // Sudah habis di semua kanal: menandai satu kanal tidak mengubah keadaan efektif, jadi tidak dicatat.
        $ubah(true, KanalKetersediaan::SelfOrder);
        $ubah(false, KanalKetersediaan::Kasir);

        $log = LogAudit::query()->whereIn('Peristiwa', ['produk.habis', 'produk.tersedia'])->orderBy('Id')->get();
        expect($log)->toHaveCount(3)
            ->and($log->pluck('Peristiwa')->all())->toBe(['produk.habis', 'produk.habis', 'produk.tersedia'])
            ->and($log[0]->NilaiBaru['KanalHabis'])->toBe(['Kasir'])
            ->and($log[1]->NilaiBaru['KanalHabis'])->toBe(['Kasir', 'TokoOnline', 'SelfOrder'])
            ->and($log[2]->NilaiBaru['Kanal'])->toBe('Kasir')
            ->and($log[2]->NilaiBaru['KanalHabis'])->toBe(['TokoOnline', 'SelfOrder']);
    });

    it('back-office: panel memuat keadaan per kanal; ubah per kanal lewat form; Kanal tidak dikenal ditolak', function (): void {
        $k = BantuanTokoOnline::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $masuk = fn () => $this->actingAs($k['Pemilik'])->withSession(['IdTenantAktif' => $k['Tenant']->Id]);
        $alamat = "/kelola/produk/{$k['Kopi']->Uuid}/habis";

        $masuk()->post($alamat, ['UuidOutlet' => $k['Outlet']->Uuid, 'Habis' => true, 'Kanal' => 'SelfOrder'])->assertSessionHasNoErrors();
        $masuk()->post($alamat, ['UuidOutlet' => $k['Outlet']->Uuid, 'Habis' => true, 'Kanal' => 'Ojol'])->assertSessionHasErrors('Kanal');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $masuk()->get("/kelola/produk/{$k['Kopi']->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Ketersediaan.0.Habis', false)
            ->where('Ketersediaan.0.Kanal.SelfOrder', true)
            ->where('Ketersediaan.0.Kanal.Kasir', false)
            ->where('Ketersediaan.0.Kanal.TokoOnline', false));
    });

    it('migrasi: baris lama berarti semua kanal (bawaan Semua); satu produk bisa punya baris beberapa kanal tetapi tidak ganda', function (): void {
        $k = BantuanTokoOnline::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        ProdukHabis::query()->create(['IdProduk' => $k['Kopi']->Id, 'IdOutlet' => $k['Outlet']->Id]);
        expect(ProdukHabis::query()->sole()->Kanal)->toBe(KanalKetersediaan::Semua);

        ProdukHabis::query()->create(['IdProduk' => $k['Kopi']->Id, 'IdOutlet' => $k['Outlet']->Id, 'Kanal' => 'Kasir']);
        expect(fn () => ProdukHabis::query()->create(['IdProduk' => $k['Kopi']->Id, 'IdOutlet' => $k['Outlet']->Id, 'Kanal' => 'Kasir']))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});
