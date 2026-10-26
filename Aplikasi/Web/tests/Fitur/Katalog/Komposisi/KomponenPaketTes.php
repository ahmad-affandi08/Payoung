<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Enum\EntitasKatalog;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\PenghapusanKatalog;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\PaketProduk\Aksi\SimpanKomponenPaket;
use App\Domain\Katalog\PaketProduk\Data\DataKomponenPaket;
use App\Domain\Katalog\PaketProduk\Kueri\KomponenPaketProduk;
use App\Domain\Katalog\PaketProduk\Model\PaketProdukDetail;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Katalog\BantuanKomposisi;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/**
 * @param  list<array{0: Produk, 1: string, 2?: string|null}>  $komponen
 */
function SimpanKomponenUji(Produk $paket, array $komponen): void
{
    app(SimpanKomponenPaket::class)->Jalankan($paket, array_map(
        fn (array $baris): DataKomponenPaket => new DataKomponenPaket($baris[0]->Id, Kuantitas::Dari($baris[1]), $baris[2] ?? null),
        $komponen,
    ));
}

/**
 * @param  list<array{0: Produk, 1: string, 2?: string|null}>  $komponen
 * @return array{Komponen: list<array{UuidProdukKomponen: string, Jumlah: string, AlokasiHarga: string}>}
 */
