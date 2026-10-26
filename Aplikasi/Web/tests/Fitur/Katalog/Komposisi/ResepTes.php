<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Kontrak\PenyediaHppBahan;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Katalog\Resep\Data\DataHppResep;
use App\Domain\Katalog\Resep\Kueri\HppResep;
use App\Domain\Katalog\Resep\Kueri\ResepProduk;
use App\Domain\Katalog\Resep\Model\Resep;
use App\Domain\Katalog\Resep\Model\ResepDetail;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Persediaan\Kueri\HppBahanDariSaldo;
use Brick\Math\BigDecimal;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Katalog\BantuanKomposisi;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/**
 * HPP bahan palsu per Id produk (BR-03.5): menggantikan ikatan bawaan "HPP belum tersedia".
 *
 * @param  array<int, string|null>  $hpp
 */
function IkatHppBahanUji(array $hpp): void
{
    app()->instance(PenyediaHppBahan::class, new class($hpp) implements PenyediaHppBahan
    {
        /** @param array<int, string|null> $hpp */
        public function __construct(private readonly array $hpp) {}

        public function AmbilHppSatuan(int $idProduk, ?int $idGudang): ?BigDecimal
        {
            $nilai = $this->hpp[$idProduk] ?? null;

            return $nilai === null ? null : BigDecimal::of($nilai);
        }
    });
}

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('BR-03.4 resep berversi dan tidak bisa diubah', function (): void {
    it('menyimpan resep lewat HTTP sebagai versi 1 lalu versi 2 tanpa mengubah baris versi 1, tercatat di log audit', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanKatalog::BuatTenant('Kedai Kopi Senja Solo');
        $kopi = BantuanKomposisi::BuatBahan();
        $susu = BantuanKomposisi::BuatBahan('Susu Segar Full Cream Pasteurisasi', 'ml', 'Mililiter');
        $menu = BantuanKomposisi::BuatProdukResep();
        $tes = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $tes->post("/kelola/produk/{$menu->Uuid}/resep", BantuanKomposisi::IsianResep([[$kopi, '18'], [$susu, '150', null, '10']], catatan: 'Espresso double shot'))
            ->assertSessionHasNoErrors()->assertRedirect("/kelola/produk/{$menu->Uuid}/resep");

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $versi1 = Resep::query()->where('IdProduk', $menu->Id)->sole();
        $detail1 = ResepDetail::query()->where('IdResep', $versi1->Id)->orderBy('Urutan')->get()->toArray();
        expect($versi1->Versi)->toBe(1)
            ->and($versi1->JumlahHasil)->toBe('1.0000')
            ->and($versi1->DibuatOleh)->toBe($pemilik->Id)
            ->and(array_column($detail1, 'PersenSusut'))->toBe(['0.000000', '10.000000']);

        $tes->post("/kelola/produk/{$menu->Uuid}/resep", BantuanKomposisi::IsianResep([[$kopi, '20'], [$susu, '150', null, '10']]))
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Resep::query()->where('IdProduk', $menu->Id)->orderBy('Versi')->pluck('Versi')->all())->toBe([1, 2])
            ->and(ResepDetail::query()->where('IdResep', $versi1->Id)->orderBy('Urutan')->get()->toArray())->toBe($detail1)
            ->and(Resep::query()->where('Versi', 2)->sole()->Detail->pluck('Jumlah')->all())->toBe(['20.0000', '150.0000']);
        $this->assertDatabaseHas('LogAudit', ['IdTenant' => $tenant->Id, 'Peristiwa' => 'produk.resep.buat-versi', 'IdObjek' => $versi1->Id, 'IdPengguna' => $pemilik->Id]);
        expect(LogAudit::query()->where('Peristiwa', 'produk.resep.buat-versi')->count())->toBe(2);
    });

    it('kirim ganda dengan isian yang sama menghasilkan satu versi (idempoten)', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanKatalog::BuatTenant();
        $kopi = BantuanKomposisi::BuatBahan();
        $menu = BantuanKomposisi::BuatProdukResep();
        $tes = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);
        $isian = BantuanKomposisi::IsianResep([[$kopi, '18']], catatan: 'Kopi tubruk');

        $tes->post("/kelola/produk/{$menu->Uuid}/resep", $isian)->assertSessionHasNoErrors();
        $tes->post("/kelola/produk/{$menu->Uuid}/resep", $isian)->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Resep::query()->where('IdProduk', $menu->Id)->count())->toBe(1)
            ->and(ResepDetail::query()->count())->toBe(1)
            ->and(LogAudit::query()->where('Peristiwa', 'produk.resep.buat-versi')->count())->toBe(1);

        $versiSama = BantuanKomposisi::SimpanResep($menu, [[$kopi, '18']], catatan: 'Kopi tubruk');
        expect($versiSama->Versi)->toBe(1);
    });

    it('baris Resep dan ResepDetail menolak diubah atau dihapus', function (): void {
        BantuanKatalog::BuatTenant();
        $kopi = BantuanKomposisi::BuatBahan();
        $resep = BantuanKomposisi::SimpanResep(BantuanKomposisi::BuatProdukResep(), [[$kopi, '18']]);
        $detail = ResepDetail::query()->where('IdResep', $resep->Id)->sole();

        expect(fn () => $resep->fill(['JumlahHasil' => '2'])->save())->toThrow(LogicException::class, 'BR-03.4')
            ->and(fn () => $resep->delete())->toThrow(LogicException::class, 'BR-03.4')
            ->and(fn () => $detail->fill(['Jumlah' => '20'])->save())->toThrow(LogicException::class, 'BR-03.4')
            ->and(fn () => $detail->delete())->toThrow(LogicException::class, 'BR-03.4');
        expect(ResepDetail::query()->sole()->Jumlah)->toBe('18.0000');
    });

    it('JumlahDasar dihitung dari konversi satuan saat disimpan dan tidak berubah saat konversi diubah kemudian', function (): void {
        BantuanKatalog::BuatTenant();
        $gula = BantuanKomposisi::BuatBahan('Gula Aren Cair Homemade', 'ml', 'Mililiter');
        $botol = BantuanKomposisi::Satuan('Botol', 'btl', false);
        $satuanBotol = BantuanKomposisi::TambahSatuanProduk($gula, $botol, '750');

        $resep = BantuanKomposisi::SimpanResep(BantuanKomposisi::BuatProdukResep(), [[$gula, '0.04', $botol]]);
        expect(ResepDetail::query()->where('IdResep', $resep->Id)->sole()->JumlahDasar)->toBe('30.0000');

        $satuanBotol->update(['KonversiKeDasar' => '1000']);
        expect(ResepDetail::query()->where('IdResep', $resep->Id)->sole()->JumlahDasar)->toBe('30.0000')
            ->and(ProdukSatuan::query()->findOrFail($satuanBotol->Id)->KonversiKeDasar)->toBe('1000.0000');
    });
});

