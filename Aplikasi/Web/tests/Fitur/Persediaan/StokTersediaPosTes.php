<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\Satuan;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Kueri\OutletPenjualan;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Model\SaldoStok;
use Brick\Math\BigDecimal;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-07 + F-05 BR-05.2 di POS: `GET /api/pos/v1/stok-tersedia` memberi kasir offline-first sisa stok lokasi stok Toko
 * outlet untuk produk berstok yang TIDAK boleh minus. Produk yang tidak ada di daftar = tanpa batas. Hanya baca:
 * penerimaan penjualan di server tidak berubah.
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

/**
 * @return array<string, string> UuidProduk => Tersedia
 */
function AmbilStokTersedia(object $tes, string $token): array
{
    $respons = $tes->withToken($token)->getJson('/api/pos/v1/stok-tersedia')->assertOk();
    $peta = [];

    foreach ($respons->json('Produk') as $baris) {
        $peta[$baris['UuidProduk']] = $baris['Tersedia'];
    }

    return $peta;
}

/**
 * Tenant ber-perangkat kasir + produk tiap jenis; stok Toko diisi langsung lewat ledger uji.
 *
 * @return array<string, mixed>
 */
function SiapkanStokPos(object $tes, string $nama = 'Toko Sembako Berkah Jaya'): array
{
    $k = BantuanKasir::Siapkan($tes, $nama);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    // Lokasi stok Toko outlet yang dipakai server (dibuat bersama tenant) adalah sumber kebenaran uji.
    $gudangToko = app(OutletPenjualan::class)->AmbilIdGudangToko($k['Outlet']->Id);
    $pcs = Satuan::query()->where('Simbol', 'pcs')->firstOrFail();
    $kg = Satuan::query()->where('Simbol', 'kg')->firstOrFail();

    return [...$k, 'IdGudangToko' => $gudangToko, 'Pcs' => $pcs, 'Kg' => $kg];
}

function IsiStokUji(Produk $produk, int $idGudang, string $jumlah): void
{
    BantuanPersediaan::TulisMutasiLangsung($produk->Id, $idGudang, $jumlah, (string) BigDecimal::of($jumlah)->multipliedBy(1000)->toScale(2));
}

