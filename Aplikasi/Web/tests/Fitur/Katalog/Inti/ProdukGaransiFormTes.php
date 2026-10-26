<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\LokasiProduksi;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Model\Produk;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05h garansi: form produk menyimpan `MasaGaransiBulan` (1–240) hanya untuk produk bernomor seri; produk lain
 * mengabaikannya (disimpan null).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @param  array<string, mixed>  $t
 * @param  array<string, mixed>  $timpa
 * @return array<string, mixed>
 */
function IsiFormGaransi(array $t, array $timpa = []): array
{
    return BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], array_replace([
        'Nama' => 'Ponsel Android 8/256 GB Hitam',
        'Pelacakan' => PelacakanProduk::Seri->value,
        'MasaGaransiBulan' => 12,
        'Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', [], [['JumlahMinimum' => '1', 'Harga' => '6500000']], defaultJual: true)],
    ], $timpa));
}

it('produk bernomor seri menyimpan masa garansi. Diubah lewat form ubah. Dikosongkan = tanpa garansi', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk('Toko Ponsel Nusantara Solo');
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $form = IsiFormGaransi($t);
    $masuk()->post('/kelola/produk', $form)->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->where('Uuid', $form['Uuid'])->sole()->MasaGaransiBulan)->toBe(12);

    $masuk()->put("/kelola/produk/{$form['Uuid']}", [...$form, 'MasaGaransiBulan' => 24])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->where('Uuid', $form['Uuid'])->sole()->MasaGaransiBulan)->toBe(24);

    $masuk()->put("/kelola/produk/{$form['Uuid']}", [...$form, 'MasaGaransiBulan' => null])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->where('Uuid', $form['Uuid'])->sole()->MasaGaransiBulan)->toBeNull();
});

it('produk tanpa pelacakan seri mengabaikan masa garansi. Nilai di luar 1–240 ditolak', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk('Toko Ponsel Nusantara Solo');
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $biasa = IsiFormGaransi($t, ['Nama' => 'Casing Silikon Bening', 'Pelacakan' => PelacakanProduk::Tidak->value]);
    $masuk()->post('/kelola/produk', $biasa)->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->where('Uuid', $biasa['Uuid'])->sole()->MasaGaransiBulan)->toBeNull();

    $masuk()->post('/kelola/produk', IsiFormGaransi($t, ['Nama' => 'Ponsel Uji Nol', 'MasaGaransiBulan' => 0]))->assertSessionHasErrors('MasaGaransiBulan');
    $masuk()->post('/kelola/produk', IsiFormGaransi($t, ['Nama' => 'Ponsel Uji Besar', 'MasaGaransiBulan' => 241]))->assertSessionHasErrors('MasaGaransiBulan');
});

it('kode barang/jasa dan kode satuan Coretax disimpan dari form produk (format dicek). Impor tidak menimpanya', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk('Toko Ponsel Nusantara Solo');
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $form = IsiFormGaransi($t, ['Nama' => 'Kabel Data USB-C 1 m', 'Pelacakan' => PelacakanProduk::Tidak->value, 'KodeBarangJasaCoretax' => '720200', 'KodeUnitCoretax' => 'UM.0021']);
    $masuk()->post('/kelola/produk', $form)->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $produk = Produk::query()->where('Uuid', $form['Uuid'])->sole();
    expect($produk->KodeBarangJasaCoretax)->toBe('720200')->and($produk->KodeUnitCoretax)->toBe('UM.0021');

    $masuk()->post('/kelola/produk', IsiFormGaransi($t, ['Nama' => 'Kode Salah Satu', 'Pelacakan' => PelacakanProduk::Tidak->value, 'KodeBarangJasaCoretax' => '72A']))->assertSessionHasErrors('KodeBarangJasaCoretax');
    $masuk()->post('/kelola/produk', IsiFormGaransi($t, ['Nama' => 'Kode Salah Dua', 'Pelacakan' => PelacakanProduk::Tidak->value, 'KodeUnitCoretax' => 'pcs']))->assertSessionHasErrors('KodeUnitCoretax');

    $masuk()->put("/kelola/produk/{$form['Uuid']}", [...$form, 'KodeBarangJasaCoretax' => null, 'KodeUnitCoretax' => null])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect($produk->refresh()->KodeBarangJasaCoretax)->toBeNull()->and($produk->KodeUnitCoretax)->toBeNull();
});

