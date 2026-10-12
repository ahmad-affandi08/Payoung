<?php

declare(strict_types=1);

use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukGudang;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Outlet;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * QA F-03 isolasi tenant lewat Uuid di BODY (bukan di URL): tenant A mengirim Uuid milik tenant B pada setiap bidang
 * rujukan form. Semua ditolak sebagai "tidak ditemukan" dan tidak ada data A maupun B yang berubah.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('form produk, kategori, daftar harga, harga daftar, dan batas stok menolak Uuid tenant lain di body', function (): void {
    $b = BantuanKatalog::SiapkanTenantProduk('Toko Tetangga Sebelah');
    $kategoriB = BantuanKatalog::BuatKategori('Kategori Rahasia B');
    $produkB = BantuanKatalog::BuatProduk(['Nama' => 'Produk Rahasia B'], '5000.00', $b['Pcs']);
    $satuanProdukB = BantuanHarga::SatuanDasar($produkB);
    $outletB = Outlet::query()->orderBy('Id')->firstOrFail();
    $gudangB = Gudang::query()->orderBy('Id')->firstOrFail();

    $a = BantuanKatalog::SiapkanTenantProduk('Toko Sumber Rejeki');
    BantuanFitur::Beri($a['Tenant']->Id, 'harga.daftar-harga');
    $produkA = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Bubuk Robusta 250 gram', 'IdKelompokPajak' => $a['KelompokPajak']->Id], '35000.00', $a['Pcs']);
    $satuanProdukA = BantuanHarga::SatuanDasar($produkA);
    $daftarA = BantuanHarga::BuatDaftarHarga('Harga Grosir A');
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $a['Tenant']->Id);

    $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($a['Pcs'], $a['KelompokPajak'], ['UuidKategori' => $kategoriB->Uuid]))->assertSessionHasErrors('UuidKategori');
    $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($a['Pcs'], $a['KelompokPajak'], ['UuidSatuanDasar' => $b['Pcs']->Uuid]))->assertSessionHasErrors('UuidSatuanDasar');
    $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($a['Pcs'], $a['KelompokPajak'], ['UuidKelompokPajak' => $b['KelompokPajak']->Uuid]))->assertSessionHasErrors('UuidKelompokPajak');
    $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($a['Pcs'], $a['KelompokPajak'], ['Satuan' => [BantuanKatalog::IsiSatuanForm($b['Pcs'])]]))->assertSessionHasErrors('Satuan.0.UuidSatuan');
    $masuk()->put("/kelola/produk/{$produkA->Uuid}", BantuanKatalog::IsiFormProduk($a['Pcs'], $a['KelompokPajak'], [
        'Satuan' => [BantuanKatalog::IsiSatuanForm($a['Pcs'], '1', [], [], $satuanProdukB->Uuid)],
    ]))->assertSessionHasErrors('Satuan.0.Uuid');

    $masuk()->post('/kelola/kategori', ['Nama' => 'Anak Curian', 'UuidInduk' => $kategoriB->Uuid])->assertSessionHasErrors('UuidInduk');
    $masuk()->post('/kelola/daftar-harga', ['Nama' => 'Harga Outlet Curian', 'UuidOutlet' => [$outletB->Uuid], 'Kanal' => null, 'TierPelanggan' => null, 'MulaiPada' => null, 'SelesaiPada' => null, 'Prioritas' => 1])
        ->assertSessionHasErrors('UuidOutlet');
    $masuk()->put("/kelola/daftar-harga/{$daftarA->Uuid}/harga", ['Baris' => [['UuidProdukSatuan' => $satuanProdukB->Uuid, 'Harga' => [['JumlahMinimum' => '1', 'Harga' => '1']]]]])
        ->assertSessionHasErrors('Baris.0.UuidProdukSatuan');
    $masuk()->put("/kelola/produk/{$produkA->Uuid}/batas-stok", ['Baris' => [['UuidGudang' => $gudangB->Uuid, 'StokMinimum' => '1', 'StokMaksimum' => '9']]])
        ->assertSessionHasErrors('Baris.0.UuidGudang');

    BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
    expect(Produk::query()->count())->toBe(1)
        ->and(Kategori::query()->count())->toBe(0)
        ->and(ProdukGudang::query()->count())->toBe(0)
        ->and(ProdukSatuan::query()->whereKey($satuanProdukA->Id)->value('IdSatuan'))->toBe($a['Pcs']->Id);

    BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
    expect(Produk::query()->count())->toBe(1)
        ->and(ProdukSatuan::query()->whereKey($satuanProdukB->Id)->value('IdProduk'))->toBe($produkB->Id);
});
