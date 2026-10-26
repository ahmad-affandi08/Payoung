<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Enum\EntitasKatalog;
use App\Domain\Katalog\Harga\Aksi\HapusHargaSatuanProduk;
use App\Domain\Katalog\Harga\Enum\SumberPerubahanHarga;
use App\Domain\Katalog\Harga\Model\RiwayatHarga;
use App\Domain\Katalog\Model\PenghapusanKatalog;
use App\Domain\Katalog\Model\ProdukHarga;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    ['Pemilik' => $this->pemilik] = BantuanKatalog::BuatTenant();
    $this->produk = BantuanKatalog::BuatProduk(['Nama' => 'Teh Melati Celup Isi 25 Kantong'], '7500.00');
    $this->pak = BantuanHarga::TambahSatuan($this->produk, BantuanKatalog::BuatSatuan('Pak', 'pak'), '12');
    $this->daftar = BantuanHarga::BuatDaftarHarga('Harga Grosir Pasar');
    ProdukHarga::query()->create(['IdProduk' => $this->produk->Id, 'IdProdukSatuan' => $this->pak->Id, 'JumlahMinimum' => '1', 'Harga' => '85000']);
    ProdukHarga::query()->create(['IdProduk' => $this->produk->Id, 'IdProdukSatuan' => $this->pak->Id, 'JumlahMinimum' => '10', 'Harga' => '82000']);
    BantuanHarga::TambahHargaDaftar($this->daftar, $this->pak, '1', '80000');
});

it('BR-03.3 menghapus semua harga satuan (dasar, bertingkat, daftar harga) dengan riwayat, jejak POS, dan audit', function (): void {
    $this->actingAs($this->pemilik, 'web');
    $uuid = ProdukHarga::query()->where('IdProdukSatuan', $this->pak->Id)->orderBy('Id')->pluck('Uuid')->all();

    app(HapusHargaSatuanProduk::class)->Jalankan($this->pak, SumberPerubahanHarga::Manual);

    expect(ProdukHarga::query()->where('IdProdukSatuan', $this->pak->Id)->exists())->toBeFalse()
        ->and(BantuanHarga::HargaDasar(BantuanHarga::SatuanDasar($this->produk)))->toBe(['1.0000' => '7500.00'])
        ->and(RiwayatHarga::query()->orderBy('Id')->get()->map(fn (RiwayatHarga $riwayat): array => $riwayat->only(['IdDaftarHarga', 'JumlahMinimum', 'HargaLama', 'HargaBaru', 'DiubahOleh']))->all())
        ->toBe([
            ['IdDaftarHarga' => null, 'JumlahMinimum' => '1.0000', 'HargaLama' => '85000.00', 'HargaBaru' => null, 'DiubahOleh' => $this->pemilik->Id],
            ['IdDaftarHarga' => null, 'JumlahMinimum' => '10.0000', 'HargaLama' => '82000.00', 'HargaBaru' => null, 'DiubahOleh' => $this->pemilik->Id],
            ['IdDaftarHarga' => $this->daftar->Id, 'JumlahMinimum' => '1.0000', 'HargaLama' => '80000.00', 'HargaBaru' => null, 'DiubahOleh' => $this->pemilik->Id],
        ])
        ->and(PenghapusanKatalog::query()->where('Entitas', EntitasKatalog::ProdukHarga->value)->orderBy('Id')->pluck('UuidEntitas')->all())->toBe($uuid)
        ->and(LogAudit::query()->where('Peristiwa', 'produk.harga.ubah')->sole()->IdObjek)->toBe($this->produk->Id);
});

it('BR-03.3 riwayat tetap utuh setelah satuan produk dihapus (IdProdukSatuan kosong, IdSatuan tetap)', function (): void {
    app(HapusHargaSatuanProduk::class)->Jalankan($this->pak, SumberPerubahanHarga::Sistem);
    $this->pak->delete();

    expect(RiwayatHarga::query()->count())->toBe(3)
        ->and(RiwayatHarga::query()->whereNotNull('IdProdukSatuan')->exists())->toBeFalse()
        ->and(RiwayatHarga::query()->distinct()->pluck('IdSatuan')->all())->toBe([$this->pak->IdSatuan])
        ->and(RiwayatHarga::query()->distinct()->pluck('Sumber')->all())->toBe([SumberPerubahanHarga::Sistem]);
});

it('sumber Impor tidak menulis audit per produk. Satuan tanpa harga tidak menulis apa pun', function (): void {
    app(HapusHargaSatuanProduk::class)->Jalankan($this->pak, SumberPerubahanHarga::Impor);
    expect(LogAudit::query()->where('Peristiwa', 'produk.harga.ubah')->exists())->toBeFalse();

    $jumlahRiwayat = RiwayatHarga::query()->count();
    app(HapusHargaSatuanProduk::class)->Jalankan($this->pak, SumberPerubahanHarga::Manual);

    expect(RiwayatHarga::query()->count())->toBe($jumlahRiwayat)
        ->and(LogAudit::query()->where('Peristiwa', 'produk.harga.ubah')->exists())->toBeFalse();
});
