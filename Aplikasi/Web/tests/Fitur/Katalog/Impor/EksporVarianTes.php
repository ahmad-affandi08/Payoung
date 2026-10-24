<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Harga\Model\RiwayatHarga;
use App\Domain\Katalog\Impor\Enum\StatusImporProduk;
use App\Domain\Katalog\Impor\Kueri\DataEksporProduk;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Katalog\BantuanImpor;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

/**
 * @return list<list<string>>
 */
function BarisKatalogEkspor(): array
{
    return [
        ['Nama Produk', 'Nama Induk Varian', 'Varian', 'Jenis', 'SKU', 'Kategori', 'Merek', 'Satuan Dasar', 'Harga Jual', 'Barcode', 'Kelompok Pajak', 'Satuan Alternatif 1', 'Isi Satuan Alternatif 1', 'Harga Satuan Alternatif 1', 'Barcode Satuan Alternatif 1', 'Min. Qty Grosir 1', 'Harga Grosir 1'],
        ['Sabun Mandi Cair Aroma Sereh Wangi 450 ml', '', '', 'Barang stok', 'SBN-450', 'Kebutuhan Rumah > Sabun', 'Wangi Sari', 'pcs', '15.000', '8991234567890, 8991234567891', 'Barang kena PPN', 'Dus', '12', '170.000', '8991234567999', '6', '14.500'],
        ['=HYPERLINK("http://contoh.invalid","Klik")', '', '', 'Jasa', 'JSA-1', 'Layanan', '', 'pcs', '50000', '', 'Barang kena PPN', '', '', '', '', '', ''],
        ['Kaos Polos Katun Combed 30s', '', '', 'Induk varian', 'KAOS', 'Pakaian', 'Kaosku', 'pcs', '', '', 'Barang kena PPN', '', '', '', '', '', ''],
        ['Kaos Polos M', 'Kaos Polos Katun Combed 30s', 'Ukuran: M; Warna: Hitam', 'Barang stok', 'KAOS-M-H', '', '', 'pcs', '75.000', '', '', '', '', '', '', '', ''],
        ['Kaos Polos L', 'Kaos Polos Katun Combed 30s', 'Ukuran: L; Warna: Hitam', '', 'KAOS-L-H', '', '', 'pcs', '80.000', '', '', '', '', '', '', '', ''],
    ];
}

