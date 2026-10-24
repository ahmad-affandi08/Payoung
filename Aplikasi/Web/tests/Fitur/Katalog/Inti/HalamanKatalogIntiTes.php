<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukGudang;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Gudang;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-03 halaman katalog Tim 1 (prop kontrak E)', function (): void {
    it('Kelola/Produk/Daftar: filter, anak varian tersembunyi, harga dasar string, BatasSku, Izin', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $minuman = BantuanKatalog::BuatKategori('Minuman');
        $kopi = BantuanKatalog::BuatKategori('Kopi', $minuman);
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Es Kopi Susu Gula Aren', 'IdKategori' => $kopi->Id, 'Merek' => 'Kopi Kenangan'], '22000.00', $t['Pcs']);
        $induk = BantuanKatalog::BuatProduk(['Nama' => 'Kaos Polos', 'Jenis' => JenisProduk::IndukVarian], null, $t['Pcs']);
        BantuanKatalog::BuatProduk(['Nama' => 'Kaos Polos S', 'IdInduk' => $induk->Id, 'KunciVarian' => 'ukuran=s'], null, $t['Pcs']);
        BantuanKatalog::BuatProduk(['Nama' => 'Arsip Lama', 'Aktif' => false, 'DiarsipkanPada' => now()], null, $t['Pcs']);
        ProdukBarcode::query()->create(['IdProduk' => $produk->Id, 'IdProdukSatuan' => ProdukSatuan::query()->where('IdProduk', $produk->Id)->value('Id'), 'Barcode' => '8990000000123']);
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Kasir);

        $masuk()->get('/kelola/produk')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Produk/Daftar')
            ->has('Produk.Data', 2)
            ->where('Produk.Meta.Total', 2)
            ->where('Produk.Data.0.Nama', 'Es Kopi Susu Gula Aren')
            ->where('Produk.Data.0.HargaDasar', '22000.00')
            ->where('Produk.Data.0.NamaKategori', 'Kopi')
            ->where('Produk.Data.0.SimbolSatuan', 'pcs')
            ->where('Produk.Data.1.JumlahVarian', 1)
            ->missing('Saring')
            ->has('Kategori', 2)
            ->has('Jenis', 9)
            ->where('BatasSku', ['Batas' => null, 'Terpakai' => 2])
            ->where('Izin', ['Kelola' => false, 'UbahHarga' => false, 'KelolaPersediaan' => false, 'KelolaPajak' => false]));

        // `kata` (nama lama) tetap diterima; `cari` = kontrak TabelData D-16. Barcode dicocokkan persis.
        $masuk()->get('/kelola/produk?kata=8990000000123')->assertInertia(fn (AssertableInertia $h) => $h->has('Produk.Data', 1)->where('Produk.Data.0.Uuid', $produk->Uuid));
        $masuk()->getJson('/kelola/produk?cari=8990000000123')->assertOk()->assertJsonPath('Data.0.Uuid', $produk->Uuid)->assertJsonPath('Meta.Total', 1);
        $masuk()->getJson('/kelola/produk?urut=-Nama')->assertOk()->assertJsonPath('Data.0.Nama', 'Kaos Polos');
        $masuk()->get("/kelola/produk?saring[Kategori]={$minuman->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h->has('Produk.Data', 1)->where('Produk.Data.0.Nama', 'Es Kopi Susu Gula Aren'));
        $masuk()->get('/kelola/produk?saring[Status]=Diarsipkan')->assertInertia(fn (AssertableInertia $h) => $h->has('Produk.Data', 1)->where('Produk.Data.0.Status', 'Diarsipkan'));
        $masuk()->get('/kelola/produk?saring[Status]=Semua&saring[Jenis]=IndukVarian')->assertInertia(fn (AssertableInertia $h) => $h->has('Produk.Data', 1)->where('Produk.Data.0.Nama', 'Kaos Polos'));
    });

    it('Kelola/Produk/Form (Buat & Ubah) dan Kelola/Produk/Detail', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Beras Pandan Wangi 5 kg', 'IdKelompokPajak' => $t['KelompokPajak']->Id, 'HargaTermasukPajak' => false], '78500.00', $t['Kg']);
        $satuan = ProdukSatuan::query()->where('IdProduk', $produk->Id)->sole();
        ProdukBarcode::query()->create(['IdProduk' => $produk->Id, 'IdProdukSatuan' => $satuan->Id, 'Barcode' => '8990000000999']);
        ProdukGudang::query()->create(['IdProduk' => $produk->Id, 'IdGudang' => Gudang::query()->value('Id'), 'StokMinimum' => '3.5']);
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->get('/kelola/produk/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Produk/Form')
            ->where('Mode', 'Buat')
            ->where('Kepala', null)
            ->where('Produk.UuidSatuanDasar', $t['Pcs']->Uuid)
            ->where('Produk.HargaTermasukPajak', 'Ikut')
            ->where('Produk.Satuan.0.KonversiKeDasar', '1')
            ->has('Satuan', 2)
            ->where('KelompokPajak.0.Uuid', $t['KelompokPajak']->Uuid)
            ->where('JenisTerkunci', false)
            ->has('Pengaturan.HargaTermasukPajakOutlet')
            ->where('Izin.UbahHarga', true));

        $masuk()->get("/kelola/produk/{$produk->Uuid}/ubah")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Produk/Form')
            ->where('Mode', 'Ubah')
            ->where('Kepala.Uuid', $produk->Uuid)
            ->where('Produk.Uuid', $produk->Uuid)
            ->where('Produk.HargaTermasukPajak', 'Tidak')
            ->where('Produk.Satuan.0', ['Uuid' => $satuan->Uuid, 'UuidSatuan' => $t['Kg']->Uuid, 'KonversiKeDasar' => '1.0000', 'DefaultJual' => true, 'DefaultBeli' => true, 'Barcode' => ['8990000000999'], 'HargaAwal' => []]));

        $masuk()->get("/kelola/produk/{$produk->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Produk/Detail')
            ->where('Kepala.Nama', 'Beras Pandan Wangi 5 kg')
            ->where('Produk.SatuanDasar', ['Uuid' => $t['Kg']->Uuid, 'Nama' => 'Kilogram', 'Simbol' => 'kg', 'BolehDesimal' => true])
            ->where('Produk.KelompokPajak.Uuid', $t['KelompokPajak']->Uuid)
            ->where('Produk.Satuan.0.BisaDijual', true)
            ->where('Produk.Satuan.0.Barcode.0.Barcode', '8990000000999')
            ->where('Produk.AlasanTidakBisaDihapus', null)
            ->where('Varian', [])
            ->where('BatasStok.0.StokMinimum', '3.5000')
            ->where('BatasStok.0.StokMaksimum', '')
            ->has('Riwayat')
            ->has('BatasSku'));
    });

    it('Kelola/Kategori/Daftar dan Kelola/Satuan/Daftar', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $minuman = BantuanKatalog::BuatKategori('Minuman');
        $kopi = BantuanKatalog::BuatKategori('Kopi', $minuman);
        BantuanKatalog::BuatProduk(['IdKategori' => $kopi->Id], null, $t['Pcs']);
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->get('/kelola/kategori')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Kategori/Daftar')
            ->where('Kategori.1', ['Uuid' => $kopi->Uuid, 'Nama' => 'Kopi', 'Jalur' => 'Minuman › Kopi', 'Kedalaman' => 2, 'UuidInduk' => $minuman->Uuid, 'JumlahProduk' => 1, 'Urutan' => 0, 'UuidStasiunDapur' => null, 'NamaStasiunDapur' => null])
            ->where('OpsiStasiunDapur', [])
            ->has('Izin'));
        $masuk()->get('/kelola/satuan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Satuan/Daftar')
            ->where('Satuan.1', ['Uuid' => $t['Pcs']->Uuid, 'Nama' => 'Pieces', 'Simbol' => 'pcs', 'BolehDesimal' => false, 'KodeStandar' => 'PCS', 'JumlahProduk' => 1])
            ->has('Izin'));
    });
});

