<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Harga\Model\DaftarHarga;
use App\Domain\Katalog\Harga\Model\RiwayatHarga;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use App\Domain\Katalog\Pilihan\Model\Pilihan;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Katalog\BantuanKomposisi;
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

it('pilihan tambahan ikut naik per kanal, harga dasar pilihan tetap, dan terapkan ulang tanpa timpa melewati yang sudah ada', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    BantuanKatalog::BuatProduk(['Nama' => 'Kopi Susu Gula Aren', 'Sku' => 'KSG', 'IdKelompokPajak' => $t['KelompokPajak']->Id], '20000.00', $t['Pcs']);
    BantuanKomposisi::BuatKelompokPilihan('Tambahan', [['Extra Shot', '5000'], ['Susu Oat', '8000'], ['Tanpa Gula', '0']], 0, 3);
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $pratinjau = $masuk()->postJson('/kelola/daftar-harga/atur-kanal/pratinjau', IsianHargaKanal(['Kanal' => ['GoFood', 'GrabFood']]))->assertOk();
    // Pilihan harga 0 dilewati: dua pilihan berharga.
    expect($pratinjau->json('Data.PilihanIkut'))->toBeTrue()
        ->and($pratinjau->json('Data.TotalPilihan'))->toBe(2)
        ->and($pratinjau->json('Data.Kanal.0.PilihanDiisi'))->toBe(2);

    $masuk()->post('/kelola/daftar-harga/atur-kanal', IsianHargaKanal(['Kanal' => ['GoFood']]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('Kilat', fn (string $pesan): bool => str_contains($pesan, '2 pilihan tambahan'));

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $shot = Pilihan::query()->where('Nama', 'Extra Shot')->sole();
    $oat = Pilihan::query()->where('Nama', 'Susu Oat')->sole();
    $polos = Pilihan::query()->where('Nama', 'Tanpa Gula')->sole();

    // 5.000 × 1,30 = 6.500; 8.000 × 1,30 = 10.400 → kelipatan 500 = 10.500.
    expect($shot->HargaKanal)->toBe(['GoFood' => '6500.00'])
        ->and($oat->HargaKanal)->toBe(['GoFood' => '10500.00'])
        ->and($shot->Harga)->toBe('5000.00')
        ->and($polos->HargaKanal)->toBeNull()
        ->and(LogAudit::query()->where('Peristiwa', 'kelompok-pilihan.harga-kanal.ubah')->count())->toBe(1);

    // GrabFood terpisah dari GoFood. Terapkan GoFood lagi dengan persen lain tanpa timpa = tidak berubah.
    $masuk()->post('/kelola/daftar-harga/atur-kanal', IsianHargaKanal(['Kanal' => ['GoFood', 'GrabFood'], 'Nilai' => '50']))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Pilihan::query()->where('Nama', 'Extra Shot')->sole()->HargaKanal)->toBe(['GoFood' => '6500.00', 'GrabFood' => '7500.00']);

    $masuk()->post('/kelola/daftar-harga/atur-kanal', IsianHargaKanal(['Nilai' => '50', 'Timpa' => true]))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Pilihan::query()->where('Nama', 'Extra Shot')->sole()->HargaKanal)->toBe(['GoFood' => '7500.00', 'GrabFood' => '7500.00']);
});

it('pilihan tidak ikut bila berlaku di outlet tertentu dan menyimpan kelompok pilihan tidak menghapus harga kanal', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    BantuanKatalog::BuatProduk(['Nama' => 'Kopi Susu Gula Aren', 'Sku' => 'KSG', 'IdKelompokPajak' => $t['KelompokPajak']->Id], '20000.00', $t['Pcs']);
    $kelompok = BantuanKomposisi::BuatKelompokPilihan('Tambahan', [['Extra Shot', '5000']], 0, 1);
    $outlet = $t['Outlet'];
    $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $pratinjau = $masuk()->postJson('/kelola/daftar-harga/atur-kanal/pratinjau', IsianHargaKanal(['UuidOutlet' => [$outlet->Uuid]]))->assertOk();
    expect($pratinjau->json('Data.PilihanIkut'))->toBeFalse()
        ->and($pratinjau->json('Data.TotalPilihan'))->toBe(0);

    $masuk()->post('/kelola/daftar-harga/atur-kanal', IsianHargaKanal(['UuidOutlet' => [$outlet->Uuid]]))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Pilihan::query()->where('Nama', 'Extra Shot')->sole()->HargaKanal)->toBeNull();

    // Mengubah kelompok pilihan lewat aksi biasa tidak menghapus harga kanal yang sudah ada.
    $masuk()->post('/kelola/daftar-harga/atur-kanal', IsianHargaKanal())->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $pilihan = Pilihan::query()->where('Nama', 'Extra Shot')->sole();
    app(\App\Domain\Katalog\Pilihan\Aksi\SimpanKelompokPilihan::class)->Jalankan(
        $kelompok,
        new \App\Domain\Katalog\Pilihan\Data\DataKelompokPilihan('Tambahan', 0, 1, 0, [
            new \App\Domain\Katalog\Pilihan\Data\DataPilihan($pilihan->Uuid, 'Extra Shot', \App\Domain\Bersama\Nilai\Uang::Dari('5000'), true, null, null),
        ], true),
    );
    expect(Pilihan::query()->where('Nama', 'Extra Shot')->sole()->HargaKanal)->toBe(['GoFood' => '6500.00']);
});