describe('Aturan resep', function (): void {
    it('menolak produk yang jenisnya tidak memakai resep', function (): void {
        BantuanKatalog::BuatTenant();
        $kopi = BantuanKomposisi::BuatBahan();

        expect(fn () => BantuanKomposisi::SimpanResep(BantuanKatalog::BuatProduk(), [[$kopi, '18']]))
            ->toThrow(fn (PelanggaranAturanBisnis $galat) => expect($galat->kode)->toBe('JenisTidakPunyaResep'));
    });

    it('menolak resep tanpa bahan, hasil 0, bahan yang bukan bahan, produk itu sendiri, satuan asing, jumlah 0, dan susut 100%', function (): void {
        BantuanKatalog::BuatTenant();
        $kopi = BantuanKomposisi::BuatBahan();
        $menu = BantuanKomposisi::BuatProdukResep();
        $jasa = BantuanKatalog::BuatProduk(['Nama' => 'Jasa Antar Kurir Internal', 'Jenis' => JenisProduk::Jasa]);
        $liter = BantuanKomposisi::Satuan('Liter', 'l');
        $galat = function (array $bahan, string $hasil = '1'): array {
            try {
                BantuanKomposisi::SimpanResep(Produk::query()->where('Jenis', JenisProduk::Resep)->firstOrFail(), $bahan, $hasil);
            } catch (PelanggaranAturanBisnis $galat) {
                return [$galat->kode, $galat->bidang];
            }

            return [];
        };

        expect($galat([]))->toBe(['ResepKosong', 'Bahan'])
            ->and($galat([[$kopi, '18']], '0'))->toBe(['JumlahHasilTidakValid', 'JumlahHasil'])
            ->and($galat([[$kopi, '18'], [$jasa, '1']]))->toBe(['BahanTidakValid', 'Bahan.1.UuidProdukBahan'])
            ->and($galat([[$menu, '1']]))->toBe(['BahanTidakValid', 'Bahan.0.UuidProdukBahan'])
            ->and($galat([[$kopi, '18', $liter]]))->toBe(['BahanTidakValid', 'Bahan.0.UuidSatuan'])
            ->and($galat([[$kopi, '0']]))->toBe(['BahanTidakValid', 'Bahan.0.Jumlah'])
            ->and($galat([[$kopi, '18', null, '100']]))->toBe(['BahanTidakValid', 'Bahan.0.PersenSusut'])
            ->and($galat([[$kopi, '18', null, '99.999999']]))->toBe([])
            ->and(Resep::query()->count())->toBe(1);
    });

    it('menolak resep melingkar lewat bahan produksi setengah jadi (ResepSiklus)', function (): void {
        BantuanKatalog::BuatTenant();
        $gula = BantuanKomposisi::BuatBahan('Gula Pasir Kristal Putih');
        $sirup = BantuanKomposisi::BuatProdukResep('Sirup Gula Aren Homemade 1 Liter', JenisProduk::Produksi);
        $saus = BantuanKomposisi::BuatProdukResep('Saus Karamel Asin Produksi Dapur', JenisProduk::Produksi);

        BantuanKomposisi::SimpanResep($sirup, [[$gula, '500']]);
        BantuanKomposisi::SimpanResep($saus, [[$sirup, '1']]);

        expect(fn () => BantuanKomposisi::SimpanResep($sirup, [[$gula, '500'], [$saus, '1']]))
            ->toThrow(fn (PelanggaranAturanBisnis $galat) => expect($galat->kode)->toBe('ResepSiklus'));
        expect(Resep::query()->where('IdProduk', $sirup->Id)->count())->toBe(1);
    });

    it('izin: kasir hanya boleh melihat, menyimpan resep ditolak 403', function (): void {
        ['Tenant' => $tenant] = BantuanKatalog::BuatTenant();
        $kopi = BantuanKomposisi::BuatBahan();
        $menu = BantuanKomposisi::BuatProdukResep();

        BantuanKatalog::MasukSebagai($this, $tenant->Id, PeranTenantBawaan::Kasir)
            ->post("/kelola/produk/{$menu->Uuid}/resep", BantuanKomposisi::IsianResep([[$kopi, '18']]))
            ->assertForbidden();
    });
});