describe('F-03 pemilih produk (D.2)', function (): void {
    it('GET /kelola/produk/cari: nama/SKU/barcode, filter jenis, satuan dengan konversi string, tanpa produk arsip', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $dus = BantuanKatalog::BuatSatuan('Dus', 'dus');
        $susu = BantuanKatalog::BuatProduk(['Nama' => 'Susu UHT Full Cream 1 L', 'Sku' => 'SUSU-1L', 'Jenis' => JenisProduk::BahanBaku], null, $t['Pcs']);
        ProdukSatuan::query()->create(['IdProduk' => $susu->Id, 'IdSatuan' => $dus->Id, 'KonversiKeDasar' => '12', 'DefaultJual' => false, 'DefaultBeli' => false]);
        BantuanKatalog::BuatProduk(['Nama' => 'Susu Kental Manis Kaleng', 'Jenis' => JenisProduk::Stok], null, $t['Pcs']);
        BantuanKatalog::BuatProduk(['Nama' => 'Susu Arsip', 'Jenis' => JenisProduk::BahanBaku, 'Aktif' => false, 'DiarsipkanPada' => now()], null, $t['Pcs']);

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->getJson('/kelola/produk/cari?kata=susu&jenis[]=BahanBaku')
            ->assertOk()
            ->assertExactJson(['Data' => [[
                'Uuid' => $susu->Uuid,
                'Nama' => 'Susu UHT Full Cream 1 L',
                'Sku' => 'SUSU-1L',
                'Jenis' => 'BahanBaku',
                'UuidSatuanDasar' => $t['Pcs']->Uuid,
                'Satuan' => [
                    ['Uuid' => $t['Pcs']->Uuid, 'Nama' => 'Pieces', 'Simbol' => 'pcs', 'BolehDesimal' => false, 'KonversiKeDasar' => '1.0000'],
                    ['Uuid' => $dus->Uuid, 'Nama' => 'Dus', 'Simbol' => 'dus', 'BolehDesimal' => false, 'KonversiKeDasar' => '12.0000'],
                ],
            ]]]);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->getJson('/kelola/produk/cari?kata=SUSU-1')->assertJsonCount(1, 'Data');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->getJson('/kelola/produk/cari?kata=susu')->assertJsonCount(2, 'Data');
    });
});
