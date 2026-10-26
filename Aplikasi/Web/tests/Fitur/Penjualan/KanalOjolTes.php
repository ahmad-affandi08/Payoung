<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Layanan\PenentuAkun;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use App\Domain\Penjualan\Enum\StatusPenjualan;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Referensi\Enum\JenisReferensiBank;
use App\Domain\Referensi\Model\ReferensiBank;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/**
 * X8 harga per kanal ojol (PRD v2.36): kanal GoFood/GrabFood/ShopeeFood di `Penjualan.Kanal`, metode pembayaran
 * `Marketplace` bertaut satu kanal platform (komisi 0–40%, satu metode per kanal), dana lewat Piutang Pencairan, dan
 * metode platform hanya diterima untuk penjualan kanal yang sama.
 */
beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function BuatMetodePlatform(KanalPenjualan $kanal, string $nama, bool $aktif = true): MetodePembayaran
{
    return MetodePembayaran::query()->create([
        'Jenis' => JenisMetodePembayaran::Marketplace,
        'Nama' => $nama,
        'Kanal' => $kanal,
        'PersenBiaya' => '20',
        'Aktif' => $aktif,
        'Urutan' => 20,
    ]);
}

function SaldoPeranJurnalOjol(int $idJurnal, PeranAkun $peran, int $idOutlet): string
{
    $idAkun = app(PenentuAkun::class)->AmbilIdAkun($peran, $idOutlet);
    $saldo = Kuantitas::Nol();

    foreach (JurnalDetail::query()->where('IdJurnal', $idJurnal)->where('IdAkun', $idAkun)->get() as $b) {
        $saldo = $saldo->Tambah(Kuantitas::Dari($b->Debit))->Kurangi(Kuantitas::Dari($b->Kredit));
    }

    return (string) $saldo->KeDesimal()->toScale(2);
}

describe('X8 / BR-08.7 metode pembayaran platform ojol di panduan awal', function (): void {
    beforeEach(function (): void {
        Storage::fake('local');
        Mail::fake();
        ReferensiBank::query()->create(['Kode' => 'BCA', 'Nama' => 'Bank Central Asia', 'Jenis' => JenisReferensiBank::Bank]);
    });

    it('wajib memilih platform, satu metode per platform, komisi sampai 40 persen (EDC tetap maksimal 10)', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        $tes = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);
        $isian = fn (array $ubah = []): array => ['Jenis' => 'Marketplace', 'Nama' => 'GoFood', 'Kanal' => 'GoFood', 'PersenBiaya' => '22.2', ...$ubah];

        $tes()->get('/kelola/panduan-awal/metode-pembayaran')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('KanalPlatform', [
                ['Nilai' => 'GoFood', 'Label' => 'GoFood'],
                ['Nilai' => 'GrabFood', 'Label' => 'GrabFood'],
                ['Nilai' => 'ShopeeFood', 'Label' => 'ShopeeFood'],
                ['Nilai' => 'Marketplace', 'Label' => 'Marketplace'],
            ])
            ->where('PersenBiayaMaksimal', ['Umum' => '10', 'Platform' => '40'])
            ->where('JenisTersedia', fn ($jenis): bool => collect($jenis)->contains('Nilai', 'Marketplace')));

        $tes()->post('/kelola/panduan-awal/metode-pembayaran', $isian(['Kanal' => null]))->assertSessionHasErrors(['Kanal' => 'Pilih platform: GoFood, GrabFood, ShopeeFood, atau Marketplace.']);
        $tes()->post('/kelola/panduan-awal/metode-pembayaran', $isian(['Kanal' => 'BawaPulang']))->assertSessionHasErrors('Kanal');
        $tes()->post('/kelola/panduan-awal/metode-pembayaran', $isian(['PersenBiaya' => '40.5']))->assertSessionHasErrors(['PersenBiaya' => 'Biaya 0 sampai 40 persen.']);
        $tes()->post('/kelola/panduan-awal/metode-pembayaran', ['Jenis' => 'Edc', 'Nama' => 'EDC BCA', 'KodeBank' => 'BCA', 'PersenBiaya' => '12'])->assertSessionHasErrors(['PersenBiaya' => 'Biaya 0 sampai 10 persen.']);

        $tes()->post('/kelola/panduan-awal/metode-pembayaran', $isian())->assertSessionHasNoErrors();
        $tes()->post('/kelola/panduan-awal/metode-pembayaran', $isian(['Nama' => 'GoFood Cabang 2']))
            ->assertSessionHasErrors(['Kanal' => 'Metode pembayaran untuk GoFood sudah ada. Aktifkan metode yang ada bila dinonaktifkan.']);
        $tes()->post('/kelola/panduan-awal/metode-pembayaran', $isian(['Nama' => 'GrabFood', 'Kanal' => 'GrabFood', 'PersenBiaya' => '30']))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $gofood = MetodePembayaran::query()->where('Nama', 'GoFood')->sole();
        expect($gofood->Kanal)->toBe(KanalPenjualan::GoFood)
            ->and($gofood->PersenBiaya)->toBe('22.200000')
            ->and(MetodePembayaran::query()->where('Nama', 'GrabFood')->sole()->Kanal)->toBe(KanalPenjualan::GrabFood);

        // Metode lain tidak pernah berkanal walau isian Kanal ikut terkirim.
        $tes()->post('/kelola/panduan-awal/metode-pembayaran', ['Jenis' => 'Edc', 'Nama' => 'EDC BCA', 'KodeBank' => 'BCA', 'Kanal' => 'GoFood'])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(MetodePembayaran::query()->where('Nama', 'EDC BCA')->sole()->Kanal)->toBeNull();

        $tes()->get('/kelola/panduan-awal/metode-pembayaran')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('MetodePembayaran', fn ($daftar): bool => collect($daftar)->firstWhere('Nama', 'GoFood')['LabelKanal'] === 'GoFood'
                && collect($daftar)->firstWhere('Nama', 'GoFood')['LabelJenis'] === 'Platform ojol / marketplace'));
    });
});

