<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Aksi\TambahProdukCepat;
use App\Domain\Katalog\Data\DataProdukCepat;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Harga\Enum\SumberPerubahanHarga;
use App\Domain\Katalog\Harga\Model\RiwayatHarga;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Model\Pengguna;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-03 BR-03.3 riwayat harga dari Aksi Tim 1 (lewat SimpanHargaProduk/HapusHargaSatuanProduk Tim 2)', function (): void {
    it('produk cepat F-01 mencatat RiwayatHarga bersumber PanduanAwal', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        app(TambahProdukCepat::class)->Jalankan([new DataProdukCepat('Roti Bakar Cokelat Keju', Uang::Dari('15000'), null, $t['Pcs']->Id, JenisProduk::NonStok, null)]);

        $produk = Produk::query()->where('Nama', 'Roti Bakar Cokelat Keju')->sole();
        $riwayat = RiwayatHarga::query()->where('IdProduk', $produk->Id)->sole();
        expect($produk->Sku)->toBe('PRD-000001')
            ->and($riwayat->Sumber)->toBe(SumberPerubahanHarga::PanduanAwal)
            ->and($riwayat->HargaLama)->toBeNull()
            ->and($riwayat->HargaBaru)->toBe('15000.00')
            ->and($riwayat->JumlahMinimum)->toBe('1.0000')
            ->and($riwayat->IdSatuan)->toBe($t['Pcs']->Id);
    });

    it('form produk: harga awal bersumber Manual dengan pengubah. Satuan yang dibuang mencatat HargaBaru null', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $dus = BantuanKatalog::BuatSatuan('Dus', 'dus');
        $form = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Satuan' => [
            BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', [], [['JumlahMinimum' => '1', 'Harga' => '3500']]),
            BantuanKatalog::IsiSatuanForm($dus, '40', [], [['JumlahMinimum' => '1', 'Harga' => '135000'], ['JumlahMinimum' => '5', 'Harga' => '130000']]),
        ]]);
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/produk', $form)->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $produk = Produk::query()->where('Uuid', $form['Uuid'])->sole();
        $pemilik = Pengguna::query()->orderByDesc('Id')->firstOrFail();
        $satuanDasar = ProdukSatuan::query()->where('IdProduk', $produk->Id)->where('IdSatuan', $t['Pcs']->Id)->sole();
        $satuanDus = ProdukSatuan::query()->where('IdProduk', $produk->Id)->where('IdSatuan', $dus->Id)->sole();
        expect(RiwayatHarga::query()->where('IdProduk', $produk->Id)->count())->toBe(3)
            ->and(RiwayatHarga::query()->where('IdProduk', $produk->Id)->pluck('Sumber')->unique()->values()->all())->toBe([SumberPerubahanHarga::Manual])
            ->and(RiwayatHarga::query()->where('IdProdukSatuan', $satuanDus->Id)->pluck('DiubahOleh')->unique()->all())->toBe([$pemilik->Id]);

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->put("/kelola/produk/{$produk->Uuid}", array_replace($form, [
            'Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', [], [], $satuanDasar->Uuid)],
        ]))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $hapus = RiwayatHarga::query()->where('IdProduk', $produk->Id)->whereNull('HargaBaru')->orderBy('JumlahMinimum')->get();
        expect($hapus)->toHaveCount(2)
            ->and($hapus->pluck('HargaLama')->all())->toBe(['135000.00', '130000.00'])
            ->and($hapus->pluck('IdSatuan')->unique()->all())->toBe([$dus->Id])
            ->and(ProdukHarga::query()->where('IdProduk', $produk->Id)->pluck('Harga')->all())->toBe(['3500.00']);
    });

    it('masalah harga Tim 2 dipetakan ke bidang form Satuan.{i}.HargaAwal.{j}', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $dus = BantuanKatalog::BuatSatuan('Dus', 'dus');

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Satuan' => [
            BantuanKatalog::IsiSatuanForm($t['Pcs']),
            BantuanKatalog::IsiSatuanForm($dus, '12', [], [['JumlahMinimum' => '1', 'Harga' => '50000'], ['JumlahMinimum' => '1.0', 'Harga' => '48000']]),
        ]]))->assertSessionHasErrors('Satuan.1.HargaAwal.1.JumlahMinimum');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Satuan' => [
            BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', [], [['JumlahMinimum' => '6', 'Harga' => '3000']]),
        ]]))->assertSessionHasErrors('Satuan.0.HargaAwal');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->count())->toBe(0)->and(RiwayatHarga::query()->count())->toBe(0);
    });

    it('generasi varian: harga dasar anak bersumber Varian', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $form = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Jenis' => 'IndukVarian', 'Sku' => 'TEH', 'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => ['Reguler', 'Jumbo']]]]);
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/produk', $form)->assertSessionHasNoErrors();
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post("/kelola/produk/{$form['Uuid']}/varian", ['AtributVarian' => [], 'JenisAnak' => 'NonStok', 'HargaDasar' => '8000'])
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $idAnak = Produk::query()->whereNotNull('IdInduk')->pluck('Id')->all();
        $riwayat = RiwayatHarga::query()->whereIn('IdProduk', $idAnak)->get();
        expect($idAnak)->toHaveCount(2)
            ->and($riwayat)->toHaveCount(2)
            ->and($riwayat->pluck('Sumber')->unique()->values()->all())->toBe([SumberPerubahanHarga::Varian])
            ->and($riwayat->pluck('HargaBaru')->unique()->values()->all())->toBe(['8000.00']);
    });

    it('hapus produk mencatat penghapusan harga di RiwayatHarga', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $form = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', [], [['JumlahMinimum' => '1', 'Harga' => '12500']])]]);
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/produk', $form)->assertSessionHasNoErrors();
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->delete("/kelola/produk/{$form['Uuid']}")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $idProduk = Produk::query()->withTrashed()->where('Uuid', $form['Uuid'])->value('Id');
        expect(RiwayatHarga::query()->where('IdProduk', $idProduk)->whereNull('HargaBaru')->sole()->HargaLama)->toBe('12500.00')
            ->and(ProdukHarga::query()->count())->toBe(0);
    });
});
