<?php

declare(strict_types=1);

use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Tenant\Enum\StatusLangganan;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Katalog\BantuanKomposisi;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * QA F-03 otorisasi: setiap rute yang mengubah katalog dicek per peran bawaan (Kasir & Supervisor hanya
 * produk.lihat; StafGudang hanya batas stok; Akuntan hanya kelompok pajak; ManajerOutlet produk.kelola tanpa
 * produk.harga.ubah), dan tenant ditangguhkan tidak bisa menulis walau Pemilik. Tidak satu baris pun berubah.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @return array<string, array{0: string, 1: string}> nama → [metode, url]
 */
function RuteTulisKatalogQa(array $t, Produk $produk, string $uuidSatuanProduk, string $uuidDaftar, string $uuidKelompokPilihan): array
{
    return [
        'produk.simpan' => ['post', '/kelola/produk'],
        'produk.perbarui' => ['put', "/kelola/produk/{$produk->Uuid}"],
        'produk.arsipkan' => ['post', "/kelola/produk/{$produk->Uuid}/arsipkan"],
        'produk.pulihkan' => ['post', "/kelola/produk/{$produk->Uuid}/pulihkan"],
        'produk.hapus' => ['delete', "/kelola/produk/{$produk->Uuid}"],
        'produk.gambar.simpan' => ['post', "/kelola/produk/{$produk->Uuid}/gambar"],
        'produk.gambar.hapus' => ['delete', "/kelola/produk/{$produk->Uuid}/gambar"],
        'produk.varian.generasi' => ['post', "/kelola/produk/{$produk->Uuid}/varian"],
        'produk.barcode-internal' => ['post', "/kelola/produk/{$produk->Uuid}/satuan/{$uuidSatuanProduk}/barcode-internal"],
        'produk.label.barcode-internal' => ['post', '/kelola/produk/label/barcode-internal'],
        'produk.batas-stok' => ['put', "/kelola/produk/{$produk->Uuid}/batas-stok"],
        'kategori.simpan' => ['post', '/kelola/kategori'],
        'satuan.simpan' => ['post', '/kelola/satuan'],
        'produk.harga.simpan' => ['put', "/kelola/produk/{$produk->Uuid}/harga"],
        'produk.harga.daftar-harga' => ['put', "/kelola/produk/{$produk->Uuid}/harga/daftar-harga/{$uuidDaftar}"],
        'daftar-harga.simpan' => ['post', '/kelola/daftar-harga'],
        'daftar-harga.ubah' => ['put', "/kelola/daftar-harga/{$uuidDaftar}"],
        'daftar-harga.nonaktifkan' => ['post', "/kelola/daftar-harga/{$uuidDaftar}/nonaktifkan"],
        'daftar-harga.harga' => ['put', "/kelola/daftar-harga/{$uuidDaftar}/harga"],
        'kelompok-pajak.simpan' => ['post', '/kelola/kelompok-pajak'],
        'kelompok-pajak.ubah' => ['put', "/kelola/kelompok-pajak/{$t['KelompokPajak']->Uuid}"],
        'kelompok-pilihan.simpan' => ['post', '/kelola/kelompok-pilihan'],
        'kelompok-pilihan.hapus' => ['delete', "/kelola/kelompok-pilihan/{$uuidKelompokPilihan}"],
        'produk.pilihan' => ['put', "/kelola/produk/{$produk->Uuid}/pilihan"],
        'produk.resep' => ['post', "/kelola/produk/{$produk->Uuid}/resep"],
        'produk.komponen' => ['put', "/kelola/produk/{$produk->Uuid}/komponen"],
        'produk.impor.unggah' => ['post', '/kelola/produk/impor'],
    ];
}

