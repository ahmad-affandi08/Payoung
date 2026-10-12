<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * PRD-10 (F-03): halaman Cetak label. Satu baris = satu satuan produk; induk varian diganti variannya; produk diarsipkan
 * tidak muncul; barcode internal hanya dibuat atas permintaan dan tidak pernah ganda untuk satu satuan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Air mineral dengan satuan dasar pcs (harga Rp 4.500, barcode pabrik) dan satuan dus isi 24 (harga Rp 100.000, tanpa barcode).
 *
 * @return array{Produk: Produk, Pcs: ProdukSatuan, Dus: ProdukSatuan}
 */
function SiapkanAirMineralLabel(array $t): array
{
    $dus = BantuanKatalog::BuatSatuan('Dus', 'dus');
    $produk = BantuanKatalog::BuatProduk(['Nama' => 'Air Mineral Botol 600 ml', 'Sku' => 'AIR-600'], '4500.00', $t['Pcs']);
    $pcs = ProdukSatuan::query()->where('IdProduk', $produk->Id)->sole();
    $satuanDus = ProdukSatuan::query()->create(['IdProduk' => $produk->Id, 'IdSatuan' => $dus->Id, 'KonversiKeDasar' => '24', 'DefaultJual' => false, 'DefaultBeli' => true]);
    ProdukHarga::query()->create(['IdProduk' => $produk->Id, 'IdProdukSatuan' => $satuanDus->Id, 'IdDaftarHarga' => null, 'JumlahMinimum' => '1', 'Harga' => '100000.00']);
    ProdukBarcode::query()->create(['IdProduk' => $produk->Id, 'IdProdukSatuan' => $pcs->Id, 'Barcode' => '8991234567891']);

    return ['Produk' => $produk, 'Pcs' => $pcs, 'Dus' => $satuanDus];
}

