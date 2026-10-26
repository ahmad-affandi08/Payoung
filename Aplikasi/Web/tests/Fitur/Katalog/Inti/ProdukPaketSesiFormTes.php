<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\PaketSesi;
use App\Domain\Katalog\Model\PaketSesiProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-23 B formulir produk Sederhana: "Jual sebagai paket sesi" di form tambah produk membuat produk Jasa + definisi
 * `PaketSesi` (semua layanan Jasa) dalam satu transaksi; kirim ulang idempoten; bukan Jasa, jumlah sesi tidak valid,
 * atau paket langganan tanpa fitur `pelanggan.paket-sesi` ditolak tanpa menyimpan produk.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @param  array<string, mixed>  $t
 * @param  array<string, mixed>  $timpa
 * @return array<string, mixed>
 */
function IsiFormPaketSesi(array $t, array $timpa = []): array
{
    return BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], array_replace([
        'Nama' => 'Paket Creambath Rambut Panjang 10x Sesi',
        'Jenis' => JenisProduk::Jasa->value,
        'Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', [], [['JumlahMinimum' => '1', 'Harga' => '1250000']], defaultJual: true)],
        'PaketSesi' => ['JumlahSesi' => 10, 'MasaBerlakuHari' => 180],
    ], $timpa));
}

it('Jasa + paket sesi: produk, harga, dan definisi paket (semua layanan Jasa) tersimpan sekaligus. Kirim ulang idempoten', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk('Salon Ayu Sederhana Klaten');
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $masuk()->get('/kelola/produk/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('FiturPaketSesi', true));

    $form = IsiFormPaketSesi($t);
    $masuk()->post('/kelola/produk', $form)->assertSessionHasNoErrors()->assertRedirect("/kelola/produk/{$form['Uuid']}")
        ->assertSessionHas('Kilat', 'Produk Paket Creambath Rambut Panjang 10x Sesi disimpan sebagai paket 10 sesi (berlaku 180 hari).');
    $masuk()->post('/kelola/produk', $form)->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $produk = Produk::query()->where('Uuid', $form['Uuid'])->sole();
    $paket = PaketSesi::query()->where('IdProduk', $produk->Id)->sole();
    expect($produk->Jenis)->toBe(JenisProduk::Jasa)
        ->and(ProdukHarga::query()->sole()->Harga)->toBe('1250000.00')
        ->and($paket->JumlahSesi)->toBe(10)
        ->and($paket->MasaBerlakuHari)->toBe(180)
        ->and($paket->SemuaProdukJasa)->toBeTrue()
        ->and($paket->Aktif)->toBeTrue()
        ->and(PaketSesiProduk::query()->count())->toBe(0)
        ->and(Produk::query()->count())->toBe(1);

    // Tanpa masa berlaku = tanpa batas; tanpa PaketSesi = produk biasa.
    $tanpaBatas = IsiFormPaketSesi($t, ['Nama' => 'Paket Pijat Refleksi 5x', 'PaketSesi' => ['JumlahSesi' => 5, 'MasaBerlakuHari' => null]]);
    $masuk()->post('/kelola/produk', $tanpaBatas)->assertSessionHasNoErrors()->assertSessionHas('Kilat', 'Produk Paket Pijat Refleksi 5x disimpan sebagai paket 5 sesi (tanpa batas waktu).');
    $masuk()->post('/kelola/produk', IsiFormPaketSesi($t, ['Nama' => 'Potong Rambut Pria', 'PaketSesi' => null]))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(PaketSesi::query()->count())->toBe(2)
        ->and(PaketSesi::query()->whereNull('MasaBerlakuHari')->sole()->JumlahSesi)->toBe(5);
});

it('ditolak tanpa menyimpan produk: bukan Jasa, jumlah sesi tidak valid, paket langganan tanpa fitur paket sesi', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk('Salon Ayu Tolak Klaten');
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $masuk()->post('/kelola/produk', IsiFormPaketSesi($t, ['Jenis' => JenisProduk::Stok->value]))->assertSessionHasErrors('PaketSesi.JumlahSesi');
    $masuk()->post('/kelola/produk', IsiFormPaketSesi($t, ['PaketSesi' => ['JumlahSesi' => 0]]))->assertSessionHasErrors('PaketSesi.JumlahSesi');
    $masuk()->post('/kelola/produk', IsiFormPaketSesi($t, ['PaketSesi' => ['JumlahSesi' => 10, 'MasaBerlakuHari' => 99999]]))->assertSessionHasErrors('PaketSesi.MasaBerlakuHari');

    Langganan::query()->where('IdTenant', $t['Tenant']->Id)->update(['IdPaket' => Paket::query()->where('Kode', 'STARTER')->value('Id')]);
    cache()->flush();
    $masuk()->get('/kelola/produk/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('FiturPaketSesi', false));
    $masuk()->post('/kelola/produk', IsiFormPaketSesi($t))->assertSessionHasErrors('PaketSesi.JumlahSesi');

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->count())->toBe(0)->and(PaketSesi::query()->count())->toBe(0);
});
