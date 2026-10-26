<?php

declare(strict_types=1);

use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Kueri\KartuStok;
use App\Domain\Tenant\Model\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanLaporan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Riwayat minyak goreng di lokasi toko (rata-rata bergerak): 1/9 stok awal +24 @38500, 5/9 jual −3, 10/9 terima +12
 * senilai 474000, 20/9 jual −5, 2/10 jual −1. Satu mutasi beras di lokasi yang sama sebagai pengganggu.
 *
 * @return array{Tenant: Tenant, Pemilik: Pengguna, Outlet: Outlet, Gudang: Gudang, Minyak: Produk, UuidStokAwal: string}
 */
function SiapkanKartuStokUji(): array
{
    $t = BantuanPersediaan::SiapkanTenant('Toko Sembako Berkah Jaya');
    $minyak = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter', 'Sku' => 'MGS-2L'], '38500.00', $t['Pcs']);
    $beras = BantuanKatalog::BuatProduk(['Nama' => 'Beras Pandan Wangi Cianjur Premium 5 kg'], '82000.00', $t['Pcs']);
    $uuidStokAwal = (string) Str::ulid();
    $jual = ['jenis' => JenisMutasi::Penjualan, 'referensi' => JenisReferensiMutasi::Penjualan];

    BantuanLaporan::CatatMutasi($minyak, $t['Gudang'], '24.0000', '924000.00', '2026-09-01', timpa: [
        'NomorReferensi' => 'SA/2026/09/0001', 'UuidReferensi' => $uuidStokAwal, 'DibuatOleh' => $t['Pemilik']->Id,
    ]);
    BantuanLaporan::CatatMutasi($beras, $t['Gudang'], '10.0000', '750000.00', '2026-09-03');
    BantuanLaporan::CatatMutasi($minyak, $t['Gudang'], '-3.0000', '-115500.00', '2026-09-05', ...$jual, timpa: ['NomorReferensi' => 'PJ/TKO/0001']);
    BantuanLaporan::CatatMutasi($minyak, $t['Gudang'], '12.0000', '474000.00', '2026-09-10', JenisMutasi::PenerimaanPembelian, JenisReferensiMutasi::PenerimaanBarang, ['NomorReferensi' => 'GRN/2026/09/0001']);
    BantuanLaporan::CatatMutasi($minyak, $t['Gudang'], '-5.0000', '-194318.18', '2026-09-20', ...$jual);
    BantuanLaporan::CatatMutasi($minyak, $t['Gudang'], '-1.0000', '-38863.64', '2026-10-02', ...$jual);

    return ['Tenant' => $t['Tenant'], 'Pemilik' => $t['Pemilik'], 'Outlet' => $t['Outlet'], 'Gudang' => $t['Gudang'], 'Minyak' => $minyak, 'UuidStokAwal' => $uuidStokAwal];
}

/**
 * @return array{SaldoAwal: array{Jumlah: string, Nilai: string}, SaldoAkhir: array{Jumlah: string, Nilai: string}, Mutasi: array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}}
 */
function AmbilKartuStokUji(Produk $produk, Gudang $gudang, string $dari, string $sampai, int $halaman = 1, int $perHalaman = 100): array
{
    return app(KartuStok::class)->Ambil($produk->Id, $gudang->Id, CarbonImmutable::parse($dari), CarbonImmutable::parse($sampai), $halaman, $perHalaman);
}