describe('Isolasi tenant resep', function (): void {
    it('bahan dan satuan milik tenant lain ditolak, produk tenant lain 404', function (): void {
        ['Tenant' => $tenantA, 'Pemilik' => $pemilikA] = BantuanKatalog::BuatTenant('Kedai Kopi Senja Solo');
        $menuA = BantuanKomposisi::BuatProdukResep();
        $kopiA = BantuanKomposisi::BuatBahan();

        BantuanKatalog::BuatTenant('Warung Kopi Tetangga Sebelah');
        $kopiB = BantuanKomposisi::BuatBahan();
        $menuB = BantuanKomposisi::BuatProdukResep();
        $isianB = BantuanKomposisi::IsianResep([[$kopiB, '18']]);

        $tes = BantuanOrganisasi::Masuk($this, $pemilikA, $tenantA->Id);
        $tes->post("/kelola/produk/{$menuA->Uuid}/resep", $isianB)
            ->assertSessionHasErrors(['Bahan.0.UuidProdukBahan', 'Bahan.0.UuidSatuan']);
        $tes->post("/kelola/produk/{$menuB->Uuid}/resep", BantuanKomposisi::IsianResep([[$kopiA, '18']]))->assertNotFound();
        $tes->get("/kelola/produk/{$menuB->Uuid}/resep")->assertNotFound();

        BantuanOrganisasi::AturKonteks($tenantA->Id);
        expect(fn () => BantuanKomposisi::SimpanResep($menuA, [[$kopiB, '18', $kopiA->SatuanDasar]]))
            ->toThrow(fn (PelanggaranAturanBisnis $galat) => expect($galat->kode)->toBe('BahanTidakValid'));
        expect(Resep::query()->count())->toBe(0);
    });
});

