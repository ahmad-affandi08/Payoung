<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Harga\Model\DaftarHarga;
use App\Domain\Katalog\Harga\Model\RiwayatHarga;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-03 / X8 "Atur harga ojol": daftar harga GoFood/GrabFood/ShopeeFood dibuat dan diisi dari harga dasar dengan satu
 * langkah (pratinjau lalu terapkan). Rumus sama dengan "Ubah harga" massal; harga dasar tidak pernah berubah.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function IsianHargaKanal(array $ubah = []): array
{
    return array_merge(['Kanal' => ['GoFood'], 'Mode' => 'NaikPersen', 'Nilai' => '30', 'Pembulatan' => 500, 'Timpa' => false, 'UuidOutlet' => []], $ubah);
}

it('pratinjau: contoh harga baru dan jumlah produk, tanpa menyimpan apa pun', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    BantuanKatalog::BuatProduk(['Nama' => 'Kopi Susu Gula Aren', 'Sku' => 'KSG', 'IdKelompokPajak' => $t['KelompokPajak']->Id], '20000.00', $t['Pcs']);
    BantuanKatalog::BuatProduk(['Nama' => 'Roti Bakar Coklat', 'Sku' => 'RBC', 'IdKelompokPajak' => $t['KelompokPajak']->Id], '15000.00', $t['Pcs']);

    $respons = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)
        ->postJson('/kelola/daftar-harga/atur-kanal/pratinjau', IsianHargaKanal(['Kanal' => ['GoFood', 'GrabFood']]))
        ->assertOk()
        ->assertJsonPath('Data.TotalSatuan', 2)
        ->assertJsonPath('Data.Kanal.0.Kanal', 'GoFood')
        ->assertJsonPath('Data.Kanal.0.DaftarAda', false)
        ->assertJsonPath('Data.Kanal.0.Diisi', 2)
        ->assertJsonPath('Data.Kanal.1.Kanal', 'GrabFood');

    // 20.000 × 1,30 = 26.000; 15.000 × 1,30 = 19.500 (kelipatan 500).
    $contoh = collect($respons->json('Data.Contoh'))->keyBy('Produk');
    expect($contoh['Kopi Susu Gula Aren']['HargaBaru'])->toBe('26000.00')
        ->and($contoh['Kopi Susu Gula Aren']['HargaBiasa'])->toBe('20000.00')
        ->and($contoh['Roti Bakar Coklat']['HargaBaru'])->toBe('19500.00');

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(DaftarHarga::query()->count())->toBe(0);
});

it('terapkan: membuat daftar tiap platform, mengisi harga, dan harga dasar tidak berubah', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Susu Gula Aren', 'Sku' => 'KSG', 'IdKelompokPajak' => $t['KelompokPajak']->Id], '20000.00', $t['Pcs']);

    BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)
        ->post('/kelola/daftar-harga/atur-kanal', IsianHargaKanal(['Kanal' => ['GoFood', 'ShopeeFood']]))
        ->assertSessionHasNoErrors()
        ->assertRedirect('/kelola/daftar-harga');

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $goFood = DaftarHarga::query()->where('Nama', 'Harga GoFood')->sole();
    $shopee = DaftarHarga::query()->where('Nama', 'Harga ShopeeFood')->sole();

    expect($goFood->Kanal)->toBe(KanalPenjualan::GoFood)
        ->and($goFood->IdOutlet)->toBeNull()
        ->and($goFood->Prioritas)->toBe(100)
        ->and($goFood->Aktif)->toBeTrue()
        ->and($shopee->Kanal)->toBe(KanalPenjualan::ShopeeFood)
        ->and(ProdukHarga::query()->where('IdDaftarHarga', $goFood->Id)->sole()->Harga)->toBe('26000.00')
        ->and(ProdukHarga::query()->where('IdDaftarHarga', $shopee->Id)->sole()->Harga)->toBe('26000.00')
        ->and(ProdukHarga::query()->where('IdProduk', $kopi->Id)->whereNull('IdDaftarHarga')->sole()->Harga)->toBe('20000.00')
        ->and(RiwayatHarga::query()->where('IdDaftarHarga', $goFood->Id)->count())->toBe(1)
        ->and(LogAudit::query()->where('Peristiwa', 'daftar-harga.harga.ubah')->count())->toBe(2);
});