describe('PRD-10 halaman Cetak label', function (): void {
    it('satu baris per satuan: barcode & harga dasar masing-masing, satuan tanpa barcode ditandai kosong', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $a = SiapkanAirMineralLabel($t);

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->get("/kelola/produk/label?Produk[]={$a['Produk']->Uuid}")
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman
                ->component('Kelola/Produk/Label')
                ->has('Baris', 2)
                ->where('Baris.0.Uuid', $a['Pcs']->Uuid)
                ->where('Baris.0.Nama', 'Air Mineral Botol 600 ml')
                ->where('Baris.0.Sku', 'AIR-600')
                ->where('Baris.0.SimbolSatuan', 'pcs')
                ->where('Baris.0.Barcode', ['8991234567891'])
                ->where('Baris.0.Harga', '4500.00')
                ->where('Baris.0.Jumlah', 1)
                ->where('Baris.1.Uuid', $a['Dus']->Uuid)
                ->where('Baris.1.SimbolSatuan', 'dus')
                ->where('Baris.1.KonversiKeDasar', '24.0000')
                ->where('Baris.1.Barcode', [])
                ->where('Baris.1.Harga', '100000.00')
                ->where('Terpotong', false)
                ->where('Izin.Kelola', true));
    });

    it('Item[]: satuan tertentu dengan jumlah stiker (penerimaan barang); uuid sama digabung, jumlah dibatasi 500 dan ditandai terpotong', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $a = SiapkanAirMineralLabel($t);

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)
            ->get("/kelola/produk/label?Item[]={$a['Dus']->Uuid}:12&Item[]={$a['Dus']->Uuid}:3&Item[]={$a['Pcs']->Uuid}:999")
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman
                ->has('Baris', 2)
                ->where('Baris.0.Uuid', $a['Dus']->Uuid)
                ->where('Baris.0.Jumlah', 15)
                ->where('Baris.1.Uuid', $a['Pcs']->Uuid)
                ->where('Baris.1.Jumlah', 500)
                ->where('Terpotong', true));
    });

    it('induk varian diganti seluruh variannya; produk diarsipkan dan produk tanpa harga ditangani', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $induk = BantuanKatalog::BuatProduk(['Nama' => 'Kaos Polos Katun', 'Jenis' => JenisProduk::IndukVarian], null, $t['Pcs']);
        $merah = BantuanKatalog::BuatProduk(['Nama' => 'Kaos Polos Katun Merah / L', 'IdInduk' => $induk->Id], '65000.00', $t['Pcs']);
        BantuanKatalog::BuatProduk(['Nama' => 'Kaos Polos Katun Biru / L', 'IdInduk' => $induk->Id, 'DiarsipkanPada' => now()], '65000.00', $t['Pcs']);
        $tanpaHarga = BantuanKatalog::BuatProduk(['Nama' => 'Plastik Kresek'], null, $t['Pcs']);

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)
            ->get("/kelola/produk/label?Produk[]={$induk->Uuid}&Produk[]={$tanpaHarga->Uuid}")
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman
                ->has('Baris', 2)
                ->where('Baris.0.UuidProduk', $merah->Uuid)
                ->where('Baris.0.Harga', '65000.00')
                ->where('Baris.1.UuidProduk', $tanpaHarga->Uuid)
                ->where('Baris.1.Harga', null)
                ->where('Baris.1.Barcode', []));
    });

    it('uuid tidak sah ditolak; tanpa parameter = halaman kosong; produk tenant lain tidak muncul', function (): void {
        $a = BantuanKatalog::SiapkanTenantProduk('Toko Tenant A');
        $rahasia = BantuanKatalog::BuatProduk(['Nama' => 'Produk Rahasia Tenant A'], '1000.00', $a['Pcs']);
        $satuanRahasia = ProdukSatuan::query()->where('IdProduk', $rahasia->Id)->sole();
        $b = BantuanKatalog::SiapkanTenantProduk('Toko Tenant B');
        $masukB = fn () => BantuanKatalog::MasukSebagai($this, $b['Tenant']->Id);

        $masukB()->get('/kelola/produk/label?Produk[]=bukan-ulid')->assertSessionHasErrors('Produk.0');
        $masukB()->get('/kelola/produk/label?Item[]=01JABCDEFGHJKMNPQRSTVWXYZ0:abc')->assertSessionHasErrors('Item.0');
        $masukB()->get('/kelola/produk/label')->assertOk()->assertInertia(fn ($halaman) => $halaman->has('Baris', 0));
        $masukB()->get("/kelola/produk/label?Produk[]={$rahasia->Uuid}&Item[]={$satuanRahasia->Uuid}:5")->assertOk()->assertInertia(fn ($halaman) => $halaman->has('Baris', 0));
        $masukB()->getJson('/kelola/produk/label/cari?kata=Rahasia')->assertOk()->assertExactJson(['Data' => []]);
    });

    it('cari: nama, SKU, atau barcode persis; induk varian dan produk diarsipkan tidak ditawarkan', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $a = SiapkanAirMineralLabel($t);
        BantuanKatalog::BuatProduk(['Nama' => 'Air Mineral Lama', 'DiarsipkanPada' => now()], '1000.00', $t['Pcs']);
        BantuanKatalog::BuatProduk(['Nama' => 'Air Mineral Varian', 'Jenis' => JenisProduk::IndukVarian], null, $t['Pcs']);
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $hasil = $masuk()->getJson('/kelola/produk/label/cari?kata=Air Mineral')->assertOk()->json('Data');
        expect(array_column($hasil, 'Uuid'))->toBe([$a['Pcs']->Uuid, $a['Dus']->Uuid]);
        expect(array_column($masuk()->getJson('/kelola/produk/label/cari?kata=AIR-600')->json('Data'), 'Uuid'))->toBe([$a['Pcs']->Uuid, $a['Dus']->Uuid]);
        expect(array_column($masuk()->getJson('/kelola/produk/label/cari?kata=8991234567891')->json('Data'), 'Uuid'))->toBe([$a['Pcs']->Uuid, $a['Dus']->Uuid]);
        expect($masuk()->getJson('/kelola/produk/label/cari?kata=tidak-ada-produk-ini')->json('Data'))->toBe([]);
    });
});