describe('BR-03.5 estimasi HPP resep', function (): void {
    it('HPP = Σ(jumlah kotor × HPP bahan) ÷ hasil, susut H7 = JumlahDasar ÷ (1 − susut/100)', function (): void {
        BantuanKatalog::BuatTenant();
        $kopi = BantuanKomposisi::BuatBahan();
        $susu = BantuanKomposisi::BuatBahan('Susu Segar Full Cream Pasteurisasi', 'ml', 'Mililiter');
        $menu = BantuanKomposisi::BuatProdukResep();
        IkatHppBahanUji([$kopi->Id => '250', $susu->Id => '20']);

        BantuanKomposisi::SimpanResep($menu, [[$kopi, '18'], [$susu, '150', null, '10']]);
        $hpp = app(HppResep::class)->Hitung($menu);

        // 18 × 250 = 4500; 150 ÷ 0,9 = 166,6667 (skala 4) × 20 = 3333,334; total 7833,334.
        expect($hpp->status)->toBe(DataHppResep::TERSEDIA)
            ->and($hpp->hppSatuan)->toBe('7833.334000')
            ->and($hpp->baris)->toBe([
                ['NamaBahan' => $kopi->Nama, 'JumlahKotor' => '18.0000', 'HppSatuanBahan' => '250.000000', 'Subtotal' => '4500.000000'],
                ['NamaBahan' => $susu->Nama, 'JumlahKotor' => '166.6667', 'HppSatuanBahan' => '20.000000', 'Subtotal' => '3333.334000'],
            ]);

        BantuanKomposisi::SimpanResep($menu, [[$kopi, '18'], [$susu, '150', null, '10']], '20');
        expect(app(HppResep::class)->Hitung($menu)->hppSatuan)->toBe('391.666700');
    });

    it('satu bahan tanpa HPP → BelumTersedia. Ikatan F-05a (HPP dari saldo) tanpa saldo bahan → BelumTersedia, tanpa resep → TanpaResep', function (): void {
        BantuanKatalog::BuatTenant();
        $kopi = BantuanKomposisi::BuatBahan();
        $susu = BantuanKomposisi::BuatBahan('Susu Segar Full Cream Pasteurisasi', 'ml', 'Mililiter');
        $menu = BantuanKomposisi::BuatProdukResep();

        expect(app(HppResep::class)->Hitung($menu)->KeArray())->toBe(['Status' => 'TanpaResep', 'HppSatuan' => null, 'Baris' => []])
            ->and(app(PenyediaHppBahan::class))->toBeInstanceOf(HppBahanDariSaldo::class);

        BantuanKomposisi::SimpanResep($menu, [[$kopi, '18'], [$susu, '150']]);
        $bawaan = app(HppResep::class)->Hitung($menu);
        expect($bawaan->status)->toBe('BelumTersedia')->and($bawaan->hppSatuan)->toBeNull();

        IkatHppBahanUji([$kopi->Id => '250', $susu->Id => null]);
        $sebagian = app(HppResep::class)->Hitung($menu);
        expect($sebagian->status)->toBe('BelumTersedia')
            ->and($sebagian->hppSatuan)->toBeNull()
            ->and($sebagian->baris[0]['Subtotal'])->toBe('4500.000000')
            ->and($sebagian->baris[1]['Subtotal'])->toBeNull();
    });
});