/** Rute yang BOLEH untuk peran (sisanya wajib 403). */
function RuteBolehPeranQa(PeranTenantBawaan $peran): array
{
    $katalog = ['produk.simpan', 'produk.perbarui', 'produk.arsipkan', 'produk.pulihkan', 'produk.hapus', 'produk.gambar.simpan', 'produk.gambar.hapus',
        'produk.varian.generasi', 'produk.barcode-internal', 'produk.label.barcode-internal', 'kategori.simpan', 'satuan.simpan', 'kelompok-pilihan.simpan', 'kelompok-pilihan.hapus',
        'produk.pilihan', 'produk.resep', 'produk.komponen', 'produk.impor.unggah'];

    return match ($peran) {
        PeranTenantBawaan::Kasir, PeranTenantBawaan::Supervisor, PeranTenantBawaan::StafPembelian => [],
        PeranTenantBawaan::StafGudang => ['produk.batas-stok'],
        PeranTenantBawaan::Akuntan => ['kelompok-pajak.simpan', 'kelompok-pajak.ubah'],
        PeranTenantBawaan::ManajerOutlet => [...$katalog, 'produk.batas-stok'],
        default => [],
    };
}

dataset('peran terbatas katalog', [
    'Kasir' => [PeranTenantBawaan::Kasir],
    'Supervisor' => [PeranTenantBawaan::Supervisor],
    'StafGudang' => [PeranTenantBawaan::StafGudang],
    'StafPembelian' => [PeranTenantBawaan::StafPembelian],
    'Akuntan' => [PeranTenantBawaan::Akuntan],
    'ManajerOutlet' => [PeranTenantBawaan::ManajerOutlet],
]);

it('rute tulis katalog di luar izin peran ditolak 403 dan tidak mengubah data', function (PeranTenantBawaan $peran): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    $produk = BantuanKatalog::BuatProduk(['IdKelompokPajak' => $t['KelompokPajak']->Id], '18000.00', $t['Pcs']);
    $satuanProduk = BantuanHarga::SatuanDasar($produk);
    $daftar = BantuanHarga::BuatDaftarHarga('Harga Grosir Pasar');
    $kelompok = BantuanKomposisi::BuatKelompokPilihan();
    $boleh = RuteBolehPeranQa($peran);
    $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, $peran);

    foreach (RuteTulisKatalogQa($t, $produk, $satuanProduk->Uuid, $daftar->Uuid, $kelompok->Uuid) as $nama => [$metode, $url]) {
        if (in_array($nama, $boleh, true)) {
            continue;
        }

        $status = $masuk->{$metode.'Json'}($url, [])->status();
        expect($status)->toBe(403, "{$peran->value} → {$nama} seharusnya 403, dapat {$status}");
    }

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->count())->toBe(1)
        ->and($produk->refresh()->DiarsipkanPada)->toBeNull()
        ->and(ProdukHarga::query()->where('IdProdukSatuan', $satuanProduk->Id)->value('Harga'))->toBe('18000.00');
})->with('peran terbatas katalog');

it('tenant ditangguhkan: Pemilik tidak bisa menulis katalog (423), tetapi tetap bisa melihat & mengekspor', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    $produk = BantuanKatalog::BuatProduk(['IdKelompokPajak' => $t['KelompokPajak']->Id], '18000.00', $t['Pcs']);
    $satuanProduk = BantuanHarga::SatuanDasar($produk);
    $daftar = BantuanHarga::BuatDaftarHarga('Harga Grosir Pasar');
    $kelompok = BantuanKomposisi::BuatKelompokPilihan();
    $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
    BantuanPerangkat::AturStatusLangganan($t['Tenant']->Id, StatusLangganan::Ditangguhkan);

    foreach (RuteTulisKatalogQa($t, $produk, $satuanProduk->Uuid, $daftar->Uuid, $kelompok->Uuid) as $nama => [$metode, $url]) {
        $status = $masuk->{$metode.'Json'}($url, [])->status();
        expect($status)->toBe(423, "{$nama} saat ditangguhkan seharusnya 423, dapat {$status}");
    }

    $masuk->get('/kelola/produk')->assertOk();
    $masuk->get('/kelola/produk/ekspor?format=csv')->assertOk();

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->count())->toBe(1)->and($produk->refresh()->DiarsipkanPada)->toBeNull();
});
