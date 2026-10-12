<?php

declare(strict_types=1);

use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Katalog\Model\Satuan;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

describe('F-03 isolasi tenant rute Tim 1', function (): void {
    it('setiap {uuid} milik tenant A diminta sebagai tenant B → 404, dan data A tidak berubah', function (): void {
        $a = BantuanKatalog::SiapkanTenantProduk('Toko Tenant A');
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Produk Rahasia Tenant A', 'PathGambar' => 'produk/1/x-01JBGAMBARVERSI0000000000A.webp'], null, $a['Pcs']);
        $satuanProduk = ProdukSatuan::query()->where('IdProduk', $produk->Id)->sole();
        $kategori = BantuanKatalog::BuatKategori('Kategori A');
        $b = BantuanKatalog::SiapkanTenantProduk('Toko Tenant B');
        $formB = BantuanKatalog::IsiFormProduk($b['Pcs'], $b['KelompokPajak']);
        $masukB = fn () => BantuanKatalog::MasukSebagai($this, $b['Tenant']->Id);
        $p = "/kelola/produk/{$produk->Uuid}";

        $masukB()->get($p)->assertNotFound();
        $masukB()->get("{$p}/ubah")->assertNotFound();
        $masukB()->put($p, $formB)->assertNotFound();
        $masukB()->post("{$p}/arsipkan")->assertNotFound();
        $masukB()->post("{$p}/pulihkan")->assertNotFound();
        $masukB()->delete($p)->assertNotFound();
        $masukB()->get("{$p}/gambar?ukuran=kecil")->assertNotFound();
        $masukB()->post("{$p}/gambar", [])->assertSessionHasErrors('Gambar');
        $masukB()->delete("{$p}/gambar")->assertNotFound();
        $masukB()->post("{$p}/varian", ['AtributVarian' => [], 'JenisAnak' => 'Stok', 'HargaDasar' => ''])->assertNotFound();
        $masukB()->post("{$p}/satuan/{$satuanProduk->Uuid}/barcode-internal")->assertNotFound();
        $masukB()->put("{$p}/batas-stok", ['Baris' => []])->assertNotFound();
        $masukB()->put("/kelola/kategori/{$kategori->Uuid}", ['Nama' => 'Dibajak'])->assertNotFound();
        $masukB()->delete("/kelola/kategori/{$kategori->Uuid}")->assertNotFound();
        $masukB()->put("/kelola/satuan/{$a['Pcs']->Uuid}", ['Nama' => 'Dibajak', 'Simbol' => 'x', 'BolehDesimal' => false])->assertNotFound();
        $masukB()->delete("/kelola/satuan/{$a['Pcs']->Uuid}")->assertNotFound();

        // Uuid tenant A di isi form tenant B tidak dikenal (bukan dipakai lintas tenant).
        $masukB()->post('/kelola/produk', array_replace($formB, ['UuidKategori' => $kategori->Uuid]))->assertSessionHasErrors('UuidKategori');
        $masukB()->post('/kelola/produk', array_replace($formB, ['UuidKelompokPajak' => $a['KelompokPajak']->Uuid]))->assertSessionHasErrors('UuidKelompokPajak');
        $masukB()->post('/kelola/produk', array_replace($formB, ['Satuan' => [BantuanKatalog::IsiSatuanForm($a['Pcs'])]]))->assertSessionHasErrors('Satuan.0.UuidSatuan');
        $masukB()->post('/kelola/kategori', ['Nama' => 'Anak', 'UuidInduk' => $kategori->Uuid])->assertSessionHasErrors('UuidInduk');
        // Uuid produk A sebagai kunci idempotensi di tenant B tidak mengembalikan produk A.
        $masukB()->post('/kelola/produk', array_replace($formB, ['Uuid' => $produk->Uuid]))->assertSessionHasErrors();
        $masukB()->getJson('/kelola/produk/cari?kata=Rahasia')->assertOk()->assertExactJson(['Data' => []]);
        $masukB()->getJson('/kelola/produk/label/cari?kata=Rahasia')->assertOk()->assertExactJson(['Data' => []]);
        $masukB()->postJson('/kelola/produk/label/barcode-internal', ['Satuan' => [$satuanProduk->Uuid]])->assertStatus(422);

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(Produk::query()->whereKey($produk->Id)->sole()->Nama)->toBe('Produk Rahasia Tenant A')
            ->and(Produk::query()->count())->toBe(1)
            ->and(Kategori::query()->whereKey($kategori->Id)->sole()->Nama)->toBe('Kategori A')
            ->and(Satuan::query()->whereKey($a['Pcs']->Id)->sole()->Nama)->toBe('Pieces');
    });
});