describe('Halaman resep', function (): void {
    it('prop versi terbaru, versi lama (?versi=), daftar versi dengan nama pembuat, satuan hasil dan satuan dasar bahan', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanKatalog::BuatTenant();
        $gula = BantuanKomposisi::BuatBahan('Gula Aren Cair Homemade', 'ml', 'Mililiter');
        $botol = BantuanKomposisi::Satuan('Botol', 'btl', false);
        BantuanKomposisi::TambahSatuanProduk($gula, $botol, '750');
        $menu = BantuanKomposisi::BuatProdukResep();
        BantuanKomposisi::SimpanResep($menu, [[$gula, '30']], idPembuat: $pemilik->Id);
        BantuanKomposisi::SimpanResep($menu, [[$gula, '0.04', $botol, '2.5']], '2', 'Lebih manis', $pemilik->Id);

        $props = app(ResepProduk::class)->Ambil($menu);
        expect($props['VersiTerbaru'])->toBe(2)
            ->and(array_column($props['DaftarVersi'], 'Versi'))->toBe([2, 1])
            ->and($props['DaftarVersi'][0]['NamaPembuat'])->toBe($pemilik->Nama)
            ->and($props['Resep'])->toMatchArray(['Versi' => 2, 'JumlahHasil' => '2.0000', 'SimbolSatuanHasil' => 'cup', 'Catatan' => 'Lebih manis', 'NamaPembuat' => $pemilik->Nama])
            ->and($props['Resep']['Bahan'] ?? null)->toBe([[
                'UuidProdukBahan' => $gula->Uuid,
                'NamaBahan' => $gula->Nama,
                'Sku' => $gula->Sku,
                'Jumlah' => '0.0400',
                'UuidSatuan' => $botol->Uuid,
                'SimbolSatuan' => 'btl',
                'JumlahDasar' => '30.0000',
                'SimbolSatuanDasar' => 'ml',
                'PersenSusut' => '2.500000',
            ]])
            ->and(app(ResepProduk::class)->Ambil($menu, 1)['Resep']['Versi'] ?? null)->toBe(1);

        $tes = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);
        $tes->get("/kelola/produk/{$menu->Uuid}/resep?versi=9")->assertNotFound();
        $tes->get("/kelola/produk/{$menu->Uuid}/resep?versi=abc")->assertNotFound();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $tes->get('/kelola/produk/'.BantuanKatalog::BuatProduk()->Uuid.'/resep')->assertNotFound();
    });

    it('GET halaman resep merender Kelola/Produk/Resep dengan Kepala, Hpp, dan Izin (butuh halaman FE)', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanKatalog::BuatTenant();
        $kopi = BantuanKomposisi::BuatBahan();
        $menu = BantuanKomposisi::BuatProdukResep();
        BantuanKomposisi::SimpanResep($menu, [[$kopi, '18']]);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get("/kelola/produk/{$menu->Uuid}/resep?versi=1")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/Produk/Resep')
                ->where('Kepala.Uuid', $menu->Uuid)
                ->where('Resep.Versi', 1)
                ->where('VersiTerbaru', 1)
                ->where('Hpp.Status', 'BelumTersedia')
                ->where('Izin.Kelola', true));
    });
});
