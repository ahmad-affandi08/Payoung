<?php

declare(strict_types=1);

use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * QA F-03 BR-03.1 (adversarial): SKU & barcode unik per tenant terhadap varian huruf besar/kecil dan spasi, kirim
 * ganda, SKU otomatis yang bertabrakan dengan SKU manual, pelepasan SKU/barcode produk terhapus, dan SKU anak varian
 * dari SKU induk sepanjang batas kolom.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('BR-03.1: SKU & barcode beda huruf/spasi dianggap sama; kirim ganda Uuid sama = satu produk', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
    $form = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Sku' => 'kopi-ab/01', 'Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', ['ab-8991234'])]]);

    $masuk()->post('/kelola/produk', $form)->assertSessionHasNoErrors();
    $masuk()->post('/kelola/produk', $form)->assertSessionHasNoErrors();

    $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Sku' => '  KOPI-AB/01  ']))
        ->assertSessionHasErrors(['Sku']);
    $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', [' AB-8991234 '])]]))
        ->assertSessionHasErrors(['Satuan.0.Barcode.0']);

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->count())->toBe(1)->and(ProdukBarcode::query()->count())->toBe(1);
});

it('BR-03.1: SKU otomatis melewati SKU manual PRD-000001; SKU & barcode produk terhapus boleh dipakai lagi', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Sku' => 'prd-000001']))->assertSessionHasNoErrors();
    $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Nama' => 'Teh Melati Celup 25 Kantong']))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->where('Nama', 'Teh Melati Celup 25 Kantong')->value('Sku'))->toBe('PRD-000002');

    $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Nama' => 'Gula Pasir 1 kg', 'Sku' => 'GULA-1KG', 'Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', ['8990000000017'])]]))
        ->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $gula = Produk::query()->where('Sku', 'GULA-1KG')->sole();
    $masuk()->delete("/kelola/produk/{$gula->Uuid}")->assertSessionHasNoErrors();

    $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Nama' => 'Gula Pasir Premium 1 kg', 'Sku' => 'gula-1kg', 'Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', ['8990000000017'])]]))
        ->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->where('Sku', 'gula-1kg')->value('Nama'))->toBe('Gula Pasir Premium 1 kg')
        ->and(Produk::query()->withTrashed()->whereKey($gula->Id)->value('Sku'))->toBeNull();
});

it('BR-03.1: SKU anak varian dari SKU induk 64 karakter tetap ≤ 64 karakter, unik, tanpa masalah sistem', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
    $skuInduk = 'KAOS-'.str_repeat('X', 59);
    expect(strlen($skuInduk))->toBe(64);

    $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], [
        'Nama' => 'Kaos Polos Katun Combed 30s', 'Sku' => $skuInduk, 'Jenis' => 'IndukVarian',
        'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => ['S', 'M', 'L']]],
    ]))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $induk = Produk::query()->where('Sku', $skuInduk)->sole();

    $masuk()->post("/kelola/produk/{$induk->Uuid}/varian", ['AtributVarian' => [], 'JenisAnak' => 'Stok', 'HargaDasar' => ''])
        ->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $skuAnak = Produk::query()->where('IdInduk', $induk->Id)->pluck('Sku')->all();
    expect($skuAnak)->toHaveCount(3)
        ->and(count(array_unique($skuAnak)))->toBe(3);

    foreach ($skuAnak as $sku) {
        expect(strlen((string) $sku))->toBeLessThanOrEqual(64)->and((string) $sku)->toMatch('/-\d{2}$/');
    }
});
