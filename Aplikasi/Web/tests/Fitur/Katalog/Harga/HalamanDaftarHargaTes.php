<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Harga\Model\DaftarHarga;
use App\Domain\Katalog\Harga\Model\RiwayatHarga;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->t = BantuanKatalog::SiapkanTenantProduk();
    BantuanFitur::Beri($this->t['Tenant']->Id, 'harga.daftar-harga');
    $this->solo = BantuanHarga::BuatOutlet('SLO1', 'Outlet Solo Baru');
    $this->bandara = BantuanHarga::BuatOutlet('CGK1', 'Outlet Bandara Soetta');
});

/**
 * @param  array<string, mixed>  $timpa
 * @return array<string, mixed>
 */
function IsiFormDaftarHarga(array $timpa = []): array
{
    return array_replace([
        'Nama' => 'Harga Bandara',
        'UuidOutlet' => [],
        'Kanal' => '',
        'TierPelanggan' => '',
        'MulaiPada' => '',
        'SelesaiPada' => '',
        'Prioritas' => '0',
    ], $timpa);
}

describe('F-03 daftar harga (E.7)', function (): void {
    it('membuat daftar harga: outlet → Id, waktu zona tenant → UTC, redirect ke detail, audit', function (): void {
        $respons = BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id)->post('/kelola/daftar-harga', IsiFormDaftarHarga([
            'UuidOutlet' => [$this->bandara->Uuid], 'Kanal' => 'Online', 'TierPelanggan' => ' Grosir ',
            'MulaiPada' => '2026-10-01T07:00', 'SelesaiPada' => '2026-11-01T07:00', 'Prioritas' => '10',
        ]))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($this->t['Tenant']->Id);
        $daftar = DaftarHarga::query()->sole();
        $respons->assertRedirect("/kelola/daftar-harga/{$daftar->Uuid}");
        expect($daftar->IdOutlet)->toBe([$this->bandara->Id])
            ->and($daftar->Kanal)->toBe(KanalPenjualan::Online)
            ->and($daftar->TierPelanggan)->toBe('Grosir')
            ->and($daftar->MulaiPada?->utc()->toIso8601ZuluString())->toBe('2026-10-01T00:00:00Z')
            ->and($daftar->SelesaiPada?->utc()->toIso8601ZuluString())->toBe('2026-11-01T00:00:00Z')
            ->and($daftar->Prioritas)->toBe(10)
            ->and(LogAudit::query()->where('Peristiwa', 'daftar-harga.buat')->exists())->toBeTrue();
    });

    it('menolak nama ganda, rentang waktu terbalik, dan outlet tidak dikenal', function (): void {
        BantuanHarga::BuatDaftarHarga('Harga Member');
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id);

        $masuk()->post('/kelola/daftar-harga', IsiFormDaftarHarga(['Nama' => 'harga member']))->assertSessionHasErrors(['Nama']);
        $masuk()->post('/kelola/daftar-harga', IsiFormDaftarHarga(['MulaiPada' => '2026-10-02T00:00', 'SelesaiPada' => '2026-10-01T00:00']))->assertSessionHasErrors(['SelesaiPada']);
        $masuk()->post('/kelola/daftar-harga', IsiFormDaftarHarga(['UuidOutlet' => ['01JBZZZZZZZZZZZZZZZZZZZZZZ']]))->assertSessionHasErrors(['UuidOutlet']);
        $masuk()->post('/kelola/daftar-harga', IsiFormDaftarHarga(['Kanal' => 'Telepon']))->assertSessionHasErrors(['Kanal']);

        BantuanOrganisasi::AturKonteks($this->t['Tenant']->Id);
        expect(DaftarHarga::query()->count())->toBe(1);
    });

    it('pelaku yang dibatasi outlet hanya boleh daftar khusus outletnya (OutletDiLuarAkses)', function (): void {
        $admin = BantuanHarga::TambahAnggotaOutlet($this->t['Tenant']->Id, PeranTenantBawaan::Admin, $this->solo);
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $admin, $this->t['Tenant']->Id);
        $semua = BantuanHarga::BuatDaftarHarga('Harga Semua Outlet');

        $masuk()->post('/kelola/daftar-harga', IsiFormDaftarHarga())->assertSessionHasErrors(['UuidOutlet']);
        $masuk()->post('/kelola/daftar-harga', IsiFormDaftarHarga(['UuidOutlet' => [$this->bandara->Uuid]]))->assertSessionHasErrors(['UuidOutlet']);
        $masuk()->post('/kelola/daftar-harga', IsiFormDaftarHarga(['Nama' => 'Harga Solo', 'UuidOutlet' => [$this->solo->Uuid]]))->assertSessionHasNoErrors();
        $masuk()->post("/kelola/daftar-harga/{$semua->Uuid}/nonaktifkan")->assertSessionHasErrors(['UuidOutlet']);
        $masuk()->get('/kelola/daftar-harga')->assertInertia(fn (AssertableInertia $h) => $h->where('Outlet', [['Nilai' => $this->solo->Uuid, 'Label' => 'Outlet Solo Baru']]));

        BantuanOrganisasi::AturKonteks($this->t['Tenant']->Id);
        expect(DaftarHarga::query()->orderBy('Id')->pluck('Nama')->all())->toBe(['Harga Semua Outlet', 'Harga Solo'])
            ->and($semua->refresh()->Aktif)->toBeTrue();
    });

    it('halaman buat (halaman penuh): opsi outlet, kanal, tier, zona waktu; Kasir 403', function (): void {
        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id)->get('/kelola/daftar-harga/buat')->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/DaftarHarga/Buat')
                ->has('Outlet')
                ->has('Kanal', count(KanalPenjualan::cases()))
                ->has('OpsiTier')
                ->where('ZonaWaktu', 'Asia/Jakarta'));

        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id, PeranTenantBawaan::Kasir)->get('/kelola/daftar-harga/buat')->assertForbidden();
    });

    it('halaman daftar: baris dengan nama outlet, label kanal, waktu lokal, jumlah produk; opsi kanal & zona waktu', function (): void {
        $daftar = BantuanHarga::BuatDaftarHarga('Harga Bandara', [
            'IdOutlet' => [$this->bandara->Id], 'Kanal' => KanalPenjualan::BawaPulang, 'Prioritas' => 5,
            'MulaiPada' => '2026-10-01 00:00:00', 'SelesaiPada' => null,
        ]);
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Roti Sobek Cokelat Keju'], '15000.00', $this->t['Pcs']);
        BantuanHarga::TambahHargaDaftar($daftar, BantuanHarga::SatuanDasar($produk), '1', '17000');
        BantuanHarga::BuatDaftarHarga('Harga Lama', ['Aktif' => false, 'Prioritas' => 99]);

        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id, PeranTenantBawaan::Kasir)->get('/kelola/daftar-harga')->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/DaftarHarga/Daftar')
                ->where('DaftarHarga.Meta.Total', 2)
                ->where('DaftarHarga.Data.0.Nama', 'Harga Bandara')
                ->where('DaftarHarga.Data.0.NamaOutlet', ['Outlet Bandara Soetta'])
                ->where('DaftarHarga.Data.0.LabelKanal', 'Bawa pulang')
                ->where('DaftarHarga.Data.0.MulaiPada', '2026-10-01T07:00')
                ->where('DaftarHarga.Data.0.SelesaiPada', null)
                ->where('DaftarHarga.Data.0.JumlahProduk', 1)
                ->where('DaftarHarga.Data.1.Aktif', false)
                ->has('Kanal', count(KanalPenjualan::cases()))
                ->where('ZonaWaktu', 'Asia/Jakarta')
                ->where('Izin.UbahHarga', false));

        // TabelData (D-16): saring status & cari nama dari URL yang sama sebagai JSON.
        $kasir = BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id, PeranTenantBawaan::Kasir);
        $kasir->getJson('/kelola/daftar-harga?saring[Status]=Nonaktif')->assertOk()->assertJsonPath('Meta.Total', 1)->assertJsonPath('Data.0.Nama', 'Harga Lama');
        $kasir->getJson('/kelola/daftar-harga?cari=bandara')->assertOk()->assertJsonPath('Meta.Total', 1)->assertJsonPath('Data.0.Nama', 'Harga Bandara');
    });

    it('detail: form, baris satuan yang dijual (yang punya harga di daftar dulu), pencarian kata', function (): void {
        $daftar = BantuanHarga::BuatDaftarHarga('Harga Grosir', ['Kanal' => KanalPenjualan::Antar]);
        $sabun = BantuanKatalog::BuatProduk(['Nama' => 'Sabun Mandi Cair 450 ml'], '5000.00', $this->t['Pcs']);
        $zaitun = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Zaitun Extra Virgin 250 ml'], '85000.00', $this->t['Pcs']);
        BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir Curah', 'Jenis' => JenisProduk::BahanBaku], '14000.00', $this->t['Pcs']);
        BantuanHarga::TambahHargaDaftar($daftar, BantuanHarga::SatuanDasar($zaitun), '12', '80000');

        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id);
        $masuk()->get("/kelola/daftar-harga/{$daftar->Uuid}")->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/DaftarHarga/Detail')
                ->where('DaftarHarga.Uuid', $daftar->Uuid)
                ->where('DaftarHarga.Kanal', 'Antar')
                ->where('DaftarHarga.UuidOutlet', [])
                ->where('DaftarHarga.MulaiPada', '')
                ->where('DaftarHarga.Prioritas', '0')
                ->where('Baris.Meta.Total', 2)
                ->where('Baris.Data.0.NamaProduk', 'Minyak Zaitun Extra Virgin 250 ml')
                ->where('Baris.Data.0.HargaDasar', '85000.00')
                ->where('Baris.Data.0.Harga', [['JumlahMinimum' => '12.0000', 'Harga' => '80000.00']])
                ->where('Baris.Data.1.UuidProduk', $sabun->Uuid)
                ->where('Baris.Data.1.Harga', []));

        $masuk()->get("/kelola/daftar-harga/{$daftar->Uuid}?cari=sabun")
            ->assertInertia(fn (AssertableInertia $h) => $h->where('Baris.Meta.Total', 1));
        $masuk()->getJson("/kelola/daftar-harga/{$daftar->Uuid}?cari=sabun")->assertOk()->assertJsonPath('Meta.Total', 1)->assertJsonPath('Data.0.UuidProduk', $sabun->Uuid);
    });

    it('BR-03.3 menyimpan harga di daftar (tingkat tanpa harga dasar boleh), riwayat ber-IdDaftarHarga, galat Baris.{i}', function (): void {
        $daftar = BantuanHarga::BuatDaftarHarga('Harga Grosir');
        $sabun = BantuanHarga::SatuanDasar(BantuanKatalog::BuatProduk(['Nama' => 'Sabun Mandi Cair 450 ml'], '5000.00', $this->t['Pcs']));
        $teh = BantuanHarga::SatuanDasar(BantuanKatalog::BuatProduk(['Nama' => 'Teh Melati Celup 25'], '7500.00', $this->t['Pcs']));
        $url = "/kelola/daftar-harga/{$daftar->Uuid}/harga";

        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id)->put($url, ['Baris' => [
            ['UuidProdukSatuan' => $sabun->Uuid, 'Harga' => [['JumlahMinimum' => '12', 'Harga' => '4000']]],
            ['UuidProdukSatuan' => $teh->Uuid, 'Harga' => [['JumlahMinimum' => '1', 'Harga' => '7000'], ['JumlahMinimum' => '24', 'Harga' => '6500']]],
        ]])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($this->t['Tenant']->Id);
        expect(ProdukHarga::query()->where('IdDaftarHarga', $daftar->Id)->count())->toBe(3)
            ->and(RiwayatHarga::query()->where('IdDaftarHarga', $daftar->Id)->count())->toBe(3)
            ->and(LogAudit::query()->where('Peristiwa', 'daftar-harga.harga.ubah')->count())->toBe(1);

        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id)->put($url, ['Baris' => [
            ['UuidProdukSatuan' => $sabun->Uuid, 'Harga' => []],
            ['UuidProdukSatuan' => $teh->Uuid, 'Harga' => [['JumlahMinimum' => '1', 'Harga' => '7000'], ['JumlahMinimum' => '0', 'Harga' => '1']]],
        ]])->assertSessionHasErrors(['Baris.1.Harga.1.JumlahMinimum']);

        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id)->put($url, ['Baris' => [
            ['UuidProdukSatuan' => $sabun->Uuid, 'Harga' => []],
        ]])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($this->t['Tenant']->Id);
        expect(ProdukHarga::query()->where('IdDaftarHarga', $daftar->Id)->where('IdProdukSatuan', $sabun->Id)->exists())->toBeFalse()
            ->and(ProdukHarga::query()->where('IdDaftarHarga', $daftar->Id)->where('IdProdukSatuan', $teh->Id)->count())->toBe(2);
    });

    it('nonaktifkan & aktifkan (tanpa hapus), ubah pengaturan, audit', function (): void {
        $daftar = BantuanHarga::BuatDaftarHarga('Harga Member');
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id);

        $masuk()->post("/kelola/daftar-harga/{$daftar->Uuid}/nonaktifkan")->assertSessionHasNoErrors()->assertRedirect();
        expect($daftar->refresh()->Aktif)->toBeFalse();
        $masuk()->post("/kelola/daftar-harga/{$daftar->Uuid}/aktifkan")->assertSessionHasNoErrors();
        expect($daftar->refresh()->Aktif)->toBeTrue();

        $masuk()->put("/kelola/daftar-harga/{$daftar->Uuid}", IsiFormDaftarHarga(['Nama' => 'Harga Member Emas', 'TierPelanggan' => 'Emas', 'Prioritas' => '3']))->assertSessionHasNoErrors();
        expect($daftar->refresh()->only(['Nama', 'TierPelanggan', 'Prioritas']))->toBe(['Nama' => 'Harga Member Emas', 'TierPelanggan' => 'Emas', 'Prioritas' => 3]);

        BantuanOrganisasi::AturKonteks($this->t['Tenant']->Id);
        expect(LogAudit::query()->whereIn('Peristiwa', ['daftar-harga.nonaktifkan', 'daftar-harga.aktifkan', 'daftar-harga.ubah'])->count())->toBe(3);
    });

    it('izin & isolasi tenant: Kasir 403 pada mutasi; daftar harga tenant lain 404', function (): void {
        $daftar = BantuanHarga::BuatDaftarHarga('Harga Member');
        $kasir = fn () => BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id, PeranTenantBawaan::Kasir);

        $kasir()->get("/kelola/daftar-harga/{$daftar->Uuid}")->assertOk();
        $kasir()->post('/kelola/daftar-harga', IsiFormDaftarHarga(['Nama' => 'Baru']))->assertForbidden();
        $kasir()->put("/kelola/daftar-harga/{$daftar->Uuid}", IsiFormDaftarHarga())->assertForbidden();
        $kasir()->post("/kelola/daftar-harga/{$daftar->Uuid}/nonaktifkan")->assertForbidden();
        $kasir()->put("/kelola/daftar-harga/{$daftar->Uuid}/harga", ['Baris' => []])->assertForbidden();

        $lain = BantuanKatalog::SiapkanTenantProduk('Toko Makmur Jaya');
        BantuanFitur::Beri($lain['Tenant']->Id, 'harga.daftar-harga');
        $masukLain = fn () => BantuanKatalog::MasukSebagai($this, $lain['Tenant']->Id);
        $masukLain()->get("/kelola/daftar-harga/{$daftar->Uuid}")->assertNotFound();
        $masukLain()->put("/kelola/daftar-harga/{$daftar->Uuid}", IsiFormDaftarHarga())->assertNotFound();
        $masukLain()->post("/kelola/daftar-harga/{$daftar->Uuid}/nonaktifkan")->assertNotFound();
        $masukLain()->put("/kelola/daftar-harga/{$daftar->Uuid}/harga", ['Baris' => []])->assertNotFound();
        $masukLain()->get('/kelola/daftar-harga')->assertInertia(fn (AssertableInertia $h) => $h->where('DaftarHarga.Meta.Total', 0));
        $masukLain()->post('/kelola/daftar-harga', IsiFormDaftarHarga(['UuidOutlet' => [$this->solo->Uuid]]))->assertSessionHasErrors(['UuidOutlet']);

        BantuanOrganisasi::AturKonteks($this->t['Tenant']->Id);
        expect($daftar->refresh()->Aktif)->toBeTrue();
    });
});
