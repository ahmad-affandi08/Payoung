<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Aksi\PerbaruiProdukSebagian;
use App\Domain\Katalog\Data\DataPerubahanProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Enum\SumberPerubahanKatalog;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Model\Gudang;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPelacakan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-05a PelacakanTerkunci di Katalog (DesainF05a C.4)', function (): void {
    it('form produk: pelacakan boleh diubah selama belum ada riwayat stok, lalu terkunci (PelacakanTerkunci)', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Apotek Sehat Sentosa');
        $form = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Nama' => 'Paracetamol 500 mg Strip 10 Tablet']);
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $masuk()->post('/kelola/produk', $form)->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $form['Satuan'][0]['Uuid'] = ProdukSatuan::query()->sole()->Uuid;

        $masuk()->put("/kelola/produk/{$form['Uuid']}", array_replace($form, ['Pelacakan' => 'Batch']))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $produk = Produk::query()->where('Uuid', $form['Uuid'])->sole();
        expect($produk->Pelacakan)->toBe(PelacakanProduk::Batch);

        BantuanPelacakan::BuatMutasiMentah($produk, Gudang::query()->where('IdOutlet', $t['Outlet']->Id)->firstOrFail());

        $masuk()->put("/kelola/produk/{$form['Uuid']}", array_replace($form, ['Pelacakan' => 'Tidak']))
            ->assertSessionHasErrors(['Pelacakan' => 'Pelacakan tidak bisa diubah dari Batch & kedaluwarsa karena produk sudah punya riwayat stok. Buat produk baru bila cara pelacakannya perlu berbeda.']);
        $masuk()->put("/kelola/produk/{$form['Uuid']}", array_replace($form, ['Pelacakan' => 'Seri']))->assertSessionHasErrors('Pelacakan');

        // Pelacakan tetap sama: perubahan lain tetap boleh.
        $masuk()->put("/kelola/produk/{$form['Uuid']}", array_replace($form, ['Pelacakan' => 'Batch', 'Merek' => 'Sanbe']))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($produk->refresh()->Pelacakan)->toBe(PelacakanProduk::Batch)->and($produk->Merek)->toBe('Sanbe');
    });

    it('PerbaruiProdukSebagian (impor): pelacakan terkunci setelah ada riwayat stok, tanpa perubahan pelacakan tetap jalan', function (): void {
        $t = BantuanPelacakan::SiapkanTenant('Toko Elektronik Maju Jaya');
        $stok = $t['Produk']['Stok'];
        $stok->forceFill(['IdKelompokPajak' => BantuanKatalog::BuatKelompokPajak('Barang elektronik kena PPN')->Id])->save();
        $aksi = app(PerbaruiProdukSebagian::class);

        $aksi->Jalankan($stok, new DataPerubahanProduk(pelacakan: PelacakanProduk::Seri), SumberPerubahanKatalog::Impor);
        expect($stok->refresh()->Pelacakan)->toBe(PelacakanProduk::Seri);

        BantuanPelacakan::BuatMutasiMentah($stok, $t['Gudang'], '1.0000');

        $galat = null;

        try {
            $aksi->Jalankan($stok, new DataPerubahanProduk(pelacakan: PelacakanProduk::Tidak), SumberPerubahanKatalog::Impor);
        } catch (PelanggaranAturanBisnis $e) {
            $galat = $e;
        }

        expect($galat?->kode)->toBe('PelacakanTerkunci')
            ->and($galat?->bidang)->toBe('Pelacakan')
            ->and($stok->refresh()->Pelacakan)->toBe(PelacakanProduk::Seri);

        $aksi->Jalankan($stok, new DataPerubahanProduk(pelacakan: PelacakanProduk::Seri, merek: 'Miyako'));
        expect($stok->refresh()->Merek)->toBe('Miyako');
    });
});
