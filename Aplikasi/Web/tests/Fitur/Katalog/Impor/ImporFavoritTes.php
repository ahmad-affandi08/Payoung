<?php

declare(strict_types=1);

use App\Domain\Katalog\Impor\Enum\BidangImpor;
use App\Domain\Katalog\Impor\Kueri\DataEksporProduk;
use App\Domain\Katalog\Model\Produk;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Katalog\BantuanImpor;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * K-16 (F-03): kolom "Favorit" (Ya/Tidak) di impor/ekspor produk, mengikuti pola "Tampil di POS". Kolom tidak ada atau sel
 * kosong = favorit TIDAK diubah, sehingga berkas impor lama tidak mereset pilihan pemilik (CLAUDE.md #16). Bahan baku
 * tidak pernah favorit (AturanProduk::TentukanFavorit).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

/**
 * Unggah → petakan otomatis → terapkan.
 *
 * @param  list<list<string|int|null>>  $baris
 */
function TerapkanImporFavorit(mixed $masuk, array $baris, string $uuidKelompokPajak): void
{
    $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatXlsx($baris));
    BantuanImpor::Petakan($masuk, $impor, ['UuidKelompokPajakBawaan' => $uuidKelompokPajak])->assertSessionHasNoErrors();
    $masuk->post("/kelola/produk/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();
}

describe('K-16 impor kolom Favorit', function (): void {
    it('kolom Favorit (Ya/Tidak) disimpan untuk produk baru dan memperbarui produk yang sudah ada', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        TerapkanImporFavorit($masuk, [
            ['Nama Produk', 'SKU', 'Satuan Dasar', 'Harga Jual', 'Favorit'],
            ['Es Kopi Susu Gula Aren', 'EKS-1', 'pcs', '22.000', 'Ya'],
            ['Roti Bakar Cokelat Keju', 'RBC-1', 'pcs', '18.000', 'Tidak'],
            ['Teh Tarik Dingin', 'TTD-1', 'pcs', '15.000', ''],
        ], $t['KelompokPajak']->Uuid);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $favorit = fn (string $sku): bool => Produk::query()->where('Sku', $sku)->sole()->Favorit;
        expect($favorit('EKS-1'))->toBeTrue()
            ->and($favorit('RBC-1'))->toBeFalse()
            ->and($favorit('TTD-1'))->toBeFalse();

        // Baris yang sama diimpor lagi dengan nilai terbalik: produk diperbarui, bukan dibuat ulang.
        TerapkanImporFavorit($masuk, [
            ['Nama Produk', 'SKU', 'Favorit'],
            ['Es Kopi Susu Gula Aren', 'EKS-1', 'Tidak'],
            ['Roti Bakar Cokelat Keju', 'RBC-1', 'ya'],
        ], $t['KelompokPajak']->Uuid);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($favorit('EKS-1'))->toBeFalse()
            ->and($favorit('RBC-1'))->toBeTrue()
            ->and(Produk::query()->count())->toBe(3);
    });

    it('impor tanpa kolom Favorit atau dengan sel kosong tidak mereset favorit produk yang sudah ada', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        BantuanKatalog::BuatProduk(['Nama' => 'Nasi Goreng Kampung Spesial', 'Sku' => 'NGK-1', 'Favorit' => true, 'IdKelompokPajak' => $t['KelompokPajak']->Id], '25000.00', $t['Pcs']);
        BantuanKatalog::BuatProduk(['Nama' => 'Mie Goreng Jawa Pedas', 'Sku' => 'MGJ-1', 'Favorit' => true, 'IdKelompokPajak' => $t['KelompokPajak']->Id], '23000.00', $t['Pcs']);

        // Berkas lama tanpa kolom Favorit (klien/templat lama) mengubah harga & nama struk.
        TerapkanImporFavorit($masuk, [
            ['Nama Produk', 'SKU', 'Nama di Struk', 'Harga Jual'],
            ['Nasi Goreng Kampung Spesial', 'NGK-1', 'Nasi Goreng Spesial', '26.000'],
        ], $t['KelompokPajak']->Uuid);
        // Kolom ada tetapi selnya kosong: juga tidak diubah.
        TerapkanImporFavorit($masuk, [
            ['Nama Produk', 'SKU', 'Nama di Struk', 'Favorit'],
            ['Mie Goreng Jawa Pedas', 'MGJ-1', 'Mie Goreng Pedas', ''],
        ], $t['KelompokPajak']->Uuid);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $ngk = Produk::query()->where('Sku', 'NGK-1')->sole();
        $mgj = Produk::query()->where('Sku', 'MGJ-1')->sole();
        expect($ngk->NamaStruk)->toBe('Nasi Goreng Spesial')
            ->and($ngk->Favorit)->toBeTrue()
            ->and($mgj->NamaStruk)->toBe('Mie Goreng Pedas')
            ->and($mgj->Favorit)->toBeTrue();
    });

    it('nilai Favorit yang tidak dikenali menjadi masalah baris, bukan mengubah apa pun', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatXlsx([
            ['Nama Produk', 'SKU', 'Favorit'],
            ['Kopi Hitam Tubruk', 'KHT-1', 'mungkin'],
        ]));
        BantuanImpor::Petakan($masuk, $impor)->assertSessionHasNoErrors();
        $masuk->get("/kelola/produk/impor/{$impor->Uuid}")->assertInertia(fn ($h) => $h
            ->has('Pratinjau.BarisGalat', 1)
            ->where('Pratinjau.BarisGalat.0.Galat.0.Bidang', 'Favorit'));
        expect(Produk::query()->where('Sku', 'KHT-1')->exists())->toBeFalse();
    });

    it('bahan baku tidak menjadi favorit lewat impor (baru maupun yang sudah ada)', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        BantuanKatalog::BuatProduk(['Nama' => 'Kopi Robusta Sangrai 500 gram', 'Sku' => 'KRS-1', 'IdKelompokPajak' => $t['KelompokPajak']->Id], '85000.00', $t['Pcs']);

        TerapkanImporFavorit($masuk, [
            ['Nama Produk', 'SKU', 'Jenis', 'Harga Jual', 'Favorit'],
            ['Gula Aren Cair Premium 1 kg', 'GAC-1', 'Bahan baku', '', 'Ya'],
            ['Kopi Robusta Sangrai 500 gram', 'KRS-1', 'Bahan baku', '', 'Ya'],
        ], $t['KelompokPajak']->Uuid);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->where('Sku', 'GAC-1')->sole()->Favorit)->toBeFalse()
            ->and(Produk::query()->where('Sku', 'KRS-1')->sole()->Favorit)->toBeFalse();
    });
});

