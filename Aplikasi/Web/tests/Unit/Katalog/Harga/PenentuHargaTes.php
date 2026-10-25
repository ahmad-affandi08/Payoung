<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Harga\Data\DataBarisProdukHarga;
use App\Domain\Katalog\Harga\Data\DataKatalogHarga;
use App\Domain\Katalog\Harga\Data\DataPermintaanHarga;
use App\Domain\Katalog\Harga\Enum\SumberHarga;
use App\Domain\Katalog\Harga\Enum\SumberPerubahanHarga;
use App\Domain\Katalog\Harga\Layanan\PenentuHarga;
use App\Domain\Pajak\Enum\KategoriPajakProduk;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use Carbon\CarbonImmutable;

/*
 * Perilaku PenentuHarga di luar test vector bersama (F-03), dan kemurniannya (tanpa DB, model, facade) agar identik
 * dengan PenentuHarga Dart. Nilai enum harus sama dengan Dart dan kontrak frontend (DesainF03 E.1).
 */

function PermintaanSabun(string $jumlah): DataPermintaanHarga
{
    return new DataPermintaanHarga('P-SABUN', 'PS-SABUN-PCS', Kuantitas::Dari($jumlah), null, null, null, CarbonImmutable::parse('2026-10-01T03:00:00Z'));
}

it('menolak jumlah nol atau negatif', function (string $jumlah): void {
    $katalog = new DataKatalogHarga([], [new DataBarisProdukHarga('P-SABUN', 'PS-SABUN-PCS', null, Kuantitas::Dari(1), Uang::Dari('5000'))]);

    expect(fn () => (new PenentuHarga)->Tentukan($katalog, PermintaanSabun($jumlah)))->toThrow(InvalidArgumentException::class);
})->with(['0', '-1']);

it('memilih baris dasar terkecil bila jumlah di bawah semua JumlahMinimum, walau urutan masukan acak', function (): void {
    $katalog = new DataKatalogHarga([], [
        new DataBarisProdukHarga('P-SABUN', 'PS-SABUN-PCS', null, Kuantitas::Dari('12'), Uang::Dari('4500')),
        new DataBarisProdukHarga('P-SABUN', 'PS-SABUN-PCS', null, Kuantitas::Dari('1'), Uang::Dari('5000')),
    ]);

    $hasil = (new PenentuHarga)->Tentukan($katalog, PermintaanSabun('0.5'));

    expect($hasil?->harga->KeString())->toBe('5000.00')
        ->and($hasil?->sumber)->toBe(SumberHarga::Dasar)
        ->and($hasil?->jumlahMinimum->KeString())->toBe('1.0000');
});

arch('PenentuHarga murni: tanpa facade, database, atau model')
    ->expect(PenentuHarga::class)
    ->not->toUse(['Illuminate\Support\Facades', 'Illuminate\Database', 'App\Domain\Katalog\Model', 'App\Domain\Katalog\Harga\Model']);

it('nilai enum kanal, sumber harga, sumber perubahan, dan kategori pajak sesuai kontrak', function (): void {
    expect(array_column(KanalPenjualan::cases(), 'value'))->toBe(['MakanDiTempat', 'BawaPulang', 'Antar', 'Online', 'PesanSendiri', 'Marketplace', 'GoFood', 'GrabFood', 'ShopeeFood'])
        ->and(array_column(SumberHarga::cases(), 'value'))->toBe(['DaftarHarga', 'Bertingkat', 'Dasar'])
        ->and(array_column(SumberPerubahanHarga::cases(), 'value'))->toBe(['Manual', 'Impor', 'PanduanAwal', 'Varian', 'Sistem', 'AplikasiOwner'])
        ->and(array_column(KategoriPajakProduk::cases(), 'value'))->toBe(['KenaPpn', 'BebasPpn', 'KenaPbjt', 'NonPajak', 'Lainnya'])
        ->and(KanalPenjualan::MakanDiTempat->AmbilLabel())->toBe('Makan di tempat')
        ->and(KategoriPajakProduk::NonPajak->CekTanpaPajak())->toBeTrue()
        ->and(KategoriPajakProduk::Lainnya->CekTanpaPajak())->toBeFalse();
});
