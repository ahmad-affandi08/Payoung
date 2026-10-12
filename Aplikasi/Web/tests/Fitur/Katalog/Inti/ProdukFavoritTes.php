<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Aksi\PerbaruiProdukSebagian;
use App\Domain\Katalog\Data\DataPerubahanProduk;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukSatuan;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * K-16 (F-03): produk favorit pilihan pemilik (`Produk.Favorit`, tingkat tenant). Field `Favorit` di form bersifat
 * `sometimes`: klien lama yang tidak mengirimnya tidak boleh mereset favorit saat mengedit (CLAUDE.md #16).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('K-16 Produk.Favorit lewat form', function (): void {
    it('membuat produk Favorit; tanpa field Favorit = false; detail & form edit membawanya', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $favorit = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Nama' => 'Es Kopi Susu Gula Aren', 'Favorit' => true]);
        $biasa = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Nama' => 'Roti Bakar Cokelat Keju']);

        $masuk()->post('/kelola/produk', $favorit)->assertSessionHasNoErrors();
        $masuk()->post('/kelola/produk', $biasa)->assertSessionHasNoErrors();
        $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Favorit' => 'bukan-boolean']))->assertSessionHasErrors('Favorit');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->where('Uuid', $favorit['Uuid'])->sole()->Favorit)->toBeTrue()
            ->and(Produk::query()->where('Uuid', $biasa['Uuid'])->sole()->Favorit)->toBeFalse();

        $masuk()->get("/kelola/produk/{$favorit['Uuid']}/ubah")
            ->assertInertia(fn ($halaman) => $halaman->where('Produk.Favorit', true));
        $masuk()->get("/kelola/produk/{$favorit['Uuid']}")
            ->assertInertia(fn ($halaman) => $halaman->where('Produk.Favorit', true));
    });

    it('edit tanpa field Favorit (klien lama) tidak mereset; Favorit false melepas; perubahan tercatat di audit', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $form = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Nama' => 'Nasi Goreng Kampung Spesial', 'Favorit' => true]);
        $masuk()->post('/kelola/produk', $form)->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $form['Satuan'][0]['Uuid'] = ProdukSatuan::query()->sole()->Uuid;
        $tanpaFavorit = $form;
        unset($tanpaFavorit['Favorit']);

        $masuk()->put("/kelola/produk/{$form['Uuid']}", array_replace($tanpaFavorit, ['Nama' => 'Nasi Goreng Kampung Spesial Telur']))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->where('Uuid', $form['Uuid'])->sole()->Favorit)->toBeTrue();

        $masuk()->put("/kelola/produk/{$form['Uuid']}", array_replace($form, ['Favorit' => false]))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $produk = Produk::query()->where('Uuid', $form['Uuid'])->sole();
        $audit = LogAudit::query()->where('Peristiwa', 'produk.ubah')->latest('Id')->firstOrFail();
        expect($produk->Favorit)->toBeFalse()
            ->and($audit->NilaiLama['Favorit'] ?? null)->toBeTrue()
            ->and($audit->NilaiBaru['Favorit'] ?? null)->toBeFalse();
    });

    it('BahanBaku tidak pernah favorit (tidak tampil di POS), baik dibuat maupun diubah ke BahanBaku', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $bahan = BantuanKatalog::IsiFormProduk($t['Pcs'], null, ['Nama' => 'Gula Aren Cair Premium 1 kg', 'Jenis' => 'BahanBaku', 'Favorit' => true]);
        $masuk()->post('/kelola/produk', $bahan)->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->where('Uuid', $bahan['Uuid'])->sole()->Favorit)->toBeFalse();

        $stok = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Robusta Sangrai 500 gram', 'Favorit' => true, 'IdKelompokPajak' => $t['KelompokPajak']->Id], '85000.00', $t['Pcs']);
        app(PerbaruiProdukSebagian::class)->Jalankan($stok, new DataPerubahanProduk(jenis: JenisProduk::BahanBaku));
        expect($stok->refresh()->Favorit)->toBeFalse()->and($stok->TampilDiPos)->toBeFalse();
    });

    it('induk varian: Favorit diteruskan ke varian yang ada dan varian baru', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $form = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], [
            'Nama' => 'Kaos Polos Katun Combed 30s',
            'Sku' => 'KAOS',
            'Jenis' => 'IndukVarian',
            'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => ['S', 'M']]],
        ]);
        $masuk()->post('/kelola/produk', $form)->assertSessionHasNoErrors();
        $masuk()->post("/kelola/produk/{$form['Uuid']}/varian", ['AtributVarian' => [], 'JenisAnak' => 'Stok', 'HargaDasar' => ''])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $induk = Produk::query()->where('Uuid', $form['Uuid'])->sole();
        $form['Satuan'][0]['Uuid'] = ProdukSatuan::query()->where('IdProduk', $induk->Id)->sole()->Uuid;

        $masuk()->put("/kelola/produk/{$form['Uuid']}", array_replace($form, ['Favorit' => true]))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($induk->refresh()->Favorit)->toBeTrue()
            ->and(Produk::query()->where('IdInduk', $induk->Id)->pluck('Favorit')->all())->toBe([true, true]);

        $masuk()->post("/kelola/produk/{$form['Uuid']}/varian", ['AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => ['L']]], 'JenisAnak' => 'Stok', 'HargaDasar' => ''])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->where('IdInduk', $induk->Id)->pluck('Favorit')->all())->toBe([true, true, true]);
    });
});