it('K-25: harga terbuka disimpan dari form untuk jenis yang boleh. Jenis lain & impor tidak mengubahnya', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk('Toko Ponsel Nusantara Solo');
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $form = IsiFormGaransi($t, ['Nama' => 'Jasa servis ringan', 'Jenis' => 'Jasa', 'Pelacakan' => PelacakanProduk::Tidak->value, 'MasaGaransiBulan' => null, 'HargaTerbuka' => true]);
    $masuk()->post('/kelola/produk', $form)->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $produk = Produk::query()->where('Uuid', $form['Uuid'])->sole();
    expect($produk->HargaTerbuka)->toBeTrue();

    // Form tanpa bidang HargaTerbuka (klien lama) tidak mematikannya.
    $tanpa = $form;
    unset($tanpa['HargaTerbuka']);
    $masuk()->put("/kelola/produk/{$form['Uuid']}", $tanpa)->assertSessionHasNoErrors();
    expect($produk->refresh()->HargaTerbuka)->toBeTrue();

    $masuk()->put("/kelola/produk/{$form['Uuid']}", [...$form, 'HargaTerbuka' => false])->assertSessionHasNoErrors();
    expect($produk->refresh()->HargaTerbuka)->toBeFalse();

    $varian = IsiFormGaransi($t, ['Nama' => 'Ponsel Seri Harga Terbuka', 'HargaTerbuka' => true]);
    $masuk()->post('/kelola/produk', [...$varian, 'Jenis' => 'Konsinyasi', 'Pelacakan' => PelacakanProduk::Tidak->value, 'MasaGaransiBulan' => null])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->where('Uuid', $varian['Uuid'])->sole()->HargaTerbuka)->toBeFalse();
});

it('BR-05.4: lokasi produksi disimpan dari form hanya untuk produk Resep (Dapur/Bar), bisa dikosongkan, nilai lain ditolak', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk('Kedai Kopi Senja Solo');
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $resep = IsiFormGaransi($t, ['Nama' => 'Es Kopi Susu Aren', 'Jenis' => 'Resep', 'Pelacakan' => PelacakanProduk::Tidak->value, 'MasaGaransiBulan' => null, 'LokasiProduksi' => 'Bar']);
    $masuk()->post('/kelola/produk', $resep)->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->where('Uuid', $resep['Uuid'])->sole()->LokasiProduksi)->toBe(LokasiProduksi::Bar);

    $masuk()->put("/kelola/produk/{$resep['Uuid']}", [...$resep, 'LokasiProduksi' => 'Dapur'])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->where('Uuid', $resep['Uuid'])->sole()->LokasiProduksi)->toBe(LokasiProduksi::Dapur);

    $masuk()->put("/kelola/produk/{$resep['Uuid']}", [...$resep, 'LokasiProduksi' => null])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->where('Uuid', $resep['Uuid'])->sole()->LokasiProduksi)->toBeNull();

    $masuk()->post('/kelola/produk', IsiFormGaransi($t, ['Nama' => 'Es Kopi Lokasi Salah', 'Jenis' => 'Resep', 'Pelacakan' => PelacakanProduk::Tidak->value, 'MasaGaransiBulan' => null, 'LokasiProduksi' => 'Gudang']))->assertSessionHasErrors('LokasiProduksi');

    $barang = IsiFormGaransi($t, ['Nama' => 'Gula Pasir 1 kg', 'Pelacakan' => PelacakanProduk::Tidak->value, 'MasaGaransiBulan' => null, 'LokasiProduksi' => 'Dapur']);
    $masuk()->post('/kelola/produk', $barang)->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->where('Uuid', $barang['Uuid'])->sole()->LokasiProduksi)->toBeNull();
});

it('setelah simpan, produk Resep langsung diarahkan ke halaman resepnya, produk Stok ke detail', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk('Kedai Kopi Senja Solo');
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $resep = IsiFormGaransi($t, ['Nama' => 'Kopi Susu Gula Aren', 'Jenis' => 'Resep', 'Pelacakan' => PelacakanProduk::Tidak->value, 'MasaGaransiBulan' => null, 'LokasiProduksi' => 'Bar']);
    $masuk()->post('/kelola/produk', $resep)->assertSessionHasNoErrors()->assertRedirect("/kelola/produk/{$resep['Uuid']}/resep");

    $barang = IsiFormGaransi($t, ['Nama' => 'Gula Pasir 1 kg Curah', 'Pelacakan' => PelacakanProduk::Tidak->value, 'MasaGaransiBulan' => null]);
    $masuk()->post('/kelola/produk', $barang)->assertSessionHasNoErrors()->assertRedirect("/kelola/produk/{$barang['Uuid']}");
});
