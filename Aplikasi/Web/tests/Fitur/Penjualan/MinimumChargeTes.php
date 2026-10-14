<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Model\AreaMeja;
use App\Domain\Penjualan\Model\Penjualan;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanPesananTerbuka;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * FnB minimum charge (§9.1, D-144): minimum per area (mis. VIP) dan per meja (menimpa area). Mengisinya menyiapkan produk
 * "Selisih minimum charge" (Jasa, harga terbuka, tidak tampil sebagai ubin). API meja POS membawa minimum yang berlaku;
 * penjualan pesanan meja yang belanjanya kurang dari minimum dan selisihnya tidak ditagih tetap diterima + tinjauan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @param  array<string, mixed>  $k
 * @return array{0: AreaMeja, 1: string, 2: string} area VIP berisi meja 7, uuid outlet, nama rute
 */
function SiapkanAreaVip(array $k): array
{
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $area = AreaMeja::query()->create(['IdOutlet' => $k['Outlet']->Id, 'Nama' => 'VIP']);
    $k['Meja']->forceFill(['IdAreaMeja' => $area->Id])->save();

    return [$area, $k['Outlet']->Uuid, $k['Meja']->Uuid];
}

it('area & meja: minimum tersimpan, produk selisih disiapkan sekali, API POS membawa minimum yang berlaku (meja menimpa area)', function (): void {
    $k = BantuanPesananTerbuka::SiapkanRestoran($this);
    [$area, $uuidOutlet] = SiapkanAreaVip($k);
    $masuk = fn () => BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

    // Belum ada minimum: tidak ada produk selisih.
    $this->withToken($k['Token'])->getJson('/api/pos/v1/meja')->assertOk()->assertJsonPath('UuidProdukMinimumCharge', null);

    $masuk()->put("/kelola/outlet/{$uuidOutlet}/area-meja/{$area->Uuid}", ['Nama' => 'VIP', 'Urutan' => 0, 'MinimumCharge' => '300000'])
        ->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $produk = Produk::query()->where('Sku', 'MINIMUM-CHARGE')->sole();
    expect($area->refresh()->MinimumCharge)->toBe('300000.00')
        ->and($produk->Jenis)->toBe(JenisProduk::Jasa)
        ->and($produk->HargaTerbuka)->toBeTrue()
        ->and($produk->TampilDiPos)->toBeFalse();

    $jawaban = $this->withToken($k['Token'])->getJson('/api/pos/v1/meja')->assertOk();
    expect($jawaban->json('UuidProdukMinimumCharge'))->toBe($produk->Uuid);
    $meja7 = collect($jawaban->json('Meja'))->firstWhere('Uuid', $k['Meja']->Uuid);
    $meja9 = collect($jawaban->json('Meja'))->firstWhere('Uuid', $k['Meja9']->Uuid);
    expect($meja7['MinimumCharge'])->toBe('300000.00')->and($meja9['MinimumCharge'])->toBeNull();

    // Minimum meja menimpa area; mengisi lagi tidak membuat produk kedua.
    $masuk()->put("/kelola/outlet/{$uuidOutlet}/meja/{$k['Meja']->Uuid}", [
        'Nama' => '7', 'Area' => $area->Uuid, 'Kapasitas' => 4, 'Bentuk' => 'Persegi', 'Urutan' => 0, 'MinimumCharge' => '150000',
    ])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Produk::query()->where('Sku', 'MINIMUM-CHARGE')->count())->toBe(1);
    $meja7 = collect($this->withToken($k['Token'])->getJson('/api/pos/v1/meja')->json('Meja'))->firstWhere('Uuid', $k['Meja']->Uuid);
    expect($meja7['MinimumCharge'])->toBe('150000.00');

    // Dikosongkan = ikut area lagi; format ribuan ditolak.
    $masuk()->put("/kelola/outlet/{$uuidOutlet}/meja/{$k['Meja']->Uuid}", [
        'Nama' => '7', 'Area' => $area->Uuid, 'Kapasitas' => 4, 'Bentuk' => 'Persegi', 'Urutan' => 0, 'MinimumCharge' => '',
    ])->assertSessionHasNoErrors();
    $masuk()->put("/kelola/outlet/{$uuidOutlet}/meja/{$k['Meja']->Uuid}", [
        'Nama' => '7', 'Area' => $area->Uuid, 'Kapasitas' => 4, 'Bentuk' => 'Persegi', 'Urutan' => 0, 'MinimumCharge' => '150.000,50',
    ])->assertSessionHasErrors('MinimumCharge');
    $meja7 = collect($this->withToken($k['Token'])->getJson('/api/pos/v1/meja')->json('Meja'))->firstWhere('Uuid', $k['Meja']->Uuid);
    expect($meja7['MinimumCharge'])->toBe('300000.00');
});

