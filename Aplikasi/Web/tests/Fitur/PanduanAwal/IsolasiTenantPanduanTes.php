<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Kasir\Model\KategoriKas;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Referensi\Enum\JenisReferensiBank;
use App\Domain\Referensi\Model\ReferensiBank;
use App\Domain\Tenant\Model\OutletFitur;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
    Mail::fake();
});

describe('Isolasi tenant panduan awal (F-01, CLAUDE.md #11)', function (): void {
    it('menerapkan template di tenant A tidak mengisi Akun/Kategori/OutletFitur tenant B', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Tenant' => $b] = BantuanPanduanAwal::BuatTenant('Toko Roti Harum');
        ['Tenant' => $a, 'Outlet' => $outletA] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Terapkan($outletA, 'FNB-CAF');

        BantuanOrganisasi::AturKonteks($b->Id);
        expect(Akun::query()->count())->toBe(0)
            ->and(Kategori::query()->count())->toBe(0)
            ->and(KategoriKas::query()->count())->toBe(0)
            ->and(OutletFitur::query()->count())->toBe(0);
        BantuanOrganisasi::AturKonteks($a->Id);
        expect(Akun::query()->count())->toBe(61); // F-21: + PPh Dibayar Dimuka 1-1750; CRM-06: + Saldo Gift Card 2-1550; v3.42: + dua akun giro; v3.61: + kliring tukar barang; D-106: + delapan akun biaya umum; E8: + beban piutang tak tertagih
    });

    it('tenant B tidak bisa melihat/mengubah metode pembayaran & gambar QRIS A, memakai kategori A, atau membuat kode aktivasi perangkat A', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ReferensiBank::query()->create(['Kode' => 'BCA', 'Nama' => 'Bank Central Asia', 'Jenis' => JenisReferensiBank::Bank]);
        ['Tenant' => $a, 'Pemilik' => $pemilikA, 'Outlet' => $outletA] = BantuanPanduanAwal::BuatTenant();
        BantuanPanduanAwal::Terapkan($outletA, 'FNB-CAF');
        ['Tenant' => $b, 'Pemilik' => $pemilikB] = BantuanPanduanAwal::BuatTenant('Toko Roti Harum');

        BantuanPanduanAwal::Masuk($this, $pemilikA, $a)->post('/kelola/panduan-awal/metode-pembayaran', [
            'Jenis' => 'QrisStatis', 'Nama' => 'QRIS Kopi', 'GambarQris' => UploadedFile::fake()->image('qris.png', 300, 300),
        ])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($a->Id);
        $qrisA = MetodePembayaran::query()->where('Jenis', 'QrisStatis')->sole();
        $kategoriA = Kategori::query()->where('Nama', 'Kopi')->sole();
        $perangkatA = BantuanPerangkat::BuatPerangkat($a->Id, $outletA)['Perangkat'];

        $tesB = fn () => BantuanPanduanAwal::Masuk($this, $pemilikB, $b);
        $tesB()->get("/kelola/panduan-awal/metode-pembayaran/{$qrisA->Uuid}/gambar-qris")->assertNotFound();
        $tesB()->post("/kelola/panduan-awal/metode-pembayaran/{$qrisA->Uuid}/nonaktifkan")->assertNotFound();
        $tesB()->post('/kelola/panduan-awal/produk', ['Produk' => [['Nama' => 'Roti Sobek Cokelat', 'Harga' => '18000', 'Kategori' => $kategoriA->Uuid]]])
            ->assertSessionHasErrors(['Produk.0.Kategori' => 'Pilih kategori dari daftar.']);
        $tesB()->post("/kelola/panduan-awal/perangkat/{$perangkatA->Uuid}/kode-aktivasi")->assertNotFound();

        BantuanOrganisasi::AturKonteks($b->Id);
        expect(Produk::query()->count())->toBe(0);
        expect($qrisA->refresh()->Aktif)->toBeTrue();
    });
});