describe('F-05a kartu stok (BR-05.1, DesainF05a C.8, H-5)', function (): void {
    it('BR-05.1 baris dalam rentang urut Id dengan saldo berjalan. Saldo awal = baris terakhir sebelum rentang. Saldo akhir = baris terakhir rentang', function (): void {
        $d = SiapkanKartuStokUji();
        $kartu = AmbilKartuStokUji($d['Minyak'], $d['Gudang'], '2026-09-05', '2026-09-30');

        expect($kartu['SaldoAwal'])->toBe(['Jumlah' => '24.0000', 'Nilai' => '924000.00'])
            ->and($kartu['SaldoAkhir'])->toBe(['Jumlah' => '28.0000', 'Nilai' => '1088181.82'])
            ->and($kartu['Mutasi']['Meta']['Total'])->toBe(3)
            ->and(array_map(fn (array $b): array => [$b['TanggalBisnis'], $b['JenisMutasi'], $b['Masuk'], $b['Keluar'], $b['TotalHpp'], $b['SaldoSetelah'], $b['NilaiSetelah']], $kartu['Mutasi']['Data']))->toBe([
                ['2026-09-05', 'Penjualan', null, '3.0000', '-115500.00', '21.0000', '808500.00'],
                ['2026-09-10', 'PenerimaanPembelian', '12.0000', null, '474000.00', '33.0000', '1282500.00'],
                ['2026-09-20', 'Penjualan', null, '5.0000', '-194318.18', '28.0000', '1088181.82'],
            ])
            ->and($kartu['Mutasi']['Data'][1])->toMatchArray([
                'LabelJenisMutasi' => JenisMutasi::PenerimaanPembelian->AmbilLabel(),
                'NomorReferensi' => 'GRN/2026/09/0001',
                'TautanReferensi' => null,
                'HppSatuan' => '39500.000000',
                'NomorBatch' => null,
                'NomorSeri' => null,
                'DicatatOleh' => null,
            ])
            ->and(PemeriksaInvarian::PeriksaRantaiMutasi($d['Tenant']->Id))->toBe([])
            ->and(PemeriksaInvarian::PeriksaSaldoStok($d['Tenant']->Id))->toBe([]);
    });

    it('baris stok awal bertautan ke dokumen sumber, mencatat pelaku, dan waktu pencatatan ISO', function (): void {
        $d = SiapkanKartuStokUji();
        $baris = AmbilKartuStokUji($d['Minyak'], $d['Gudang'], '2026-09-01', '2026-09-01')['Mutasi']['Data'][0];

        expect($baris)->toMatchArray([
            'JenisMutasi' => 'StokAwal',
            'NomorReferensi' => 'SA/2026/09/0001',
            'TautanReferensi' => '/kelola/persediaan/stok-awal/'.$d['UuidStokAwal'],
            'Masuk' => '24.0000',
            'Keluar' => null,
            'DicatatOleh' => $d['Pemilik']->Nama,
        ])->and($baris['DicatatPada'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
    });

    it('rentang tanpa mutasi: saldo awal = saldo akhir = baris terakhir sebelum rentang. Sebelum riwayat = nol', function (): void {
        $d = SiapkanKartuStokUji();
        $november = AmbilKartuStokUji($d['Minyak'], $d['Gudang'], '2026-11-01', '2026-11-30');
        $agustus = AmbilKartuStokUji($d['Minyak'], $d['Gudang'], '2026-08-01', '2026-08-31');
        $oktober = AmbilKartuStokUji($d['Minyak'], $d['Gudang'], '2026-10-01', '2026-10-31');

        expect($november['SaldoAwal'])->toBe(['Jumlah' => '27.0000', 'Nilai' => '1049318.18'])
            ->and($november['SaldoAkhir'])->toBe($november['SaldoAwal'])
            ->and($november['Mutasi']['Meta']['Total'])->toBe(0)
            ->and($agustus['SaldoAwal'])->toBe(['Jumlah' => '0.0000', 'Nilai' => '0.00'])
            ->and($agustus['SaldoAkhir'])->toBe(['Jumlah' => '0.0000', 'Nilai' => '0.00'])
            ->and($oktober['SaldoAwal'])->toBe(['Jumlah' => '28.0000', 'Nilai' => '1088181.82'])
            ->and($oktober['SaldoAkhir'])->toBe(['Jumlah' => '27.0000', 'Nilai' => '1049318.18']);
    });

    it('berhalaman sesuai ukuran halaman tabel (D-16)', function (): void {
        $d = SiapkanKartuStokUji();
        $h2 = AmbilKartuStokUji($d['Minyak'], $d['Gudang'], '2026-09-01', '2026-10-31', 2, 2);

        expect([$h2['Mutasi']['Meta']['Halaman'], $h2['Mutasi']['Meta']['JumlahHalaman'], $h2['Mutasi']['Meta']['Total']])->toBe([2, 3, 5])
            ->and(array_column($h2['Mutasi']['Data'], 'TanggalBisnis'))->toBe(['2026-09-10', '2026-09-20'])
            ->and($h2['SaldoAwal'])->toBe(['Jumlah' => '0.0000', 'Nilai' => '0.00']);
    });

    it('HTTP: props PropsKartuStok dengan produk & lokasi terpilih. Tanggal bawaan awal bulan s.d. hari ini. Dari > sampai ditukar', function (): void {
        $d = SiapkanKartuStokUji();
        $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00:00', 'Asia/Jakarta'));
        $masuk = fn () => BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);

        $masuk()->get("/kelola/persediaan/kartu-stok?produk={$d['Minyak']->Uuid}&gudang={$d['Gudang']->Uuid}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/Persediaan/KartuStok')
                ->where('Produk', ['Uuid' => $d['Minyak']->Uuid, 'Nama' => $d['Minyak']->Nama, 'Sku' => 'MGS-2L', 'SimbolSatuan' => 'pcs', 'Pelacakan' => 'Tidak'])
                ->where('Gudang.Uuid', $d['Gudang']->Uuid)
                ->where('Saring', ['UuidProduk' => $d['Minyak']->Uuid, 'UuidGudang' => $d['Gudang']->Uuid, 'Dari' => '2026-09-01', 'Sampai' => '2026-09-24'])
                ->where('SaldoAwal', ['Jumlah' => '0.0000', 'Nilai' => '0.00'])
                ->where('SaldoAkhir', ['Jumlah' => '28.0000', 'Nilai' => '1088181.82'])
                ->where('Mutasi.Meta.Total', 4)
                ->has('OpsiGudang', 1));

        $masuk()->get("/kelola/persediaan/kartu-stok?produk={$d['Minyak']->Uuid}&gudang={$d['Gudang']->Uuid}&dari=2026-10-31&sampai=2026-10-01")
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('Saring.Dari', '2026-10-01')
                ->where('Saring.Sampai', '2026-10-31')
                ->where('Mutasi.Meta.Total', 1));

        // TabelData (D-16): mutasi berikutnya dari URL yang sama sebagai JSON, parameter produk/gudang/periode ikut.
        $masuk()->getJson("/kelola/persediaan/kartu-stok?produk={$d['Minyak']->Uuid}&gudang={$d['Gudang']->Uuid}&perHalaman=25&halaman=1")
            ->assertOk()
            ->assertJsonPath('Meta.Total', 4)
            ->assertJsonPath('Meta.PerHalaman', 25)
            ->assertJsonCount(4, 'Data');
        $masuk()->getJson('/kelola/persediaan/kartu-stok')->assertOk()->assertJsonPath('Meta.Total', 0);

        $masuk()->get('/kelola/persediaan/kartu-stok?dari=bukan-tanggal&sampai=2026-02-31')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('Produk', null)
                ->where('Gudang', null)
                ->where('Mutasi', null)
                ->where('SaldoAwal', null)
                ->where('SaldoAkhir', null)
                ->where('Saring.Dari', '2026-09-01')
                ->where('Saring.Sampai', '2026-09-24'));
    });

    it('HTTP isolasi: produk atau lokasi tenant lain = 404. Lokasi di outlet di luar akses = 404; Kasir 403', function (): void {
        $d = SiapkanKartuStokUji();
        $b = BantuanPersediaan::SiapkanTenant('Toko Kelontong Maju Mundur');
        $produkB = BantuanKatalog::BuatProduk(['Nama' => 'Kecap Manis Bango 520 ml'], '24000.00', $b['Pcs']);

        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id)->get("/kelola/persediaan/kartu-stok?produk={$produkB->Uuid}&gudang={$d['Gudang']->Uuid}")->assertNotFound();
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id)->get("/kelola/persediaan/kartu-stok?produk={$d['Minyak']->Uuid}&gudang={$b['Gudang']->Uuid}")->assertNotFound();

        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        $cabang = BantuanHarga::BuatOutlet('SLO', 'Cabang Solo Baru');
        $staf = BantuanHarga::TambahAnggotaOutlet($d['Tenant']->Id, PeranTenantBawaan::StafGudang, $cabang);
        BantuanOrganisasi::Masuk($this, $staf, $d['Tenant']->Id)
            ->get("/kelola/persediaan/kartu-stok?produk={$d['Minyak']->Uuid}&gudang={$d['Gudang']->Uuid}")
            ->assertNotFound();

        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id, PeranTenantBawaan::Kasir)->get('/kelola/persediaan/kartu-stok')->assertForbidden();
    });
});
