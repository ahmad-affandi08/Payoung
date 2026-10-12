<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Audit kemudahan pakai #19 (F-03): aksi massal produk terpilih — pindah kategori, sembunyikan/tampilkan di kasir,
 * arsipkan/pulihkan. Lewat Aksi satuan (audit per produk), semua-atau-tidak, Uuid tenant lain ditolak.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('massal: pindah kategori, sembunyikan dari kasir, arsipkan; Uuid asing menggagalkan semuanya', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Susu Gula Aren Literan', 'Sku' => 'KSG-1L', 'IdKelompokPajak' => $t['KelompokPajak']->Id], '85000.00', $t['Pcs']);
    $teh = BantuanKatalog::BuatProduk(['Nama' => 'Teh Melati Botol 1 Liter', 'Sku' => 'TMB-1L', 'IdKelompokPajak' => $t['KelompokPajak']->Id], '35000.00', $t['Pcs']);
    $kategori = Kategori::query()->create(['Nama' => 'Minuman Literan']);
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
    $uuid = [$kopi->Uuid, $teh->Uuid];

    $masuk()->post('/kelola/produk/massal', ['Aksi' => 'Kategori', 'Uuid' => $uuid, 'UuidKategori' => $kategori->Uuid])
        ->assertSessionHasNoErrors()->assertSessionHas('Kilat', '2 produk dipindah kategorinya.');
    $masuk()->post('/kelola/produk/massal', ['Aksi' => 'SembunyikanDariPos', 'Uuid' => $uuid])->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    foreach ([$kopi, $teh] as $p) {
        $p->refresh();
        expect($p->IdKategori)->toBe($kategori->Id)->and($p->TampilDiPos)->toBeFalse();
    }

    // Satu Uuid asing (tidak ada di tenant) = tidak ada yang diarsipkan.
    $masuk()->post('/kelola/produk/massal', ['Aksi' => 'Arsipkan', 'Uuid' => [$kopi->Uuid, '01J9ZZZZZZZZZZZZZZZZZZZZZZ']])
        ->assertSessionHasErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->where('Aktif', false)->count())->toBe(0);

    $masuk()->post('/kelola/produk/massal', ['Aksi' => 'Arsipkan', 'Uuid' => $uuid])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->where('Aktif', false)->count())->toBe(2)
        ->and(LogAudit::query()->where('Peristiwa', 'produk.arsipkan')->count())->toBe(2);

    $masuk()->post('/kelola/produk/massal', ['Aksi' => 'Kategori', 'Uuid' => $uuid])->assertSessionHasErrors('UuidKategori');
});

it('K-16 massal: Favorit & HapusFavorit lewat Aksi satuan (audit per produk); BahanBaku tetap bukan favorit; Uuid tenant lain ditolak', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Susu Gula Aren Literan', 'Sku' => 'KSG-1L', 'IdKelompokPajak' => $t['KelompokPajak']->Id], '85000.00', $t['Pcs']);
    $teh = BantuanKatalog::BuatProduk(['Nama' => 'Teh Melati Botol 1 Liter', 'Sku' => 'TMB-1L', 'IdKelompokPajak' => $t['KelompokPajak']->Id], '35000.00', $t['Pcs']);
    $gula = BantuanKatalog::BuatProduk(['Nama' => 'Gula Aren Cair Premium 1 kg', 'Jenis' => JenisProduk::BahanBaku, 'TampilDiPos' => false], null, $t['Pcs']);
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $masuk()->post('/kelola/produk/massal', ['Aksi' => 'Favorit', 'Uuid' => [$kopi->Uuid, $teh->Uuid, $gula->Uuid]])
        ->assertSessionHasNoErrors()->assertSessionHas('Kilat', '3 produk ditandai favorit di kasir.');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect($kopi->refresh()->Favorit)->toBeTrue()
        ->and($teh->refresh()->Favorit)->toBeTrue()
        ->and($gula->refresh()->Favorit)->toBeFalse()
        ->and(LogAudit::query()->where('Peristiwa', 'produk.ubah')->count())->toBe(2);

    $masuk()->post('/kelola/produk/massal', ['Aksi' => 'HapusFavorit', 'Uuid' => [$kopi->Uuid]])
        ->assertSessionHasNoErrors()->assertSessionHas('Kilat', '1 produk dilepas dari favorit kasir.');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect($kopi->refresh()->Favorit)->toBeFalse()->and($teh->refresh()->Favorit)->toBeTrue();

    // Isolasi tenant: produk tenant lain tidak bisa ditandai dari tenant ini, dan tidak berubah.
    $lain = BantuanKatalog::SiapkanTenantProduk('Toko Tenant Lain');
    $asing = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng 2 Liter', 'IdKelompokPajak' => $lain['KelompokPajak']->Id], '38000.00', $lain['Pcs']);
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $masuk()->post('/kelola/produk/massal', ['Aksi' => 'Favorit', 'Uuid' => [$kopi->Uuid, $asing->Uuid]])->assertSessionHasErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect($kopi->refresh()->Favorit)->toBeFalse();
    BantuanOrganisasi::AturKonteks($lain['Tenant']->Id);
    expect($asing->refresh()->Favorit)->toBeFalse();
});
