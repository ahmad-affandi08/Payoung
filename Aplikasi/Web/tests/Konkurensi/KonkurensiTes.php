<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Pemenuhan\Model\Reservasi;
use App\Domain\Penjualan\Aksi\SimpanPesananGrosir;
use App\Domain\Penjualan\Data\DataBarisPesananGrosir;
use App\Domain\Penjualan\Data\DataPesananGrosir;
use App\Domain\Penjualan\Enum\StatusPesananGrosir;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PesananGrosir;
use App\Domain\Persediaan\Model\BahanTerbuang;
use App\Domain\Persediaan\Model\MutasiStok;
use Carbon\CarbonImmutable;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan as B;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Audit F-07: konkurensi nyata dengan DUA proses PHP (dua koneksi MySQL, kunci cache store database) yang mulai pada
 * mikrodetik yang sama. Data disiapkan & di-commit (DatabaseTruncation, lihat tests/Pest.php), lalu invarian diperiksa
 * setelah keduanya selesai: idempotensi item yang sama, stok tidak minus di back-office, slot staf tidak ganda.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Jalankan skenario di dua proses bersamaan dengan data masing-masing; kembalikan hasil JSON keduanya.
 *
 * @param  array{0: array<string, mixed>, 1: array<string, mixed>}  $data
 * @return list<array<string, mixed>>
 */
function JalankanBersamaan(string $skenario, array $data): array
{
    $mulai = microtime(true) + 3.0;
    $berkas = [];

    foreach ($data as $i => $isi) {
        $berkas[$i] = tempnam(sys_get_temp_dir(), 'konkurensi');
        file_put_contents($berkas[$i], json_encode($isi, JSON_THROW_ON_ERROR));
    }

    $koneksi = config('database.connections.mysql');
    $lingkungan = [
        'APP_ENV' => 'testing',
        'CACHE_STORE' => 'database',
        'QUEUE_CONNECTION' => 'sync',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => (string) $koneksi['host'],
        'DB_PORT' => (string) $koneksi['port'],
        'DB_DATABASE' => (string) $koneksi['database'],
        'DB_USERNAME' => (string) $koneksi['username'],
        'DB_PASSWORD' => (string) $koneksi['password'],
    ];
    $hasil = Process::concurrently(function (Pool $pool) use ($skenario, $mulai, $berkas, $lingkungan): void {
        foreach ($berkas as $b) {
            $pool->path(base_path())->env($lingkungan)->timeout(120)
                ->command([PHP_BINARY, 'tests/Konkurensi/PekerjaKonkurensi.php', $skenario, sprintf('%.6F', $mulai), $b]);
        }
    });

    array_map('unlink', $berkas);

    return array_map(function ($proses): array {
        expect($proses->successful())->toBeTrue($proses->errorOutput());

        $teks = $proses->output();
        expect(json_validate($teks))->toBeTrue("Keluaran pekerja: {$teks}");

        return json_decode($teks, true, flags: JSON_THROW_ON_ERROR);
    }, array_values($hasil->collect()->all()));
}

it('item outbox penjualan yang sama dikirim dua perangkat/proses bersamaan: tercatat sekali, stok & jurnal tidak ganda', function (): void {
    $k = BantuanPenjualan::Siapkan($this, 'Toko Serentak Solo');
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']]]);
    $isi = ['IdTenant' => $k['Tenant']->Id, 'IdPerangkat' => $k['Perangkat']->Id, 'IdOutlet' => $k['Outlet']->Id, 'Item' => [$item]];

    $hasil = JalankanBersamaan('sinkron', [$isi, $isi]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $status = collect($hasil)->pluck('Hasil')->flatten()->sort()->values()->all();
    expect($status)->toBe(['Diterima', 'Duplikat'])
        ->and(Penjualan::query()->where('Uuid', $item['Uuid'])->count())->toBe(1)
        ->and(MutasiStok::query()->where('JenisReferensi', 'Penjualan')->count())->toBe(1)
        ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
});

it('dua pencatatan bahan terbuang bersamaan melebihi stok: satu diterima, satu StokTidakCukup. Stok tidak minus', function (): void {
    $t = BantuanPersediaan::SiapkanTenant('Kafe Serentak Klaten');
    $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
    BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '100', '1000')], $t['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(2)->format('Y-m-d'));
    $isi = fn (): array => ['IdTenant' => $t['Tenant']->Id, 'IdOutlet' => $t['Outlet']->Id, 'IdGudang' => $t['Gudang']->Id, 'IdProduk' => $p['Stok']->Id, 'Jumlah' => '60', 'IdPengguna' => $t['Pemilik']->Id, 'Uuid' => strtoupper((string) Str::ulid())];

    $hasil = JalankanBersamaan('bahan-terbuang', [$isi(), $isi()]);

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(collect($hasil)->pluck('Galat')->filter()->values()->all())->toBe(['StokTidakCukup'])
        ->and(BahanTerbuang::query()->count())->toBe(1)
        ->and(B::Saldo($p['Stok'], $t['Gudang']))->toBe(['40.0000', '40000.00'])
        ->and(Kuantitas::Dari(B::Saldo($p['Stok'], $t['Gudang'])[0])->KeDesimal()->isNegative())->toBeFalse()
        ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
});