it('terapkan: harga bertingkat ikut naik, produk harga nol dan produk diarsipkan dilewati', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    $gelas = BantuanKatalog::BuatProduk(['Nama' => 'Es Teh Manis', 'Sku' => 'ETM', 'IdKelompokPajak' => $t['KelompokPajak']->Id], '5000.00', $t['Pcs']);
    ProdukHarga::query()->create(['IdProduk' => $gelas->Id, 'IdProdukSatuan' => ProdukHarga::query()->where('IdProduk', $gelas->Id)->value('IdProdukSatuan'), 'IdDaftarHarga' => null, 'JumlahMinimum' => '12', 'Harga' => '4500.00']);
    BantuanKatalog::BuatProduk(['Nama' => 'Jasa Tanpa Harga', 'Sku' => 'JTH', 'IdKelompokPajak' => $t['KelompokPajak']->Id], '0.00', $t['Pcs']);
    $arsip = BantuanKatalog::BuatProduk(['Nama' => 'Menu Lama', 'Sku' => 'ML', 'IdKelompokPajak' => $t['KelompokPajak']->Id, 'Aktif' => false, 'DiarsipkanPada' => now()], '9000.00', $t['Pcs']);

    BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)
        ->post('/kelola/daftar-harga/atur-kanal', IsianHargaKanal(['Pembulatan' => 0]))
        ->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $daftar = DaftarHarga::query()->where('Nama', 'Harga GoFood')->sole();
    $harga = ProdukHarga::query()->where('IdDaftarHarga', $daftar->Id)->orderBy('JumlahMinimum')->get();

    // 5.000 × 1,30 = 6.500; 4.500 × 1,30 = 5.850. Tanpa baris untuk produk harga 0 dan produk diarsipkan.
    expect($harga)->toHaveCount(2)
        ->and($harga->pluck('Harga')->all())->toBe(['6500.00', '5850.00'])
        ->and(ProdukHarga::query()->where('IdDaftarHarga', $daftar->Id)->where('IdProduk', $arsip->Id)->exists())->toBeFalse();
});

it('terapkan ulang: tanpa timpa harga yang ada dilewati, dengan timpa diganti, daftar nonaktif diaktifkan lagi', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Susu Gula Aren', 'Sku' => 'KSG', 'IdKelompokPajak' => $t['KelompokPajak']->Id], '20000.00', $t['Pcs']);
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $masuk()->post('/kelola/daftar-harga/atur-kanal', IsianHargaKanal())->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $daftar = DaftarHarga::query()->where('Nama', 'Harga GoFood')->sole();
    $daftar->forceFill(['Aktif' => false])->save();

    // Tanpa timpa: kopi sudah punya harga ojol, jadi dilewati (tetap 26.000) walau persennya berbeda.
    $masuk()->post('/kelola/daftar-harga/atur-kanal', IsianHargaKanal(['Nilai' => '50']))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(DaftarHarga::query()->where('Kanal', 'GoFood')->count())->toBe(1)
        ->and(DaftarHarga::query()->where('Nama', 'Harga GoFood')->sole()->Aktif)->toBeTrue()
        ->and(ProdukHarga::query()->where('IdDaftarHarga', $daftar->Id)->sole()->Harga)->toBe('26000.00');

    // Dengan timpa: 20.000 × 1,50 = 30.000.
    $masuk()->post('/kelola/daftar-harga/atur-kanal', IsianHargaKanal(['Nilai' => '50', 'Timpa' => true]))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(ProdukHarga::query()->where('IdDaftarHarga', $daftar->Id)->sole()->Harga)->toBe('30000.00')
        ->and(ProdukHarga::query()->where('IdProduk', $kopi->Id)->whereNull('IdDaftarHarga')->sole()->Harga)->toBe('20000.00');
});

it('turun nominal: hasil negatif menolak semuanya dan tidak ada daftar yang tersisa', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    BantuanKatalog::BuatProduk(['Nama' => 'Gelas Plastik', 'Sku' => 'GP', 'IdKelompokPajak' => $t['KelompokPajak']->Id], '1000.00', $t['Pcs']);

    BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)
        ->post('/kelola/daftar-harga/atur-kanal', IsianHargaKanal(['Mode' => 'TurunNominal', 'Nilai' => '5000', 'Pembulatan' => 0]))
        ->assertSessionHasErrors('Nilai');

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(DaftarHarga::query()->count())->toBe(0);
});

it('masukan tidak valid ditolak: kanal bukan platform, pilihan kosong, cara tidak dikenal', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    BantuanKatalog::BuatProduk(['Nama' => 'Kopi', 'Sku' => 'K', 'IdKelompokPajak' => $t['KelompokPajak']->Id], '20000.00', $t['Pcs']);
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $masuk()->post('/kelola/daftar-harga/atur-kanal', IsianHargaKanal(['Kanal' => ['Online']]))->assertSessionHasErrors('Kanal.0');
    $masuk()->post('/kelola/daftar-harga/atur-kanal', IsianHargaKanal(['Kanal' => []]))->assertSessionHasErrors('Kanal');
    $masuk()->post('/kelola/daftar-harga/atur-kanal', IsianHargaKanal(['Mode' => 'Acak']))->assertSessionHasErrors('Mode');
    $masuk()->post('/kelola/daftar-harga/atur-kanal', IsianHargaKanal(['Nilai' => '-5']))->assertSessionHasErrors('Nilai');
    $masuk()->post('/kelola/daftar-harga/atur-kanal', IsianHargaKanal(['Pembulatan' => 250]))->assertSessionHasErrors('Pembulatan');

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(DaftarHarga::query()->count())->toBe(0);
});

it('tanpa produk berharga: pesan jelas, tanpa izin ubah harga ditolak', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();

    BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)
        ->post('/kelola/daftar-harga/atur-kanal', IsianHargaKanal())
        ->assertSessionHasErrors('Kanal');
});