describe('F-03 impor varian', function (): void {
    it('preset Umum (kolom Nama Induk Varian): induk dari barisnya sendiri, anak lewat TambahVarianAnak, harga anak, BatasSku hanya anak', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatXlsx(BarisKatalogEkspor()));
        BantuanImpor::Petakan($masuk, $impor)->assertSessionHasNoErrors();
        $masuk->get("/kelola/produk/impor/{$impor->Uuid}")->assertInertia(fn ($h) => $h->where('Pratinjau.RingkasanAksi.Buat', 5)->has('Pratinjau.BarisGalat', 0));
        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $induk = Produk::query()->where('Sku', 'KAOS')->sole();
        $anak = Produk::query()->where('IdInduk', $induk->Id)->orderBy('Id')->get();

        expect($impor->refresh()->Status)->toBe(StatusImporProduk::Selesai)
            ->and($induk->Jenis)->toBe(JenisProduk::IndukVarian)
            ->and($induk->AtributVarian)->toBe([['Nama' => 'Ukuran', 'Nilai' => ['M', 'L']], ['Nama' => 'Warna', 'Nilai' => ['Hitam']]])
            ->and($anak->pluck('Sku')->all())->toBe(['KAOS-M-H', 'KAOS-L-H'])
            ->and($anak->pluck('KunciVarian')->all())->toBe(['ukuran=m|warna=hitam', 'ukuran=l|warna=hitam'])
            ->and($anak[0]->Nama)->toBe('Kaos Polos Katun Combed 30s M / Hitam')
            ->and($anak[0]->Merek)->toBe('Kaosku')
            ->and(ProdukHarga::query()->where('IdProduk', $anak[1]->Id)->sole()->Harga)->toBe('80000.00');
    });

    it('baris induk sesudah baris anak: induk dibuat dari baris induknya (SKU, pajak), lalu baris induk memperbarui', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv([
            ['Nama Produk', 'Nama Induk Varian', 'Varian', 'Jenis', 'SKU', 'Harga Jual', 'Kelompok Pajak', 'Merek'],
            ['Sandal M', 'Sandal Jepit Karet', 'Ukuran: 40', '', 'SDL-40', '25.000', '', ''],
            ['Sandal L', 'Sandal Jepit Karet', 'Ukuran: 42', '', 'SDL-42', '25.000', '', ''],
            ['Sandal Jepit Karet', '', '', 'Induk varian', 'SDL', '', 'Barang kena PPN', 'Swallow'],
        ]));
        BantuanImpor::Petakan($masuk, $impor)->assertSessionHasNoErrors();
        expect($impor->refresh()->JumlahValid)->toBe(3)->and($impor->JumlahGalat)->toBe(0);
        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $induk = Produk::query()->where('Sku', 'SDL')->sole();
        expect($impor->refresh()->JumlahGagal)->toBe(0)
            ->and($induk->Merek)->toBe('Swallow')
            ->and($induk->IdKelompokPajak)->toBe($t['KelompokPajak']->Id)
            ->and(Produk::query()->where('Jenis', JenisProduk::IndukVarian->value)->count())->toBe(1)
            ->and(Produk::query()->where('IdInduk', $induk->Id)->pluck('Sku')->sort()->values()->all())->toBe(['SDL-40', 'SDL-42']);
    });

    it('preset majoo (baris per varian): baris bernama sama dengan kolom Varian menjadi anak dari induk bernama itu', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv([
            ['Nama Produk', 'Nama Varian', 'Harga Jual', 'Kategori', 'Kode Produk'],
            ['Es Teh Manis', 'Jumbo', '8.000', 'Minuman', 'ETM-J'],
            ['Es Teh Manis', 'Reguler', '5.000', 'Minuman', 'ETM-R'],
            ['Nasi Goreng Kampung', '', '18.000', 'Makanan', 'NGK'],
        ]), 'Majoo');
        expect($impor->Pemetaan)->toMatchArray(['Nama' => 0, 'Varian' => 1, 'HargaJual' => 2, 'Kategori' => 3, 'Sku' => 4]);

        BantuanImpor::Petakan($masuk, $impor, ['UuidKelompokPajakBawaan' => $t['KelompokPajak']->Uuid])->assertSessionHasNoErrors();
        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $induk = Produk::query()->where('Nama', 'Es Teh Manis')->sole();
        expect($impor->refresh()->JumlahDibuat)->toBe(3)
            ->and($induk->Jenis)->toBe(JenisProduk::IndukVarian)
            ->and(Kategori::query()->findOrFail($induk->IdKategori)->Nama)->toBe('Minuman')
            ->and(Produk::query()->where('IdInduk', $induk->Id)->orderBy('Id')->pluck('Nama')->all())->toBe(['Es Teh Manis Jumbo', 'Es Teh Manis Reguler'])
            ->and(Produk::query()->where('Sku', 'NGK')->sole()->IdInduk)->toBeNull();
    });
});