describe('X8 / BR-08.7 penjualan kanal ojol dari POS', function (): void {
    it('data-awal membawa Kanal metode platform. Penjualan GoFood dibayar GoFood masuk Piutang Pencairan tanpa menyentuh kas', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $gofood = BuatMetodePlatform(KanalPenjualan::GoFood, 'GoFood');
        BuatMetodePlatform(KanalPenjualan::ShopeeFood, 'ShopeeFood (nonaktif)', false);
        $ayam = BantuanKatalog::BuatProduk(['Nama' => 'Ayam Geprek Sambal Bawang Level 3 + Nasi', 'Jenis' => JenisProduk::NonStok], '25000.00');

        $metode = collect($this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->json('MetodePembayaran'));
        expect($metode->firstWhere('Nama', 'GoFood'))->toMatchArray(['Jenis' => 'Marketplace', 'Kanal' => 'GoFood'])
            ->and($metode->firstWhere('Nama', 'ShopeeFood (nonaktif)'))->toBeNull()
            ->and($metode->firstWhere('Jenis', 'Tunai')['Kanal'])->toBeNull();

        // Harga GoFood (input manual) 31.000 dikirim perangkat sebagai snapshot harga.
        $item = BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $ayam, 'Jumlah' => '2', 'Harga' => '31000.00']],
            'Pembayaran' => [['Metode' => $gofood, 'Jumlah' => '62000.00', 'Referensi' => 'F-3281937']],
        ], ['Kanal' => 'GoFood']);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
        expect($p->Kanal)->toBe(KanalPenjualan::GoFood)
            ->and($p->Status)->toBe(StatusPenjualan::Lunas)
            ->and(SaldoPeranJurnalOjol((int) $p->IdJurnal, PeranAkun::PiutangPencairan, $k['Outlet']->Id))->toBe('62000.00')
            ->and(SaldoPeranJurnalOjol((int) $p->IdJurnal, PeranAkun::KasOutlet, $k['Outlet']->Id))->toBe('0.00');

        // Laporan & daftar penjualan menyaring kanal baru.
        $pemilik = fn () => $this->actingAs($k['Pemilik'])->withSession(['IdTenantAktif' => $k['Tenant']->Id]);
        $pemilik()->get('/kelola/penjualan?Kanal=GoFood')->assertOk();
    });

    it('metode GoFood untuk penjualan bawa pulang atau GrabFood ditolak MetodeBayarBedaKanal. Tunai di kanal ojol tetap boleh', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $gofood = BuatMetodePlatform(KanalPenjualan::GoFood, 'GoFood');
        $ayam = BantuanKatalog::BuatProduk(['Nama' => 'Es Teh Manis Jumbo', 'Jenis' => JenisProduk::NonStok], '8000.00');
        $baris = ['Baris' => [['Produk' => $ayam, 'Jumlah' => '1', 'Harga' => '10000.00']]];

        $bawaPulang = BantuanPenjualan::Item($k, $baris + ['Pembayaran' => [['Metode' => $gofood, 'Jumlah' => '10000.00']]]);
        $grab = BantuanPenjualan::Item($k, $baris + ['Pembayaran' => [['Metode' => $gofood, 'Jumlah' => '10000.00']]], ['Kanal' => 'GrabFood']);
        $tunaiShopee = BantuanPenjualan::Item($k, $baris + ['Pembayaran' => [['Metode' => $k['Tunai'], 'Jumlah' => '10000.00']]], ['Kanal' => 'ShopeeFood']);

        $respons = BantuanKasir::Kirim($this, $k['Token'], [$bawaPulang, $grab, $tunaiShopee])->assertOk();
        expect($respons->json('Hasil.0.Galat.Kode'))->toBe('MetodeBayarBedaKanal')
            ->and($respons->json('Hasil.0.Galat.Pesan'))->toBe('GoFood hanya untuk penjualan kanal GoFood.')
            ->and($respons->json('Hasil.1.Galat.Kode'))->toBe('MetodeBayarBedaKanal')
            ->and($respons->json('Hasil.2.Status'))->toBe('Diterima');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->where('Uuid', $tunaiShopee['Uuid'])->sole()->Kanal)->toBe(KanalPenjualan::ShopeeFood)
            ->and(Penjualan::query()->count())->toBe(1);
    });

    it('isolasi tenant: metode GoFood tenant lain tidak dikenal (MetodeBayarTidakDikenal)', function (): void {
        $lain = BantuanPenjualan::Siapkan($this, 'Warung Geprek Mbak Ning');
        $gofoodLain = BuatMetodePlatform(KanalPenjualan::GoFood, 'GoFood');
        $k = BantuanPenjualan::Siapkan($this);
        $ayam = BantuanKatalog::BuatProduk(['Nama' => 'Ayam Bakar Madu', 'Jenis' => JenisProduk::NonStok], '30000.00');

        $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $ayam, 'Jumlah' => '1', 'Harga' => '30000.00']], 'Pembayaran' => [['Metode' => $gofoodLain, 'Jumlah' => '30000.00']]], ['Kanal' => 'GoFood']);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Ditolak', 'MetodeBayarTidakDikenal']])
            ->and($lain['Tenant']->Id)->not->toBe($k['Tenant']->Id);
    });
});