it('penjualan pesanan meja di bawah minimum diterima + tinjauan; selisih ditagih lewat produk minimum charge = bersih', function (): void {
    $k = BantuanPesananTerbuka::SiapkanRestoran($this);
    [$area, $uuidOutlet] = SiapkanAreaVip($k);
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)
        ->put("/kelola/outlet/{$uuidOutlet}/area-meja/{$area->Uuid}", ['Nama' => 'VIP', 'Urutan' => 0, 'MinimumCharge' => '100000'])
        ->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $selisih = Produk::query()->where('Sku', 'MINIMUM-CHARGE')->sole();

    // Meja 7 (VIP): belanja Rp 25.000, selisih tidak ditagih.
    $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
    BantuanPesananTerbuka::Kirim($this, $k, [$buka]);
    $kurang = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Kopi'], 'Jumlah' => '1', 'Harga' => '25000.00']]], ['UuidPesananTerbuka' => $buka['Uuid']]);
    expect(BantuanPesananTerbuka::Kirim($this, $k, [$kurang]))->toBe([['Diterima', null]]);
    $penjualan = Penjualan::query()->where('Uuid', $kurang['Uuid'])->sole();
    expect($penjualan->PerluTinjauan)->toBeTrue()
        ->and($penjualan->AlasanTinjauan)->toContain('MinimumChargeTidakTerpenuhi')
        ->and($penjualan->AlasanTinjauan)->toContain('Rp 25.000')
        ->and($penjualan->AlasanTinjauan)->toContain('Rp 100.000');

    // Meja 7 lagi: kopi + selisih Rp 75.000 sebagai baris produk minimum charge = belanja Rp 100.000, bersih.
    $buka2 = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
    BantuanPesananTerbuka::Kirim($this, $k, [$buka2]);
    $cukup = BantuanPenjualan::Item($k, ['Baris' => [
        ['Produk' => $k['Kopi'], 'Jumlah' => '1', 'Harga' => '25000.00'],
        ['Produk' => $selisih, 'Jumlah' => '1', 'Harga' => '75000.00'],
    ]], ['UuidPesananTerbuka' => $buka2['Uuid']]);
    expect(BantuanPesananTerbuka::Kirim($this, $k, [$cukup]))->toBe([['Diterima', null]]);
    $bersih = Penjualan::query()->where('Uuid', $cukup['Uuid'])->sole();
    expect($bersih->AlasanTinjauan ?? '')->not->toContain('MinimumChargeTidakTerpenuhi')
        ->and($bersih->Subtotal)->toBe('100000.00');

    // Meja 9 tanpa area/minimum: tidak diperiksa.
    $buka9 = BantuanPesananTerbuka::ItemBuka($k, $k['Meja9']);
    BantuanPesananTerbuka::Kirim($this, $k, [$buka9]);
    $bebas = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Kopi'], 'Jumlah' => '1', 'Harga' => '25000.00']]], ['UuidPesananTerbuka' => $buka9['Uuid']]);
    expect(BantuanPesananTerbuka::Kirim($this, $k, [$bebas]))->toBe([['Diterima', null]]);
    expect(Penjualan::query()->where('Uuid', $bebas['Uuid'])->sole()->PerluTinjauan)->toBeFalse();

    // Penjualan tanpa pesanan meja (bawa pulang) tidak terkena minimum.
    $bawaPulang = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Kopi'], 'Jumlah' => '1', 'Harga' => '25000.00']]]);
    expect(BantuanPesananTerbuka::Kirim($this, $k, [$bawaPulang]))->toBe([['Diterima', null]]);
    expect(Penjualan::query()->where('Uuid', $bawaPulang['Uuid'])->sole()->PerluTinjauan)->toBeFalse();
});