it('dua reservasi bersamaan pada staf & jam yang sama: hanya satu yang mendapat slot', function (): void {
    $t = BantuanPersediaan::SiapkanTenant('Salon Serentak Solo');
    $layanan = BantuanKatalog::BuatProduk(['Nama' => 'Potong Rambut & Styling Premium', 'Jenis' => JenisProduk::Jasa, 'DurasiMenit' => 45, 'TampilOnline' => true], '75000.00');
    $maya = Karyawan::query()->create(['Nama' => 'Maya Senior Stylist']);
    $besok = CarbonImmutable::now('Asia/Jakarta')->addDays(2)->format('Y-m-d');
    JadwalKerja::query()->create(['IdKaryawan' => $maya->Id, 'IdOutlet' => $t['Outlet']->Id, 'Tanggal' => $besok, 'JamMulai' => '09:00', 'JamSelesai' => '12:00']);
    $isi = fn (string $nama, string $hp): array => ['IdTenant' => $t['Tenant']->Id, 'IdOutlet' => $t['Outlet']->Id, 'UuidLayanan' => $layanan->Uuid, 'Tanggal' => $besok, 'Jam' => '10:00', 'UuidStaf' => $maya->Uuid, 'Nama' => $nama, 'NoHp' => $hp, 'IdPengguna' => $t['Pemilik']->Id];

    $hasil = JalankanBersamaan('reservasi', [$isi('Rina Wulandari', '081234567890'), $isi('Sari Lestari', '081298765432')]);

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(collect($hasil)->pluck('Galat')->filter()->values()->all())->toBe(['SlotTidakTersedia'])
        ->and(Reservasi::query()->where('IdKaryawan', $maya->Id)->count())->toBe(1);
});

it('dua penjualan tempo bersamaan untuk pelanggan yang sama: limit kredit hanya muat satu, yang lain menjadi tinjauan (BR-12.1)', function (): void {
    $k = BantuanPenjualan::Siapkan($this, 'Grosir Serentak Kediri');
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $toko = Pelanggan::query()->create(['Nama' => 'Toko Makmur Serentak', 'NoHp' => '6281355550077', 'LimitKredit' => '100000', 'TerminHari' => 14]);
    $isi = function () use ($k, $minyak, $toko): array {
        $item = BantuanPenjualan::Item(
            $k,
            ['Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']], 'Pembayaran' => [['Metode' => $k['Tempo'], 'Jumlah' => '77000.00']]],
            ['UuidPelanggan' => $toko->Uuid],
        );

        return ['IdTenant' => $k['Tenant']->Id, 'IdPerangkat' => $k['Perangkat']->Id, 'IdOutlet' => $k['Outlet']->Id, 'Item' => [$item]];
    };

    $hasil = JalankanBersamaan('sinkron', [$isi(), $isi()]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(collect($hasil)->pluck('Hasil')->flatten()->all())->toBe(['Diterima', 'Diterima'])
        ->and(Piutang::query()->count())->toBe(2)
        ->and(Penjualan::query()->where('PerluTinjauan', false)->count())->toBe(1)
        ->and(Penjualan::query()->where('PerluTinjauan', true)->where('AlasanTinjauan', 'like', '%TempoBermasalah%')->count())->toBe(1);
});

it('dua konfirmasi SO grosir bersamaan untuk pelanggan yang sama: paparan kredit menghitung SO yang lain, hanya satu yang lolos limit (BR-12.6)', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk('Grosir Serentak Madiun');
    $gula = BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir Kemasan 1 kg', 'IdKelompokPajak' => $t['KelompokPajak']->Id], '15000.00', $t['Pcs']);
    $satuan = BantuanHarga::SatuanDasar($gula);
    $toko = Pelanggan::query()->create(['Nama' => 'Toko Makmur Madiun', 'NoHp' => '6281355550088', 'LimitKredit' => '5000000', 'TerminHari' => 30]);
    // 200 x 15.000 = 3.000.000: satu SO muat di limit 5.000.000, dua SO tidak.
    $buat = fn (): string => app(SimpanPesananGrosir::class)->Jalankan(new DataPesananGrosir(
        uuidPelanggan: $toko->Uuid,
        idOutlet: $t['Outlet']->Id,
        tanggal: CarbonImmutable::now('Asia/Jakarta')->startOfDay(),
        baris: [new DataBarisPesananGrosir($gula->Uuid, $satuan->Uuid, Kuantitas::Dari('200'), Uang::Dari('0'))],
    ), $t['Pemilik']->Id)->Uuid;
    $isi = fn (string $uuid): array => ['IdTenant' => $t['Tenant']->Id, 'UuidPesanan' => $uuid, 'IdPengguna' => $t['Pemilik']->Id];

    $hasil = JalankanBersamaan('konfirmasi-so', [$isi($buat()), $isi($buat())]);

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(collect($hasil)->pluck('Galat')->filter()->values()->all())->toBe(['ButuhPersetujuanKredit'])
        ->and(PesananGrosir::query()->where('Status', StatusPesananGrosir::Dikonfirmasi->value)->count())->toBe(1)
        ->and(PesananGrosir::query()->where('Status', StatusPesananGrosir::Draf->value)->count())->toBe(1);
});