describe('F-03 ekspor produk', function (): void {
    it('xlsx/csv sesuai filter aktif, kolom templat Umum, anak varian di bawah induk, formula injection dinetralkan, audit', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatXlsx(BarisKatalogEkspor()));
        BantuanImpor::Petakan($masuk, $impor)->assertSessionHasNoErrors();
        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        BantuanKatalog::BuatProduk(['Nama' => 'Produk Lama Diarsipkan', 'Aktif' => false, 'DiarsipkanPada' => now()], null, $t['Pcs']);
        $judul = DataEksporProduk::AmbilJudul();
        $kolom = fn (string $nama): int => (int) array_search($nama, $judul, true);

        $semua = BantuanImpor::BacaUnduhan($masuk->get('/kelola/produk/ekspor?format=xlsx')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        expect($semua[0])->toBe($judul)
            ->and(array_column($semua, $kolom('SKU')))->toBe(['SKU', 'SBN-450', 'JSA-1', 'KAOS', 'KAOS-M-H', 'KAOS-L-H']);

        $sabun = $semua[1];
        expect($sabun[$kolom('Kategori')])->toBe('Kebutuhan Rumah > Sabun')
            ->and($sabun[$kolom('Jenis')])->toBe('Barang stok')
            ->and($sabun[$kolom('Satuan Dasar')])->toBe('Pieces')
            ->and($sabun[$kolom('Harga Jual')])->toBe('15000')
            ->and($sabun[$kolom('Barcode')])->toBe('8991234567890, 8991234567891')
            ->and($sabun[$kolom('Kelompok Pajak')])->toBe('Barang kena PPN')
            ->and($sabun[$kolom('Harga Termasuk Pajak')])->toBe('Ikut outlet')
            ->and($sabun[$kolom('Satuan Alternatif 1')])->toBe('Dus')
            ->and($sabun[$kolom('Isi Satuan Alternatif 1')])->toBe('12')
            ->and($sabun[$kolom('Harga Satuan Alternatif 1')])->toBe('170000')
            ->and($sabun[$kolom('Barcode Satuan Alternatif 1')])->toBe('8991234567999')
            ->and($sabun[$kolom('Min. Qty Grosir 1')])->toBe('6')
            ->and($sabun[$kolom('Harga Grosir 1')])->toBe('14500')
            ->and($sabun[$kolom('Status')])->toBe('Aktif')
            ->and($semua[2][$kolom('Nama Produk')])->toBe('\'=HYPERLINK("http://contoh.invalid","Klik")')
            ->and($semua[4][$kolom('Nama Induk Varian')])->toBe('Kaos Polos Katun Combed 30s')
            ->and($semua[4][$kolom('Varian')])->toBe('Ukuran: M; Warna: Hitam');

        $uuidSabun = Kategori::query()->where('Nama', 'Sabun')->sole()->Uuid;
        $uuidRumah = Kategori::query()->where('Nama', 'Kebutuhan Rumah')->sole()->Uuid;
        $perKategori = BantuanImpor::BacaUnduhan($masuk->get("/kelola/produk/ekspor?format=csv&saring[Kategori]={$uuidRumah}")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8'), 'csv');
        expect(array_column($perKategori, $kolom('SKU')))->toBe(['SKU', 'SBN-450']);
        expect(array_column(BantuanImpor::BacaUnduhan($masuk->get("/kelola/produk/ekspor?format=csv&saring[Kategori]={$uuidSabun}&kata=sabun"), 'csv'), $kolom('SKU')))->toBe(['SKU', 'SBN-450'])
            ->and(array_column(BantuanImpor::BacaUnduhan($masuk->get('/kelola/produk/ekspor?format=csv&saring[Jenis]=Jasa'), 'csv'), $kolom('SKU')))->toBe(['SKU', 'JSA-1'])
            ->and(BantuanImpor::BacaUnduhan($masuk->get('/kelola/produk/ekspor?format=csv&saring[Status]=Diarsipkan'), 'csv'))->toHaveCount(2)
            ->and(BantuanImpor::BacaUnduhan($masuk->get('/kelola/produk/ekspor?format=csv&saring[Status]=Semua'), 'csv'))->toHaveCount(7)
            ->and(BantuanImpor::BacaUnduhan($masuk->get('/kelola/produk/ekspor?format=csv&saring[Kategori]=01JBTIDAKADA0000000000000A'), 'csv'))->toHaveCount(1);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(LogAudit::query()->where('Peristiwa', 'produk.ekspor')->count())->toBe(7)
            ->and(LogAudit::query()->where('Peristiwa', 'produk.ekspor')->orderBy('Id')->first()?->NilaiBaru)->toMatchArray(['Format' => 'xlsx', 'Status' => 'Aktif']);
    });

    it('round trip: ekspor → impor kembali (TambahDanPerbarui) = 0 produk baru, tanpa perubahan harga/barcode/satuan', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $awal = BantuanImpor::Unggah($masuk, BantuanImpor::BuatXlsx(BarisKatalogEkspor()));
        BantuanImpor::Petakan($masuk, $awal)->assertSessionHasNoErrors();
        $masuk->post("/kelola/produk/impor/{$awal->Uuid}/terapkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $sebelum = [Produk::query()->count(), ProdukSatuan::query()->count(), ProdukBarcode::query()->count(), ProdukHarga::query()->count(), RiwayatHarga::query()->count(), Kategori::query()->count()];

        foreach (['xlsx', 'csv'] as $format) {
            $isi = $masuk->get("/kelola/produk/ekspor?format={$format}")->assertOk()->streamedContent();
            $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatBerkasMentah($isi, "ekspor-produk.{$format}"));
            BantuanImpor::Petakan($masuk, $impor)->assertSessionHasNoErrors();
            $masuk->get("/kelola/produk/impor/{$impor->Uuid}")->assertInertia(fn ($h) => $h
                ->has('Pratinjau.BarisGalat', 0)
                ->where('Pratinjau.RingkasanAksi', ['Buat' => 0, 'Perbarui' => 5, 'Lewati' => 0]));
            $masuk->post("/kelola/produk/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();

            BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
            expect($impor->refresh()->Status)->toBe(StatusImporProduk::Selesai)
                ->and($impor->JumlahDibuat)->toBe(0)
                ->and($impor->JumlahDiperbarui)->toBe(5)
                ->and($impor->JumlahGagal)->toBe(0)
                ->and([Produk::query()->count(), ProdukSatuan::query()->count(), ProdukBarcode::query()->count(), ProdukHarga::query()->count(), RiwayatHarga::query()->count(), Kategori::query()->count()])->toBe($sebelum)
                ->and(Produk::query()->where('Sku', 'JSA-1')->sole()->Nama)->toBe('=HYPERLINK("http://contoh.invalid","Klik")');
        }
    });
});