describe('PRD-10 barcode internal dari halaman Cetak label (atas pilihan sadar)', function (): void {
    it('membuat barcode hanya untuk satuan yang belum punya; yang sudah punya dikembalikan apa adanya; panggilan ulang tidak menggandakan', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $a = SiapkanAirMineralLabel($t);
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $badan = ['Satuan' => [$a['Pcs']->Uuid, $a['Dus']->Uuid]];

        $pertama = $masuk()->postJson('/kelola/produk/label/barcode-internal', $badan)->assertOk()->json('Data');
        expect($pertama[0])->toBe(['Uuid' => $a['Pcs']->Uuid, 'Barcode' => '8991234567891', 'Baru' => false])
            ->and($pertama[1]['Uuid'])->toBe($a['Dus']->Uuid)
            ->and($pertama[1]['Barcode'])->toBe('2000000000015')
            ->and($pertama[1]['Baru'])->toBeTrue();

        $kedua = $masuk()->postJson('/kelola/produk/label/barcode-internal', $badan)->assertOk()->json('Data');
        expect($kedua[1])->toBe(['Uuid' => $a['Dus']->Uuid, 'Barcode' => '2000000000015', 'Baru' => false]);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(ProdukBarcode::query()->where('IdProdukSatuan', $a['Dus']->Id)->count())->toBe(1)
            ->and(ProdukBarcode::query()->where('IdProdukSatuan', $a['Pcs']->Id)->count())->toBe(1)
            ->and(LogAudit::query()->where('Peristiwa', 'produk.barcode.buat')->count())->toBe(1);
    });

    it('menolak kosong, terlalu banyak, uuid tidak dikenal (semua atau tidak sama sekali), dan produk diarsipkan', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $a = SiapkanAirMineralLabel($t);
        $arsip = BantuanKatalog::BuatProduk(['Nama' => 'Produk Lama', 'DiarsipkanPada' => now()], '1000.00', $t['Pcs']);
        $satuanArsip = ProdukSatuan::query()->where('IdProduk', $arsip->Id)->sole();
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->postJson('/kelola/produk/label/barcode-internal', ['Satuan' => []])->assertStatus(422)->assertJsonValidationErrors('Satuan');
        $masuk()->postJson('/kelola/produk/label/barcode-internal', ['Satuan' => array_fill(0, 201, $a['Dus']->Uuid)])->assertStatus(422);
        $masuk()->postJson('/kelola/produk/label/barcode-internal', ['Satuan' => ['bukan-ulid']])->assertStatus(422)->assertJsonValidationErrors('Satuan.0');
        $masuk()->postJson('/kelola/produk/label/barcode-internal', ['Satuan' => [$a['Dus']->Uuid, '01JABCDEFGHJKMNPQRSTVWXYZ0']])->assertStatus(422)->assertJsonPath('Galat.Kode', 'SatuanTidakDitemukan');
        $masuk()->postJson('/kelola/produk/label/barcode-internal', ['Satuan' => [$satuanArsip->Uuid]])->assertStatus(422)->assertJsonPath('Galat.Kode', 'ProdukDiarsipkan');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(ProdukBarcode::query()->where('IdProdukSatuan', $a['Dus']->Id)->count())->toBe(0)
            ->and(ProdukBarcode::query()->count())->toBe(1);
    });

    it('tenant lain tidak bisa membuat barcode untuk satuan tenant ini; hanya izin kelola produk', function (): void {
        $a = BantuanKatalog::SiapkanTenantProduk('Toko Tenant A');
        $air = SiapkanAirMineralLabel($a);
        $b = BantuanKatalog::SiapkanTenantProduk('Toko Tenant B');

        BantuanKatalog::MasukSebagai($this, $b['Tenant']->Id)->postJson('/kelola/produk/label/barcode-internal', ['Satuan' => [$air['Dus']->Uuid]])
            ->assertStatus(422)->assertJsonPath('Galat.Kode', 'SatuanTidakDitemukan');
        BantuanKatalog::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir)
            ->postJson('/kelola/produk/label/barcode-internal', ['Satuan' => [$air['Dus']->Uuid]])->assertForbidden();
        // Kasir (produk.lihat) tetap boleh membuka halaman & mencetak, tetapi tidak diberi tombol membuat barcode.
        BantuanKatalog::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir)->get("/kelola/produk/label?Produk[]={$air['Produk']->Uuid}")
            ->assertOk()->assertInertia(fn ($halaman) => $halaman->where('Izin.Kelola', false)->has('Baris', 2));

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(ProdukBarcode::query()->where('IdProdukSatuan', $air['Dus']->Id)->count())->toBe(0);
    });
});