describe('K-16 ekspor kolom Favorit', function (): void {
    it('ekspor memuat kolom Favorit (Ya/Tidak) dan round trip ekspor → impor ulang mempertahankan favorit', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        TerapkanImporFavorit($masuk, [
            ['Nama Produk', 'SKU', 'Satuan Dasar', 'Harga Jual', 'Favorit'],
            ['Es Kopi Susu Gula Aren', 'EKS-1', 'pcs', '22.000', 'Ya'],
            ['Roti Bakar Cokelat Keju', 'RBC-1', 'pcs', '18.000', 'Tidak'],
        ], $t['KelompokPajak']->Uuid);
        $judul = DataEksporProduk::AmbilJudul();
        $kolomFavorit = (int) array_search(BidangImpor::Favorit->AmbilJudul(), $judul, true);
        $kolomSku = (int) array_search('SKU', $judul, true);

        expect($judul)->toContain('Favorit');

        foreach (['xlsx', 'csv'] as $format) {
            $unduhan = BantuanImpor::BacaUnduhan($masuk->get("/kelola/produk/ekspor?format={$format}")->assertOk(), $format);
            $perSku = collect(array_slice($unduhan, 1))->mapWithKeys(fn (array $baris): array => [$baris[$kolomSku] => $baris[$kolomFavorit]])->all();
            expect($unduhan[0][$kolomFavorit])->toBe('Favorit')
                ->and($perSku)->toBe(['EKS-1' => 'Ya', 'RBC-1' => 'Tidak']);
        }

        // Balik nilai di database, impor ulang berkas ekspor yang lama: favorit kembali ke nilai di berkas.
        $isi = $masuk->get('/kelola/produk/ekspor?format=csv')->assertOk()->streamedContent();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        Produk::query()->where('Sku', 'EKS-1')->update(['Favorit' => false]);
        Produk::query()->where('Sku', 'RBC-1')->update(['Favorit' => true]);

        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatBerkasMentah($isi, 'ekspor-produk.csv'));
        BantuanImpor::Petakan($masuk, $impor)->assertSessionHasNoErrors();
        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->where('Sku', 'EKS-1')->sole()->Favorit)->toBeTrue()
            ->and(Produk::query()->where('Sku', 'RBC-1')->sole()->Favorit)->toBeFalse()
            ->and(Produk::query()->count())->toBe(2);
    });
});