describe('F-07 BR-05.2 stok tersedia untuk kasir', function (): void {
    it('produk tidak boleh minus muncul dengan sisa stok benar, tanpa saldo = 0.0000. Saldo minus dan desimal apa adanya', function (): void {
        $k = SiapkanStokPos($this);
        $minyak = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter'], '38500.00', $k['Pcs']);
        $beras = BantuanKatalog::BuatProduk(['Nama' => 'Beras Pandan Wangi Cianjur Premium'], '16000.00', $k['Pcs']);
        $gula = BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir Kristal Putih Curah', 'Jenis' => JenisProduk::BahanBaku, 'TampilDiPos' => false], null, $k['Kg']);
        $telur = BantuanKatalog::BuatProduk(['Nama' => 'Telur Ayam Negeri Grade A'], '2500.00', $k['Pcs']);
        IsiStokUji($minyak, $k['IdGudangToko'], '12');
        IsiStokUji($gula, $k['IdGudangToko'], '2.5');
        IsiStokUji($telur, $k['IdGudangToko'], '3');
        // Terjual di kasir melebihi stok (server tetap menerima): saldo jadi minus.
        BantuanPersediaan::TulisMutasiLangsung($telur->Id, $k['IdGudangToko'], '-5', '-5000.00', ['JenisMutasi' => JenisMutasi::PenyesuaianKeluar]);

        $hasil = AmbilStokTersedia($this, $k['Token']);

        expect($hasil[$minyak->Uuid])->toBe('12.0000')
            ->and($hasil[$gula->Uuid])->toBe('2.5000')
            ->and($hasil[$telur->Uuid])->toBe('-2.0000')
            ->and($hasil[$beras->Uuid])->toBe('0.0000');
    });

    it('jawaban memuat WaktuServer ISO8601 Zulu dan setiap Tersedia berupa string desimal', function (): void {
        $k = SiapkanStokPos($this);
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Bubuk Robusta Lampung 250 gram'], '32000.00', $k['Pcs']);
        IsiStokUji($produk, $k['IdGudangToko'], '4');

        $respons = $this->withToken($k['Token'])->getJson('/api/pos/v1/stok-tersedia')->assertOk();

        expect($respons->json('WaktuServer'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/')
            ->and($respons->json('Produk.0'))->toBe(['UuidProduk' => $produk->Uuid, 'Tersedia' => '4.0000']);
    });

    it('produk boleh minus (Produk.BolehMinus=true atau pengaturan tenant) tidak muncul; Produk.BolehMinus=false mengalahkan tenant', function (): void {
        $k = SiapkanStokPos($this);
        $bebas = BantuanKatalog::BuatProduk(['Nama' => 'Sabun Cuci Piring Jeruk Nipis 800 ml', 'BolehMinus' => true], '15000.00', $k['Pcs']);
        $ikutTenant = BantuanKatalog::BuatProduk(['Nama' => 'Pewangi Pakaian Lavender 900 ml'], '18000.00', $k['Pcs']);
        $dilarang = BantuanKatalog::BuatProduk(['Nama' => 'Tepung Terigu Protein Sedang 1 kg', 'BolehMinus' => false], '13000.00', $k['Pcs']);
        IsiStokUji($ikutTenant, $k['IdGudangToko'], '7');

        $hasil = AmbilStokTersedia($this, $k['Token']);
        expect($hasil)->not->toHaveKey($bebas->Uuid)
            ->and($hasil)->toHaveKey($ikutTenant->Uuid)
            ->and($hasil)->toHaveKey($dilarang->Uuid);

        BantuanPersediaan::AturMetodeHpp($k['Tenant'], MetodeHpp::RataRata, true);

        $hasil = AmbilStokTersedia($this, $k['Token']);
        expect($hasil)->not->toHaveKey($bebas->Uuid)
            ->and($hasil)->not->toHaveKey($ikutTenant->Uuid)
            ->and($hasil[$dilarang->Uuid])->toBe('0.0000');
    });

    it('produk batch dan seri tetap muncul walau tenant mengizinkan stok minus', function (): void {
        $k = SiapkanStokPos($this);
        BantuanPersediaan::AturMetodeHpp($k['Tenant'], MetodeHpp::RataRata, true);
        $batch = BantuanKatalog::BuatProduk(['Nama' => 'Susu UHT Full Cream 1 Liter (batch)', 'Pelacakan' => PelacakanProduk::Batch, 'BolehMinus' => true], '19500.00', $k['Pcs']);
        $seri = BantuanKatalog::BuatProduk(['Nama' => 'Rice Cooker Digital 1,8 Liter (seri)', 'Pelacakan' => PelacakanProduk::Seri], '675000.00', $k['Pcs']);
        IsiStokUji($batch, $k['IdGudangToko'], '24');

        $hasil = AmbilStokTersedia($this, $k['Token']);

        expect($hasil[$batch->Uuid])->toBe('24.0000')
            ->and($hasil[$seri->Uuid])->toBe('0.0000');
    });

    it('jasa, tanpa stok, resep, paket, induk varian, konsinyasi, dan produk diarsipkan tidak muncul', function (): void {
        $k = SiapkanStokPos($this);
        $tidakMuncul = [];

        foreach ([JenisProduk::Jasa, JenisProduk::NonStok, JenisProduk::Resep, JenisProduk::Paket, JenisProduk::IndukVarian, JenisProduk::Konsinyasi] as $jenis) {
            $tidakMuncul[] = BantuanKatalog::BuatProduk(['Nama' => "Uji jenis {$jenis->value} untuk kasir", 'Jenis' => $jenis], '10000.00', $k['Pcs'])->Uuid;
        }

        $arsip = BantuanKatalog::BuatProduk(['Nama' => 'Produk lama sudah diarsipkan', 'DiarsipkanPada' => now()], '10000.00', $k['Pcs']);
        $tidakMuncul[] = $arsip->Uuid;
        $stok = BantuanKatalog::BuatProduk(['Nama' => 'Mi Instan Goreng Spesial Isi 5'], '14000.00', $k['Pcs']);

        $hasil = AmbilStokTersedia($this, $k['Token']);

        expect(array_keys($hasil))->toBe([$stok->Uuid]);

        foreach ($tidakMuncul as $uuid) {
            expect($hasil)->not->toHaveKey($uuid);
        }
    });

    it('sisa stok hanya dari lokasi stok Toko outlet. Gudang lain dan outlet lain tidak dihitung', function (): void {
        $k = SiapkanStokPos($this);
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Air Mineral Botol 600 ml Dus 24'], '48000.00', $k['Pcs']);
        $gudangBelakang = BantuanPersediaan::BuatGudang($k['Outlet'], 'Gudang Belakang', JenisGudang::Gudang);
        IsiStokUji($produk, $k['IdGudangToko'], '5');
        IsiStokUji($produk, $gudangBelakang->Id, '100');

        $outletB = BantuanDokumenPersediaan::BuatOutlet('SOLO', 'Cabang Solo Baru');
        $gudangB = BantuanPersediaan::BuatGudang($outletB, 'Toko Cabang Solo', JenisGudang::Toko);
        IsiStokUji($produk, $gudangB->Id, '40');
        $perangkatB = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $outletB, 'Kasir Cabang Solo');

        expect(AmbilStokTersedia($this, $k['Token']))->toBe([$produk->Uuid => '5.0000'])
            ->and(AmbilStokTersedia($this, $perangkatB['Token']))->toBe([$produk->Uuid => '40.0000']);
    });

    it('outlet tanpa lokasi stok Toko mendapat daftar kosong', function (): void {
        $k = SiapkanStokPos($this);
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Kecap Manis Botol 275 ml'], '12000.00', $k['Pcs']);
        IsiStokUji($produk, $k['IdGudangToko'], '9');
        $outletB = BantuanDokumenPersediaan::BuatOutlet('KLATEN', 'Cabang Klaten');
        $perangkatB = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $outletB, 'Kasir Klaten');

        $respons = $this->withToken($perangkatB['Token'])->getJson('/api/pos/v1/stok-tersedia')->assertOk();

        expect($respons->json('Produk'))->toBe([])
            ->and($respons->json('WaktuServer'))->toBeString();
    });

    it('isolasi tenant: produk dan stok tenant lain tidak bocor', function (): void {
        $a = SiapkanStokPos($this, 'Toko Sembako Berkah Jaya');
        $produkA = BantuanKatalog::BuatProduk(['Nama' => 'Garam Dapur Halus Beryodium 250 gram'], '4000.00', $a['Pcs']);
        IsiStokUji($produkA, $a['IdGudangToko'], '30');

        $b = SiapkanStokPos($this, 'Warung Bakso Pak Kumis');
        $produkB = BantuanKatalog::BuatProduk(['Nama' => 'Bakso Urat Sapi Kemasan 500 gram'], '35000.00', $b['Pcs']);
        IsiStokUji($produkB, $b['IdGudangToko'], '8');

        expect(AmbilStokTersedia($this, $b['Token']))->toBe([$produkB->Uuid => '8.0000'])
            ->and(AmbilStokTersedia($this, $a['Token']))->toBe([$produkA->Uuid => '30.0000']);
    });

    it('hanya baca: tidak menambah mutasi atau saldo stok', function (): void {
        $k = SiapkanStokPos($this);
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Teh Celup Melati Isi 25 Kantong'], '9000.00', $k['Pcs']);
        IsiStokUji($produk, $k['IdGudangToko'], '6');
        $sebelum = SaldoStok::query()->count();

        AmbilStokTersedia($this, $k['Token']);
        AmbilStokTersedia($this, $k['Token']);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(SaldoStok::query()->count())->toBe($sebelum)
            ->and(SaldoStok::query()->where('IdProduk', $produk->Id)->value('JumlahTersedia'))->toBe('6.0000');
    });

    it('kontrak: tanpa token atau token palsu 401', function (): void {
        $this->getJson('/api/pos/v1/stok-tersedia')->assertUnauthorized();
        $this->withToken('token-palsu-bukan-perangkat')->getJson('/api/pos/v1/stok-tersedia')->assertUnauthorized();
    });
});
