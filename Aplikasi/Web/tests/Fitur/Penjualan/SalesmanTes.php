<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\JenisPerangkat;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Enum\HasilKunjungan;
use App\Domain\Penjualan\Enum\StatusPesananGrosir;
use App\Domain\Penjualan\Enum\SumberPesananGrosir;
use App\Domain\Penjualan\Model\KunjunganSales;
use App\Domain\Penjualan\Model\PesananGrosir;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * Modul Salesman bagian 1 (§9.7 grosir, SLS-11, §19 peran "Sales/Salesman"): salesman mengambil pesanan grosir dan
 * mencatat kunjungan dari HP (outbox `PesananGrosir.Buat` & `Kunjungan.Catat`, bisa offline), melihat pelanggan,
 * piutang, dan stok lewat `/api/pos/v1/salesman/*`, lalu back-office membaca kunjungan di Grosir › Kunjungan.
 *
 * Yang dijaga: harga pesanan dari price engine server (klien tidak bisa menyetel harga), pesanan masuk Draf sehingga
 * konfirmasi & limit kredit BR-12.6 tetap di back-office, idempotensi per Uuid, koordinat disimpan sebagai desimal,
 * izin `salesman.kunjungan`, dan isolasi tenant.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    // Jam dibekukan siang hari WIB pada tanggal UTC yang sama: tes memakai tanggal Asia/Jakarta, sedangkan runner CI
    // berjalan kapan saja. Tanpa ini tes gagal antara 17.00 dan 24.00 UTC (sudah "besok" di WIB).
    $this->travelTo(CarbonImmutable::now('UTC')->setTime(5, 0));
});

/**
 * Distributor sembako + HP salesman (jenis Salesman) + salesman ber-peran Salesman + toko pelanggan + gula berstok
 * 500 pcs @ Rp 15.000.
 *
 * @return array<string, mixed>
 */
function SiapkanSalesman(TestCase $tes, string $nama = 'Distributor Sembako Sumber Rejeki'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $nama);
    ['Perangkat' => $hp, 'Kode' => $kode] = BantuanPerangkat::BuatPerangkat($k['Tenant']->Id, $k['Outlet'], JenisPerangkat::Salesman, 'HP Salesman Budi');
    $tokenHp = BantuanPerangkat::Aktifkan($tes, $kode);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $salesman = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Salesman);
    $gula = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Gula Pasir Kristal Putih Kemasan 1 kg', '500', '12500', '15000.00');
    $toko = Pelanggan::query()->create([
        'Nama' => 'Toko Kelontong Makmur Jaya Abadi',
        'NoHp' => '6281355550001',
        'Alamat' => 'Jl. Slamet Riyadi No. 212, Purwosari, Laweyan, Surakarta',
        'LimitKredit' => '25000000',
        'TerminHari' => 30,
    ]);

    return $k + ['Hp' => $hp, 'TokenHp' => $tokenHp, 'Salesman' => $salesman, 'Gula' => $gula, 'Toko' => $toko];
}

/**
 * @param  array<string, mixed>  $k
 * @param  list<array<string, mixed>>  $baris
 * @param  array<string, mixed>  $timpa
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemPesananSalesman(array $k, array $baris, array $timpa = [], ?string $uuid = null): array
{
    return [
        'Jenis' => 'PesananGrosir.Buat',
        'Uuid' => $uuid ?? BantuanKasir::Uuid(),
        'Data' => array_merge([
            'UuidPelanggan' => $k['Toko']->Uuid,
            'UuidPengguna' => $k['Salesman']->Uuid,
            'DibuatPada' => CarbonImmutable::now()->subMinutes(20)->utc()->toIso8601ZuluString(),
            'Catatan' => 'Kirim Kamis pagi sebelum jam 10',
            'Baris' => $baris,
        ], $timpa),
    ];
}

/**
 * @param  array<string, mixed>  $k
 * @param  array<string, mixed>  $timpa
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemKunjungan(array $k, array $timpa = [], ?string $uuid = null): array
{
    return [
        'Jenis' => 'Kunjungan.Catat',
        'Uuid' => $uuid ?? BantuanKasir::Uuid(),
        'Data' => array_merge([
            'UuidPelanggan' => $k['Toko']->Uuid,
            'UuidPengguna' => $k['Salesman']->Uuid,
            'MasukPada' => CarbonImmutable::now()->subMinutes(35)->utc()->toIso8601ZuluString(),
            'KeluarPada' => CarbonImmutable::now()->subMinutes(10)->utc()->toIso8601ZuluString(),
            'Latitude' => '-7.5666001',
            'Longitude' => '110.8166002',
            'AkurasiMeter' => 12,
            'Hasil' => 'PesananDibuat',
            'Catatan' => 'Pemilik toko minta harga khusus bulan depan',
        ], $timpa),
    ];
}

/** @param  array<string, mixed>  $k */
function GetSalesman(TestCase $tes, array $k, string $alamat, ?Pengguna $pelaku = null): TestResponse
{
    return $tes->withToken($k['TokenHp'])->withHeader('X-Id-Kasir', ($pelaku ?? $k['Salesman'])->Uuid)->getJson("/api/pos/v1/salesman/{$alamat}");
}

