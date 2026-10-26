<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Data\KonteksKatalogPos;
use App\Domain\Katalog\Kontrak\BagianKatalogPos;
use App\Domain\Katalog\Layanan\PenilaiPemakaianProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\PaketProduk\Aksi\SimpanKomponenPaket;
use App\Domain\Katalog\PaketProduk\Data\DataKomponenPaket;
use App\Domain\Katalog\PaketProduk\Kueri\KomponenPaketUntukPos;
use App\Domain\Katalog\Pilihan\Aksi\AturKelompokPilihanProduk;
use App\Domain\Katalog\Pilihan\Kueri\PilihanUntukPos;
use App\Domain\Katalog\Pilihan\Model\Pilihan;
use App\Domain\Katalog\Resep\Kueri\PemakaianProdukDiKomposisi;
use App\Domain\Katalog\Resep\Kueri\ResepUntukPos;
use App\Domain\Katalog\Resep\Model\Resep;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Katalog\BantuanKomposisi;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/**
 * Gabungan bagian katalog POS Tim 3 dari tag `BagianKatalogPos::TAG`.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function AmbilBagianKomposisiUji(int $idTenant, int $idOutlet, ?CarbonImmutable $sejak = null): array
{
    $hasil = [];

    foreach (app()->tagged(BagianKatalogPos::TAG) as $bagian) {
        if ($bagian instanceof PilihanUntukPos || $bagian instanceof ResepUntukPos || $bagian instanceof KomponenPaketUntukPos) {
            $hasil = [...$hasil, ...$bagian->AmbilBagian(new KonteksKatalogPos($idTenant, $idOutlet, $sejak))];
        }
    }

    return $hasil;
}

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('BR-03.2 produk dipakai di komposisi', function (): void {
    it('bahan resep versi terbaru, bahan pilihan, dan komponen paket = dipakai. Versi lama tidak dihitung', function (): void {
        BantuanKatalog::BuatTenant();
        $kopi = BantuanKomposisi::BuatBahan();
        $gula = BantuanKomposisi::BuatBahan('Gula Pasir Kristal Putih');
        $sirup = BantuanKomposisi::BuatBahan('Sirup Hazelnut Premium Import', 'ml', 'Mililiter');
        $menu = BantuanKomposisi::BuatProdukResep();
        $paket = BantuanKomposisi::BuatPaket();
        $pemeriksa = app(PemakaianProdukDiKomposisi::class);

        BantuanKomposisi::SimpanResep($menu, [[$kopi, '18'], [$gula, '10']]);
        expect(app(PenilaiPemakaianProduk::class)->AmbilAlasan($gula))->toBe("dipakai sebagai bahan di resep {$menu->Nama} versi 1");

        BantuanKomposisi::SimpanResep($menu, [[$kopi, '18']]);
        expect($pemeriksa->PeriksaPemakaian($gula->Id))->toBeNull()
            ->and($pemeriksa->PeriksaPemakaian($kopi->Id))->toBe("dipakai sebagai bahan di resep {$menu->Nama} versi 2");

        BantuanKomposisi::BuatKelompokPilihan('Tambahan Rasa', [['Hazelnut', '5000', $sirup, '15']], 0);
        expect($pemeriksa->PeriksaPemakaian($sirup->Id))->toBe('dipakai di pilihan Tambahan Rasa/Hazelnut');

        app(SimpanKomponenPaket::class)->Jalankan($paket, [new DataKomponenPaket($menu->Id, Kuantitas::Dari('1'), null)]);
        expect($pemeriksa->PeriksaPemakaian($menu->Id))->toBe("komponen paket {$paket->Nama}");

        // Resep dan paket milik produk yang sudah dihapus tidak menahan bahannya.
        Produk::query()->whereKey($menu->Id)->firstOrFail()->delete();
        Produk::query()->whereKey($paket->Id)->firstOrFail()->delete();
        expect($pemeriksa->PeriksaPemakaian($kopi->Id))->toBeNull()
            ->and($pemeriksa->PeriksaPemakaian($menu->Id))->toBeNull();
    });
});

describe('Bagian katalog POS Tim 3', function (): void {
    it('sinkron lengkap: kelompok, pilihan, pemasangan, resep versi terbaru dengan bahan, komponen paket. Uang & jumlah string', function (): void {
        ['Tenant' => $tenant, 'Outlet' => $outlet] = BantuanKatalog::BuatTenant('Kedai Kopi Senja Solo');
        $kopi = BantuanKomposisi::BuatBahan();
        $susu = BantuanKomposisi::BuatBahan('Susu Segar Full Cream Pasteurisasi', 'ml', 'Mililiter');
        $menu = BantuanKomposisi::BuatProdukResep();
        $paket = BantuanKomposisi::BuatPaket();
        $kelompok = BantuanKomposisi::BuatKelompokPilihan('Tambahan Susu', [['Susu Ekstra', '4000', $susu, '50']], 0);
        app(AturKelompokPilihanProduk::class)->Jalankan($menu, [$kelompok->Id]);
        BantuanKomposisi::SimpanResep($menu, [[$kopi, '16']]);
        $versi2 = BantuanKomposisi::SimpanResep($menu, [[$kopi, '18'], [$susu, '150', null, '5']]);
        app(SimpanKomponenPaket::class)->Jalankan($paket, [new DataKomponenPaket($menu->Id, Kuantitas::Dari('2'), null)]);

        $bagian = AmbilBagianKomposisiUji($tenant->Id, $outlet->Id);

        expect($bagian['KelompokPilihan'])->toBe([['Uuid' => $kelompok->Uuid, 'Nama' => 'Tambahan Susu', 'MinimalPilih' => 0, 'MaksimalPilih' => 1, 'Urutan' => 0]])
            ->and($bagian['Pilihan'])->toBe([[
                'Uuid' => Pilihan::query()->sole()->Uuid,
                'UuidKelompokPilihan' => $kelompok->Uuid,
                'Nama' => 'Susu Ekstra',
                'Harga' => '4000.00',
                'UuidProdukBahan' => $susu->Uuid,
                'Jumlah' => '50.0000',
                'Aktif' => true,
                'Urutan' => 0,
            ]])
            ->and($bagian['ProdukKelompokPilihan'])->toHaveCount(1)
            ->and($bagian['ProdukKelompokPilihan'][0])->toMatchArray(['UuidProduk' => $menu->Uuid, 'UuidKelompokPilihan' => $kelompok->Uuid, 'Urutan' => 0])
            ->and($bagian['Resep'])->toBe([[
                'Uuid' => $versi2->Uuid,
                'UuidProduk' => $menu->Uuid,
                'Versi' => 2,
                'JumlahHasil' => '1.0000',
                'Bahan' => [
                    ['UuidProdukBahan' => $kopi->Uuid, 'JumlahDasar' => '18.0000', 'PersenSusut' => '0.000000'],
                    ['UuidProdukBahan' => $susu->Uuid, 'JumlahDasar' => '150.0000', 'PersenSusut' => '5.000000'],
                ],
            ]])
            ->and($bagian['PaketProdukDetail'])->toHaveCount(1)
            ->and($bagian['PaketProdukDetail'][0])->toMatchArray(['UuidProdukPaket' => $paket->Uuid, 'UuidProdukKomponen' => $menu->Uuid, 'Jumlah' => '2.0000', 'AlokasiHarga' => null]);

        // Produk yang dihapus: pemasangan, resep, dan komponennya tidak ikut sinkron lengkap.
        Produk::query()->whereKey($menu->Id)->firstOrFail()->delete();
        Produk::query()->whereKey($paket->Id)->firstOrFail()->delete();
        $setelahHapus = AmbilBagianKomposisiUji($tenant->Id, $outlet->Id);
        expect($setelahHapus['ProdukKelompokPilihan'])->toBe([])
            ->and($setelahHapus['Resep'])->toBe([])
            ->and($setelahHapus['PaketProdukDetail'])->toBe([]);
    });

    it('delta: hanya baris yang berubah sejak kursor, resep hanya versi terbaru yang baru', function (): void {
        ['Tenant' => $tenant, 'Outlet' => $outlet] = BantuanKatalog::BuatTenant();
        $kopi = BantuanKomposisi::BuatBahan();
        $menu = BantuanKomposisi::BuatProdukResep();
        $this->travelTo(CarbonImmutable::parse('2026-09-27 01:00:00'));
        BantuanKomposisi::BuatKelompokPilihan('Level Gula');
        $es = BantuanKomposisi::BuatKelompokPilihan('Level Es', [['Normal', '0']]);
        BantuanKomposisi::SimpanResep($menu, [[$kopi, '16']]);

        $this->travelTo(CarbonImmutable::parse('2026-09-27 03:00:00'));
        app(AturKelompokPilihanProduk::class)->Jalankan($menu, [$es->Id]);
        $versi2 = BantuanKomposisi::SimpanResep($menu, [[$kopi, '18']]);

        $bagian = AmbilBagianKomposisiUji($tenant->Id, $outlet->Id, CarbonImmutable::parse('2026-09-27 02:00:00'));
        expect($bagian['KelompokPilihan'])->toBe([])
            ->and($bagian['Pilihan'])->toBe([])
            ->and(array_column($bagian['ProdukKelompokPilihan'], 'UuidKelompokPilihan'))->toBe([$es->Uuid])
            ->and(array_column($bagian['Resep'], 'Uuid'))->toBe([$versi2->Uuid])
            ->and($bagian['PaketProdukDetail'])->toBe([]);

        expect(AmbilBagianKomposisiUji($tenant->Id, $outlet->Id, CarbonImmutable::parse('2026-09-27 04:00:00'))['Resep'])->toBe([]);
    });

    it('isolasi tenant: perangkat tenant B hanya menerima komposisi tenant B', function (): void {
        ['Tenant' => $tenantA, 'Outlet' => $outletA] = BantuanKatalog::BuatTenant('Kedai Kopi Senja Solo');
        $kopiA = BantuanKomposisi::BuatBahan();
        BantuanKomposisi::SimpanResep(BantuanKomposisi::BuatProdukResep(), [[$kopiA, '18']]);
        BantuanKomposisi::BuatKelompokPilihan('Level Gula Kedai A');

        ['Tenant' => $tenantB, 'Outlet' => $outletB] = BantuanKatalog::BuatTenant('Warung Kopi Tetangga Sebelah');
        $kelompokB = BantuanKomposisi::BuatKelompokPilihan('Level Gula Warung B');

        $bagianB = AmbilBagianKomposisiUji($tenantB->Id, $outletB->Id);
        expect(array_column($bagianB['KelompokPilihan'], 'Uuid'))->toBe([$kelompokB->Uuid])
            ->and($bagianB['Resep'])->toBe([])
            ->and(count($bagianB['Pilihan']))->toBe(3);

        BantuanOrganisasi::AturKonteks($tenantA->Id);
        $bagianA = AmbilBagianKomposisiUji($tenantA->Id, $outletA->Id);
        expect(array_column($bagianA['KelompokPilihan'], 'Nama'))->toBe(['Level Gula Kedai A'])
            ->and($bagianA['Resep'])->toHaveCount(1)
            ->and(Resep::query()->count())->toBe(1);
    });
});