function IsianKomponenUji(array $komponen): array
{
    return ['Komponen' => array_map(fn (array $baris): array => [
        'UuidProdukKomponen' => $baris[0]->Uuid,
        'Jumlah' => $baris[1],
        'AlokasiHarga' => $baris[2] ?? '',
    ], $komponen)];
}

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('Komponen paket', function (): void {
    it('menyimpan komponen lewat HTTP, lalu mengganti: Uuid baris tetap, yang dilepas dicatat jejaknya. Tercatat di log audit', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanKatalog::BuatTenant('Rumah Makan Ayam Geprek Bu Rini');
        $paket = BantuanKomposisi::BuatPaket();
        $nasi = BantuanKatalog::BuatProduk(['Nama' => 'Nasi Ayam Geprek Sambal Bawang', 'Jenis' => JenisProduk::Resep]);
        $teh = BantuanKatalog::BuatProduk(['Nama' => 'Es Teh Manis Gelas Besar', 'Jenis' => JenisProduk::NonStok]);
        $kerupuk = BantuanKatalog::BuatProduk(['Nama' => 'Kerupuk Udang Sidoarjo Bungkus', 'Jenis' => JenisProduk::Stok]);
        $tes = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $tes->put("/kelola/produk/{$paket->Uuid}/komponen", IsianKomponenUji([[$nasi, '1', '70'], [$teh, '1', '25'], [$kerupuk, '2', '5']]))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $barisNasi = PaketProdukDetail::query()->where('IdProdukKomponen', $nasi->Id)->sole();
        $barisKerupuk = PaketProdukDetail::query()->where('IdProdukKomponen', $kerupuk->Id)->sole();

        $tes->put("/kelola/produk/{$paket->Uuid}/komponen", IsianKomponenUji([[$teh, '2'], [$nasi, '1.5']]))->assertSessionHasNoErrors();
        $tes->put("/kelola/produk/{$paket->Uuid}/komponen", IsianKomponenUji([[$teh, '2'], [$nasi, '1.5']]))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(PaketProdukDetail::query()->where('IdProdukPaket', $paket->Id)->orderBy('Urutan')->get()->map->only(['IdProdukKomponen', 'Jumlah', 'AlokasiHarga', 'Urutan'])->all())->toBe([
            ['IdProdukKomponen' => $teh->Id, 'Jumlah' => '2.0000', 'AlokasiHarga' => null, 'Urutan' => 0],
            ['IdProdukKomponen' => $nasi->Id, 'Jumlah' => '1.5000', 'AlokasiHarga' => null, 'Urutan' => 1],
        ])
            ->and(PaketProdukDetail::query()->where('IdProdukKomponen', $nasi->Id)->sole()->Uuid)->toBe($barisNasi->Uuid)
            ->and(PenghapusanKatalog::query()->get()->map(fn (PenghapusanKatalog $baris): array => [$baris->Entitas, $baris->UuidEntitas])->all())
            ->toBe([[EntitasKatalog::PaketProdukDetail, $barisKerupuk->Uuid]])
            ->and(LogAudit::query()->where('Peristiwa', 'produk.komponen.ubah')->where('IdObjek', $paket->Id)->where('IdPengguna', $pemilik->Id)->count())->toBe(2);
    });

    it('alokasi harga: kosong semua atau terisi semua dengan jumlah tepat 100%', function (): void {
        BantuanKatalog::BuatTenant();
        $paket = BantuanKomposisi::BuatPaket();
        $a = BantuanKatalog::BuatProduk(['Nama' => 'Nasi Ayam Geprek Sambal Bawang']);
        $b = BantuanKatalog::BuatProduk(['Nama' => 'Es Teh Manis Gelas Besar']);
        $kode = function (array $komponen) use ($paket): ?string {
            try {
                SimpanKomponenUji($paket, $komponen);
            } catch (PelanggaranAturanBisnis $galat) {
                return $galat->kode;
            }

            return null;
        };

        expect($kode([[$a, '1', '60'], [$b, '1']]))->toBe('AlokasiHargaTidakValid')
            ->and($kode([[$a, '1', '60'], [$b, '1', '39.999999']]))->toBe('AlokasiHargaTidakValid')
            ->and($kode([[$a, '1', '100.5'], [$b, '1', '0']]))->toBe('AlokasiHargaTidakValid')
            ->and($kode([[$a, '1', '66.666667'], [$b, '1', '33.333333']]))->toBeNull()
            ->and(PaketProdukDetail::query()->orderBy('Urutan')->pluck('AlokasiHarga')->all())->toBe(['66.666667', '33.333333']);
    });

    it('komponen harus bisa dijual, bukan paket, bukan paket itu sendiri, tidak ganda, jumlah > 0. Hanya produk jenis Paket', function (): void {
        BantuanKatalog::BuatTenant();
        $paket = BantuanKomposisi::BuatPaket();
        $paketLain = BantuanKomposisi::BuatPaket('Paket Keluarga Hemat Berempat');
        $bahan = BantuanKomposisi::BuatBahan();
        $induk = BantuanKatalog::BuatProduk(['Jenis' => JenisProduk::IndukVarian, 'Nama' => 'Kaos Polos Katun Combed 30s'], harga: null);
        $anak = BantuanKatalog::BuatProduk(['IdInduk' => $induk->Id, 'Nama' => 'Kaos Polos Katun Combed 30s L', 'KunciVarian' => 'ukuran=l']);
        $nasi = BantuanKatalog::BuatProduk(['Nama' => 'Nasi Ayam Geprek Sambal Bawang']);
        $galat = function (Produk $untuk, array $komponen): array {
            try {
                SimpanKomponenUji($untuk, $komponen);
            } catch (PelanggaranAturanBisnis $galat) {
                return [$galat->kode, $galat->bidang];
            }

            return [];
        };

        expect($galat($paket, []))->toBe(['KomponenTidakValid', 'Komponen'])
            ->and($galat($paket, [[$nasi, '1'], [$bahan, '1']]))->toBe(['KomponenTidakValid', 'Komponen.1.UuidProdukKomponen'])
            ->and($galat($paket, [[$paketLain, '1']]))->toBe(['KomponenTidakValid', 'Komponen.0.UuidProdukKomponen'])
            ->and($galat($paket, [[$paket, '1']]))->toBe(['KomponenTidakValid', 'Komponen.0.UuidProdukKomponen'])
            ->and($galat($paket, [[$induk, '1']]))->toBe(['KomponenTidakValid', 'Komponen.0.UuidProdukKomponen'])
            ->and($galat($paket, [[$nasi, '1'], [$nasi, '2']]))->toBe(['KomponenTidakValid', 'Komponen.1.UuidProdukKomponen'])
            ->and($galat($paket, [[$nasi, '0']]))->toBe(['KomponenTidakValid', 'Komponen.0.Jumlah'])
            ->and($galat($nasi, [[$anak, '1']]))->toBe(['JenisTidakMendukung', 'Komponen'])
            ->and($galat($paket, [[$anak, '1'], [$nasi, '1']]))->toBe([]);
    });

    it('isolasi: komponen tenant lain ditolak, paket tenant lain 404. Kasir 403', function (): void {
        BantuanKatalog::BuatTenant('Warung Makan Tetangga Sebelah');
        $paketB = BantuanKomposisi::BuatPaket();
        $nasiB = BantuanKatalog::BuatProduk(['Nama' => 'Nasi Rames Komplit Lauk Telur']);
        ['Tenant' => $tenantA, 'Pemilik' => $pemilikA] = BantuanKatalog::BuatTenant('Rumah Makan Ayam Geprek Bu Rini');
        $paketA = BantuanKomposisi::BuatPaket();
        $nasiA = BantuanKatalog::BuatProduk(['Nama' => 'Nasi Ayam Geprek Sambal Bawang']);

        expect(fn () => SimpanKomponenUji($paketA, [[$nasiB, '1']]))
            ->toThrow(fn (PelanggaranAturanBisnis $galat) => expect($galat->kode)->toBe('KomponenTidakValid'));

        $tes = BantuanOrganisasi::Masuk($this, $pemilikA, $tenantA->Id);
        $tes->put("/kelola/produk/{$paketA->Uuid}/komponen", IsianKomponenUji([[$nasiB, '1']]))->assertSessionHasErrors(['Komponen.0.UuidProdukKomponen']);
        $tes->put("/kelola/produk/{$paketB->Uuid}/komponen", IsianKomponenUji([[$nasiA, '1']]))->assertNotFound();
        $tes->get("/kelola/produk/{$paketB->Uuid}/komponen")->assertNotFound();
        BantuanKatalog::MasukSebagai($this, $tenantA->Id, PeranTenantBawaan::Kasir)
            ->put("/kelola/produk/{$paketA->Uuid}/komponen", IsianKomponenUji([[$nasiA, '1']]))->assertForbidden();

        BantuanOrganisasi::AturKonteks($tenantA->Id);
        expect(PaketProdukDetail::query()->count())->toBe(0);
    });

    it('prop Komponen: nama, SKU, jumlah, simbol satuan dasar komponen, alokasi "" = otomatis', function (): void {
        BantuanKatalog::BuatTenant();
        $paket = BantuanKomposisi::BuatPaket();
        $nasi = BantuanKatalog::BuatProduk(['Nama' => 'Nasi Ayam Geprek Sambal Bawang']);
        SimpanKomponenUji($paket, [[$nasi, '2']]);

        expect(app(KomponenPaketProduk::class)->Ambil($paket))->toBe([[
            'UuidProdukKomponen' => $nasi->Uuid,
            'Nama' => $nasi->Nama,
            'Sku' => $nasi->Sku,
            'Jumlah' => '2.0000',
            'SimbolSatuan' => 'pcs',
            'AlokasiHarga' => '',
        ]]);
    });

    it('GET komponen merender Kelola/Produk/Komponen (butuh halaman FE). Produk bukan paket 404', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanKatalog::BuatTenant();
        $paket = BantuanKomposisi::BuatPaket();
        $nasi = BantuanKatalog::BuatProduk(['Nama' => 'Nasi Ayam Geprek Sambal Bawang']);
        $tes = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $tes->get("/kelola/produk/{$nasi->Uuid}/komponen")->assertNotFound();
        $tes->get("/kelola/produk/{$paket->Uuid}/komponen")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/Produk/Komponen')
                ->where('Kepala.Uuid', $paket->Uuid)
                ->has('Komponen', 0));
    });
});