describe('Outbox PesananGrosir.Buat dari aplikasi salesman', function (): void {
    it('membuat draf pesanan grosir bersumber Salesman dengan harga dari server, idempoten per Uuid', function (): void {
        /** @var TestCase $this */
        $k = SiapkanSalesman($this);
        // Klien mencoba menyelipkan harga; harga itu wajib diabaikan.
        $item = ItemPesananSalesman($k, [['UuidProduk' => $k['Gula']->Uuid, 'Jumlah' => '120', 'Harga' => '1000.00', 'HargaSatuan' => '1000.00']]);

        expect(BantuanKasir::KirimRingkas($this, $k['TokenHp'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(BantuanKasir::KirimRingkas($this, $k['TokenHp'], [$item]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        $pesanan = PesananGrosir::query()->with('Detail')->sole();
        expect($pesanan->Uuid)->toBe(strtoupper($item['Uuid']))
            ->and($pesanan->Status)->toBe(StatusPesananGrosir::Draf)
            ->and($pesanan->Sumber)->toBe(SumberPesananGrosir::Salesman)
            ->and($pesanan->IdSalesman)->toBe($k['Salesman']->Id)
            ->and($pesanan->IdPerangkat)->toBe($k['Hp']->Id)
            ->and($pesanan->DibuatOleh)->toBe($k['Salesman']->Id)
            // Satuan tidak dikirim = satuan dasar; harga Rp 15.000 dari katalog, bukan Rp 1.000 kiriman klien.
            ->and($pesanan->Detail[0]->SimbolSatuan)->toBe('pcs')
            ->and($pesanan->Detail[0]->Harga)->toBe('15000.00')
            ->and($pesanan->Total)->toBe('1800000.00')
            ->and($pesanan->Catatan)->toBe('Kirim Kamis pagi sebelum jam 10')
            // Bukan peristiwa akuntansi: stok belum bergerak sampai surat jalan.
            ->and(LogAudit::query()->where('Peristiwa', 'grosir.pesanan-salesman')->count())->toBe(1);
    });

    it('produk/pelanggan tidak dikenal dan produk di luar cakupan grosir ditolak dengan kodenya', function (): void {
        /** @var TestCase $this */
        $k = SiapkanSalesman($this);
        // Sejak v6.18 (D-137) produk ber-batch/seri masuk cakupan grosir (batch FEFO dan nomor seri dipilih saat surat jalan);
        // yang tetap ditolak adalah produk bukan-stok seperti jasa.
        $jasa = BantuanKatalog::BuatProduk(['Nama' => 'Jasa Bongkar Muat Gudang', 'Jenis' => JenisProduk::Jasa], '150000.00');

        $hasil = BantuanKasir::KirimRingkas($this, $k['TokenHp'], [
            ItemPesananSalesman($k, [['UuidProduk' => BantuanKasir::Uuid(), 'Jumlah' => '5']]),
            ItemPesananSalesman($k, [['UuidProduk' => $k['Gula']->Uuid, 'Jumlah' => '5']], ['UuidPelanggan' => BantuanKasir::Uuid()]),
            ItemPesananSalesman($k, [['UuidProduk' => $jasa->Uuid, 'Jumlah' => '1']]),
            ItemPesananSalesman($k, [['UuidProduk' => $k['Gula']->Uuid, 'Jumlah' => '0']]),
        ]);

        expect($hasil)->toBe([
            ['Ditolak', 'ProdukTidakDikenal'],
            ['Ditolak', 'PelangganTidakDikenal'],
            ['Ditolak', 'JenisProdukBelumDidukung'],
            ['Ditolak', 'DataTidakValid'],
        ]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PesananGrosir::query()->count())->toBe(0);
    });

    it('produk bernomor seri diterima sebagai draf: nomor serinya dipilih saat surat jalan (v6.18)', function (): void {
        /** @var TestCase $this */
        $k = SiapkanSalesman($this);
        $seri = BantuanKatalog::BuatProduk(['Nama' => 'Ponsel Android 128 GB Garansi Resmi', 'Pelacakan' => PelacakanProduk::Seri], '2499000.00');

        expect(BantuanKasir::KirimRingkas($this, $k['TokenHp'], [ItemPesananSalesman($k, [['UuidProduk' => $seri->Uuid, 'Jumlah' => '2']])]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PesananGrosir::query()->sole()->Status)->toBe(StatusPesananGrosir::Draf);
    });

    it('pengguna tanpa izin salesman maupun izin jual tetap diterima sebagai draf, alasannya di log audit', function (): void {
        /** @var TestCase $this */
        $k = SiapkanSalesman($this);
        // Staf gudang (tanpa salesman.kunjungan dan tanpa penjualan.buat) mencatat pesanan di HP yang sama: kejadian
        // lapangan tidak ditolak, tetapi ditandai agar operator back-office memutuskannya saat konfirmasi.
        $gudang = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::StafGudang);
        $item = ItemPesananSalesman($k, [['UuidProduk' => $k['Gula']->Uuid, 'Jumlah' => '10']], ['UuidPengguna' => $gudang->Uuid]);

        expect(BantuanKasir::KirimRingkas($this, $k['TokenHp'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $audit = LogAudit::query()->where('Peristiwa', 'grosir.pesanan-salesman')->sole();

        expect(PesananGrosir::query()->sole()->Status)->toBe(StatusPesananGrosir::Draf)
            ->and(json_encode($audit->NilaiBaru))->toContain('IzinBerubah');
    });

    it('POS-05 SO cepat: kasir berizin jual menyusun pesanan grosir dari keranjang tanpa ditandai IzinBerubah', function (): void {
        /** @var TestCase $this */
        $k = SiapkanSalesman($this);
        $item = ItemPesananSalesman($k, [['UuidProduk' => $k['Gula']->Uuid, 'Jumlah' => '10']], ['UuidPengguna' => $k['Kasir']->Uuid]);

        expect(BantuanKasir::KirimRingkas($this, $k['TokenHp'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $audit = LogAudit::query()->where('Peristiwa', 'grosir.pesanan-salesman')->sole();

        expect(PesananGrosir::query()->sole()->Status)->toBe(StatusPesananGrosir::Draf)
            ->and(json_encode($audit->NilaiBaru))->not->toContain('IzinBerubah');
    });
});

describe('Outbox Kunjungan.Catat', function (): void {
    it('mencatat kunjungan dengan koordinat desimal & hasil, idempoten, dan menautkan pesanan dari batch yang sama', function (): void {
        /** @var TestCase $this */
        $k = SiapkanSalesman($this);
        $pesanan = ItemPesananSalesman($k, [['UuidProduk' => $k['Gula']->Uuid, 'Jumlah' => '50']]);
        $kunjungan = ItemKunjungan($k, ['UuidPesananGrosir' => $pesanan['Uuid']]);

        // Pesanan dulu, lalu kunjungan saat check-out (urutan FIFO outbox).
        expect(BantuanKasir::KirimRingkas($this, $k['TokenHp'], [$pesanan, $kunjungan]))->toBe([['Diterima', null], ['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(BantuanKasir::KirimRingkas($this, $k['TokenHp'], [$kunjungan]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        $catatan = KunjunganSales::query()->sole();
        expect($catatan->Uuid)->toBe(strtoupper($kunjungan['Uuid']))
            ->and($catatan->Latitude)->toBe('-7.5666001')
            ->and($catatan->Longitude)->toBe('110.8166002')
            ->and($catatan->AkurasiMeter)->toBe(12)
            ->and($catatan->Hasil)->toBe(HasilKunjungan::PesananDibuat)
            ->and($catatan->IdPengguna)->toBe($k['Salesman']->Id)
            ->and($catatan->IdPerangkat)->toBe($k['Hp']->Id)
            ->and($catatan->IdPesananGrosir)->toBe(PesananGrosir::query()->sole()->Id);
    });

    it('Uuid yang dipakai ulang dengan isi berbeda ditolak UuidSudahDipakai, bukan dianggap Duplikat', function (): void {
        /** @var TestCase $this */
        $k = SiapkanSalesman($this);
        $pesanan = ItemPesananSalesman($k, [['UuidProduk' => $k['Gula']->Uuid, 'Jumlah' => '50']]);
        $kunjungan = ItemKunjungan($k, ['Hasil' => 'TidakPesan']);

        expect(BantuanKasir::KirimRingkas($this, $k['TokenHp'], [$pesanan, $kunjungan]))->toBe([['Diterima', null], ['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        $pesananLain = ItemPesananSalesman($k, [['UuidProduk' => $k['Gula']->Uuid, 'Jumlah' => '50'], ['UuidProduk' => $k['Gula']->Uuid, 'Jumlah' => '5']], [], $pesanan['Uuid']);
        $kunjunganLain = ItemKunjungan($k, ['Hasil' => 'TokoTutup'], $kunjungan['Uuid']);

        expect(BantuanKasir::KirimRingkas($this, $k['TokenHp'], [$pesananLain, $kunjunganLain]))->toBe([
            ['Ditolak', 'UuidSudahDipakai'],
            ['Ditolak', 'UuidSudahDipakai'],
        ]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PesananGrosir::query()->count())->toBe(1)
            ->and(KunjunganSales::query()->sole()->Hasil)->toBe(HasilKunjungan::TidakPesan);
    });

    it('pesanan yang belum ada disimpan kosong lalu ditautkan saat pesanannya tiba belakangan', function (): void {
        /** @var TestCase $this */
        $k = SiapkanSalesman($this);
        $pesanan = ItemPesananSalesman($k, [['UuidProduk' => $k['Gula']->Uuid, 'Jumlah' => '50']]);
        $kunjungan = ItemKunjungan($k, ['UuidPesananGrosir' => $pesanan['Uuid'], 'Latitude' => null, 'Longitude' => null, 'AkurasiMeter' => null]);
        $pesanan['Data']['UuidKunjungan'] = $kunjungan['Uuid'];

        expect(BantuanKasir::KirimRingkas($this, $k['TokenHp'], [$kunjungan]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(KunjunganSales::query()->sole()->IdPesananGrosir)->toBeNull()
            ->and(KunjunganSales::query()->sole()->Latitude)->toBeNull();

        expect(BantuanKasir::KirimRingkas($this, $k['TokenHp'], [$pesanan]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(KunjunganSales::query()->sole()->IdPesananGrosir)->toBe(PesananGrosir::query()->sole()->Id);
    });

    it('koordinat di luar rentang, keluar sebelum masuk, dan waktu masa depan ditolak', function (): void {
        /** @var TestCase $this */
        $k = SiapkanSalesman($this);

        expect(BantuanKasir::KirimRingkas($this, $k['TokenHp'], [
            ItemKunjungan($k, ['Latitude' => '91.0000000']),
            ItemKunjungan($k, ['Longitude' => '-180.5']),
            ItemKunjungan($k, ['Latitude' => '-7.56', 'Longitude' => null]),
            ItemKunjungan($k, ['KeluarPada' => CarbonImmutable::now()->subHours(2)->utc()->toIso8601ZuluString()]),
            ItemKunjungan($k, ['MasukPada' => CarbonImmutable::now()->addHour()->utc()->toIso8601ZuluString(), 'KeluarPada' => null]),
            ItemKunjungan($k, ['Hasil' => 'Bolos']),
            ItemKunjungan($k, ['Latitude' => '90', 'Longitude' => '-180', 'Hasil' => 'TokoTutup', 'KeluarPada' => null]),
        ]))->toBe([
            ['Ditolak', 'DataTidakValid'],
            ['Ditolak', 'DataTidakValid'],
            ['Ditolak', 'DataTidakValid'],
            ['Ditolak', 'WaktuTidakValid'],
            ['Ditolak', 'WaktuTidakValid'],
            ['Ditolak', 'DataTidakValid'],
            ['Diterima', null],
        ]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $satu = KunjunganSales::query()->sole();
        expect($satu->Latitude)->toBe('90.0000000')
            ->and($satu->Longitude)->toBe('-180.0000000')
            ->and($satu->KeluarPada)->toBeNull();
    });

    it('isolasi tenant: pelanggan tenant lain tidak dikenal dan datanya tidak tersentuh', function (): void {
        /** @var TestCase $this */
        $a = SiapkanSalesman($this, 'Distributor Sembako Solo');
        $b = SiapkanSalesman($this, 'Distributor Sembako Klaten');

        expect(BantuanKasir::KirimRingkas($this, $a['TokenHp'], [
            ItemKunjungan($a, ['UuidPelanggan' => $b['Toko']->Uuid]),
            ItemPesananSalesman($a, [['UuidProduk' => $b['Gula']->Uuid, 'Jumlah' => '5']]),
            // Salesman tenant B lewat HP tenant A: bukan anggota usaha ini.
            ItemKunjungan($a, ['UuidPengguna' => $b['Salesman']->Uuid]),
        ]))->toBe([['Ditolak', 'PelangganTidakDikenal'], ['Ditolak', 'ProdukTidakDikenal'], ['Ditolak', 'KasirTidakDitemukan']]);

        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
        expect(KunjunganSales::query()->count())->toBe(0)->and(PesananGrosir::query()->count())->toBe(0);

        // API: piutang pelanggan tenant lain = 404.
        GetSalesman($this, $a, "pelanggan/{$b['Toko']->Uuid}/piutang")->assertNotFound();
    });
});

describe('API /api/pos/v1/salesman', function (): void {
    it('tanpa izin salesman.kunjungan atau tanpa X-Id-Kasir ditolak 403', function (): void {
        /** @var TestCase $this */
        $k = SiapkanSalesman($this);

        foreach (['pelanggan', 'stok', 'kunjungan', "pelanggan/{$k['Toko']->Uuid}/piutang"] as $alamat) {
            GetSalesman($this, $k, $alamat, $k['Kasir'])->assertForbidden()->assertJsonPath('Galat.Kode', 'TanpaIzin');
            $this->withToken($k['TokenHp'])->getJson("/api/pos/v1/salesman/{$alamat}")->assertForbidden();
        }

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PeranTenantBawaan::Salesman->AmbilIzin())->toContain(IzinTenant::SalesmanKunjungan)
            ->and(PeranTenantBawaan::Admin->AmbilIzin())->toContain(IzinTenant::SalesmanKunjungan)
            ->and(PeranTenantBawaan::Kasir->AmbilIzin())->not->toContain(IzinTenant::SalesmanKunjungan);
    });

    it('pelanggan: posisi kredit, piutang jatuh tempo, nomor HP & alamat (K30), kunjungan terakhir', function (): void {
        /** @var TestCase $this */
        $k = SiapkanSalesman($this);
        $tempo = fn (string $harga): array => BantuanPenjualan::Item(
            $k,
            ['Baris' => [['Produk' => $k['Gula'], 'Jumlah' => '10', 'Harga' => $harga]], 'Pembayaran' => [['Metode' => $k['Tempo'], 'Jumlah' => (string) BigDecimal::of($harga)->multipliedBy(10)->toScale(2)]]],
            ['UuidPelanggan' => $k['Toko']->Uuid],
        );
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tempo('15000.00'), $tempo('15000.00')]))->toBe([['Diterima', null], ['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        // Satu nota sudah lewat jatuh tempo 5 hari.
        $lama = Piutang::query()->orderBy('Id')->firstOrFail();
        Piutang::query()->whereKey($lama->Id)->update(['JatuhTempo' => CarbonImmutable::now('Asia/Jakarta')->subDays(5)->toDateString()]);
        expect(BantuanKasir::KirimRingkas($this, $k['TokenHp'], [ItemKunjungan($k, ['Hasil' => 'TidakPesan'])]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        Pelanggan::query()->create(['Nama' => 'Warung Bu Sri Rejeki', 'NoHp' => '6281399990002']);

        $respons = GetSalesman($this, $k, 'pelanggan?kata=makmur')->assertOk()
            ->assertJsonCount(1, 'Pelanggan')
            ->assertJsonPath('Halaman', 1)
            ->assertJsonPath('AdaBerikutnya', false);
        $p = $respons->json('Pelanggan.0');

        expect($p['Uuid'])->toBe($k['Toko']->Uuid)
            // K30 (v3.86): salesman perlu menghubungi & mendatangi toko, jadi nomor penuh + alamat dikirim.
            ->and($p['NoHp'])->toBe('6281355550001')
            ->and($p['Alamat'])->toBe('Jl. Slamet Riyadi No. 212, Purwosari, Laweyan, Surakarta')
            ->and($p['LimitKredit'])->toBe('25000000.00')
            ->and($p['SisaPiutang'])->toBe('300000.00')
            ->and($p['JumlahPiutangJatuhTempo'])->toBe('150000.00')
            ->and($p['HariLewatJatuhTempo'])->toBe(5)
            ->and($p['TerakhirDikunjungiPada'])->toBe(KunjunganSales::query()->sole()->MasukPada->utc()->toIso8601ZuluString());

        GetSalesman($this, $k, 'pelanggan')->assertOk()->assertJsonCount(2, 'Pelanggan')
            ->assertJsonPath('Pelanggan.1.TerakhirDikunjungiPada', null)
            ->assertJsonPath('Pelanggan.1.SisaPiutang', '0.00');

        $piutang = GetSalesman($this, $k, "pelanggan/{$k['Toko']->Uuid}/piutang")->assertOk()->assertJsonCount(2, 'Piutang')->json('Piutang');
        expect($piutang[0]['Nomor'])->toBe($lama->Nomor)
            ->and($piutang[0]['Sisa'])->toBe('150000.00')
            ->and($piutang[0]['UmurHari'])->toBe(5)
            ->and($piutang[1]['UmurHari'])->toBeLessThan(0);
    });

    it('stok lokasi Toko outlet sebagai string desimal, dan kunjungan harian milik salesman sendiri', function (): void {
        /** @var TestCase $this */
        $k = SiapkanSalesman($this);
        expect(BantuanKasir::KirimRingkas($this, $k['TokenHp'], [ItemKunjungan($k)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $lain = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Salesman);

        GetSalesman($this, $k, 'stok')->assertOk()
            ->assertJsonFragment(['UuidProduk' => $k['Gula']->Uuid, 'JumlahTersedia' => '500.0000']);

        $hariIni = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        GetSalesman($this, $k, 'kunjungan')->assertOk()
            ->assertJsonPath('Tanggal', $hariIni)
            ->assertJsonCount(1, 'Kunjungan')
            ->assertJsonPath('Kunjungan.0.Hasil', 'PesananDibuat')
            ->assertJsonPath('Kunjungan.0.Latitude', '-7.5666001')
            ->assertJsonPath('Kunjungan.0.NamaPelanggan', 'Toko Kelontong Makmur Jaya Abadi');
        GetSalesman($this, $k, 'kunjungan?tanggal=2020-01-01')->assertOk()->assertJsonCount(0, 'Kunjungan');
        GetSalesman($this, $k, 'kunjungan', $lain)->assertOk()->assertJsonCount(0, 'Kunjungan');
        GetSalesman($this, $k, 'kunjungan?tanggal=kemarin')->assertUnprocessable();
    });
});

describe('Back-office Grosir › Kunjungan', function (): void {
    it('menampilkan kunjungan dengan filter salesman, hasil, tanggal; ekspor CSV; detail pesanan menyebut salesman', function (): void {
        /** @var TestCase $this */
        $k = SiapkanSalesman($this);
        $pesanan = ItemPesananSalesman($k, [['UuidProduk' => $k['Gula']->Uuid, 'Jumlah' => '50']]);
        expect(BantuanKasir::KirimRingkas($this, $k['TokenHp'], [
            $pesanan,
            ItemKunjungan($k, ['UuidPesananGrosir' => $pesanan['Uuid']]),
            ItemKunjungan($k, ['Hasil' => 'TokoTutup', 'Latitude' => null, 'Longitude' => null, 'AkurasiMeter' => null, 'Catatan' => null]),
        ]))->toBe([['Diterima', null], ['Diterima', null], ['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->get('/kelola/grosir/kunjungan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/Kunjungan/Daftar')
            ->has('Kunjungan.Data', 2)
            ->has('OpsiHasil', 4)
            ->has('OpsiSalesman', 1)
            ->where('OpsiSalesman.0.Nilai', $k['Salesman']->Uuid));

        $this->getJson('/kelola/grosir/kunjungan?saring[Hasil]=TokoTutup')->assertOk()
            ->assertJsonCount(1, 'Data')
            ->assertJsonPath('Data.0.Latitude', null)
            ->assertJsonPath('Data.0.LabelHasil', 'Toko tutup');
        $baris = $this->getJson('/kelola/grosir/kunjungan?saring[Hasil]=PesananDibuat&saring[Salesman]='.$k['Salesman']->Uuid)->assertOk()
            ->assertJsonCount(1, 'Data')->json('Data.0');
        expect($baris['DurasiMenit'])->toBe(25)
            ->and($baris['NomorPesananGrosir'])->toStartWith('PG/')
            ->and($baris['Longitude'])->toBe('110.8166002');
        $this->getJson('/kelola/grosir/kunjungan?saring[Salesman]='.BantuanKasir::Uuid())->assertOk()->assertJsonCount(0, 'Data');
        $this->getJson('/kelola/grosir/kunjungan?saring[Tanggal]=2020-01-01..2020-01-31')->assertOk()->assertJsonCount(0, 'Data');
        $this->getJson('/kelola/grosir/kunjungan?cari=Makmur')->assertOk()->assertJsonCount(2, 'Data');

        $csv = $this->get('/kelola/grosir/kunjungan/ekspor?saring[Hasil]=PesananDibuat')->assertOk()->streamedContent();
        expect($csv)->toContain('Toko Kelontong Makmur Jaya Abadi')->toContain('-7.5666001')->not->toContain('Toko tutup');

        $this->get('/kelola/grosir/pesanan/'.strtoupper($pesanan['Uuid']))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Pesanan.Sumber', 'Salesman')
            ->where('Pesanan.NamaSalesman', $k['Salesman']->Nama)
            ->where('Tindakan.Konfirmasi', true));
    });

    it('tanpa izin grosir.kelola ditolak; kunjungan tenant lain tidak terlihat', function (): void {
        /** @var TestCase $this */
        $a = SiapkanSalesman($this, 'Distributor Sembako Solo');
        expect(BantuanKasir::KirimRingkas($this, $a['TokenHp'], [ItemKunjungan($a)]))->toBe([['Diterima', null]]);
        $b = SiapkanSalesman($this, 'Distributor Sembako Klaten');

        BantuanOrganisasi::Masuk($this, $b['Kasir'], $b['Tenant']->Id);
        $this->get('/kelola/grosir/kunjungan')->assertForbidden();
        $this->get('/kelola/grosir/kunjungan/ekspor')->assertForbidden();

        BantuanOrganisasi::Masuk($this, $b['Pemilik'], $b['Tenant']->Id);
        $this->getJson('/kelola/grosir/kunjungan')->assertOk()->assertJsonCount(0, 'Data');
    });
});
