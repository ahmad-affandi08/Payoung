<?php

declare(strict_types=1);

use App\Domain\Bengkel\Enum\StatusPerintahKerja;
use App\Domain\Bengkel\Model\Kendaraan;
use App\Domain\Bengkel\Model\PerintahKerja;
use App\Domain\Bengkel\Model\PerintahKerjaDetail;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\Komisi;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\TierPelanggan;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\NomorSeri;
use App\Domain\Persediaan\Model\SaldoStok;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * Sektor Bengkel bagian 1 (§9.10, SLS-08, K-27, keputusan K25): kendaraan pelanggan, perintah kerja (harga dari price
 * engine server), persetujuan pelanggan lewat tautan WhatsApp (boleh sebagian), status pengerjaan, penagihan lewat
 * `Penjualan.Buat` `UuidPerintahKerja` (stok sparepart baru berkurang saat ditagih; mekanik jadi staf baris → komisi),
 * void mengembalikan perintah kerja, riwayat servis, pengingat servis berkala, Kotak Tindakan, izin & isolasi tenant.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 09:30', 'Asia/Jakarta'));
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['8001']])]);
});

/**
 * Bengkel uji: dua jasa (servis ringan Rp 50.000, ganti oli Rp 10.000), dua sparepart berstok (oli 10 botol HPP
 * Rp 40.000 jual Rp 55.000; busi 5 pcs HPP Rp 60.000 jual Rp 85.000), satu mekanik, satu pelanggan.
 *
 * @return array<string, mixed>
 */
function SiapkanBengkel(TestCase $tes, string $nama = 'Bengkel Jaya Motor Solo'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $nama);

    return $k + [
        'Servis' => BantuanKatalog::BuatProduk(['Nama' => 'Servis Ringan Motor Matik (Tune Up)', 'Jenis' => JenisProduk::Jasa], '50000.00'),
        'GantiOli' => BantuanKatalog::BuatProduk(['Nama' => 'Jasa Ganti Oli', 'Jenis' => JenisProduk::Jasa], '10000.00'),
        'Oli' => BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Oli Mesin Motor 10W-30 1 Liter', '10', '40000', '55000.00'),
        'Busi' => BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Busi Motor Iridium', '5', '60000', '85000.00'),
        'Mekanik' => Karyawan::query()->create(['Nama' => 'Joko Susilo Mekanik Senior']),
        'Pelanggan' => Pelanggan::query()->create(['Nama' => 'Budi Santoso Wicaksono', 'NoHp' => '6281234567890']),
    ];
}

/** @param array<string, mixed> $k */
function MasukBengkel(TestCase $tes, array $k): TestCase
{
    return BantuanOrganisasi::Masuk($tes, $k['Pemilik'], $k['Tenant']->Id);
}

/**
 * Kendaraan pelanggan lewat back-office.
 *
 * @param  array<string, mixed>  $k
 */
function BuatKendaraanUji(TestCase $tes, array $k, string $plat = 'ad1234xy'): Kendaraan
{
    MasukBengkel($tes, $k)->post('/kelola/bengkel/kendaraan', [
        'UuidPelanggan' => $k['Pelanggan']->Uuid,
        'NomorPolisi' => $plat,
        'Merek' => 'Honda',
        'Tipe' => 'Vario 125',
        'Tahun' => 2021,
        'Warna' => 'Hitam',
        'KmTerakhir' => 15000,
    ])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return Kendaraan::query()->orderByDesc('Id')->firstOrFail();
}

/**
 * Perintah kerja berisi servis (mekanik), ganti oli (tanpa mekanik), oli, dan busi.
 *
 * @param  array<string, mixed>  $k
 * @param  array<string, mixed>  $timpa
 */
function BuatPerintahKerjaUji(TestCase $tes, array $k, Kendaraan $kendaraan, array $timpa = []): PerintahKerja
{
    MasukBengkel($tes, $k)->post('/kelola/bengkel/perintah-kerja', $timpa + [
        'UuidOutlet' => $k['Outlet']->Uuid,
        'UuidPelanggan' => $k['Pelanggan']->Uuid,
        'UuidKendaraan' => $kendaraan->Uuid,
        'KmMasuk' => 18250,
        'Keluhan' => 'Tarikan berat, rem depan bunyi, sudah lewat jadwal ganti oli',
        'Diagnosis' => 'Busi aus, oli hitam pekat',
        'EstimasiSelesaiPada' => '2026-10-13T15:00',
        'Baris' => [
            ['Jenis' => 'Jasa', 'UuidProduk' => $k['Servis']->Uuid, 'Jumlah' => '1', 'UuidKaryawan' => $k['Mekanik']->Uuid],
            ['Jenis' => 'Jasa', 'UuidProduk' => $k['GantiOli']->Uuid, 'Jumlah' => '1'],
            // Harga dari klien diabaikan: server memakai price engine.
            ['Jenis' => 'Sparepart', 'UuidProduk' => $k['Oli']->Uuid, 'Jumlah' => '1', 'HargaSatuan' => '1.00'],
            ['Jenis' => 'Sparepart', 'UuidProduk' => $k['Busi']->Uuid, 'Jumlah' => '1'],
        ],
    ])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return PerintahKerja::query()->orderByDesc('Id')->firstOrFail();
}

/** @param array<string, mixed> $k */
function StokProduk(array $k, Produk $produk): string
{
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return (string) SaldoStok::query()->where('IdProduk', $produk->Id)->where('IdGudang', $k['Gudang']->Id)->value('JumlahTersedia');
}

/**
 * Tautan persetujuan dari halaman detail back-office.
 *
 * @param  array<string, mixed>  $k
 */
function AmbilTautanPersetujuan(TestCase $tes, array $k, PerintahKerja $pk): string
{
    $tautan = null;
    MasukBengkel($tes, $k)->get("/kelola/bengkel/perintah-kerja/{$pk->Uuid}")->assertOk()
        ->assertInertia(function (AssertableInertia $h) use (&$tautan): AssertableInertia {
            $tautan = $h->toArray()['props']['PerintahKerja']['Persetujuan']['Tautan'];

            return $h->component('Kelola/Bengkel/PerintahKerja/Detail');
        });
    expect($tautan)->toBeString();

    return (string) parse_url((string) $tautan, PHP_URL_PATH);
}

it('alur penuh: kendaraan → perintah kerja berharga server → persetujuan sebagian lewat tautan → tagih di kasir (stok & komisi) → void', function (): void {
    $k = SiapkanBengkel($this);
    $kendaraan = BuatKendaraanUji($this, $k);
    expect($kendaraan->NomorPolisi)->toBe('AD 1234 XY')->and($kendaraan->KmTerakhir)->toBe(15000);

    // Nomor polisi unik per tenant di antara kendaraan aktif (beda spasi/huruf tetap dianggap sama).
    MasukBengkel($this, $k)->post('/kelola/bengkel/kendaraan', [
        'UuidPelanggan' => $k['Pelanggan']->Uuid, 'NomorPolisi' => 'AD 1234xy', 'Merek' => 'Yamaha',
    ])->assertSessionHasErrors('NomorPolisi');

    $pk = BuatPerintahKerjaUji($this, $k, $kendaraan);
    $baris = PerintahKerjaDetail::query()->where('IdPerintahKerja', $pk->Id)->orderBy('Urutan')->get();
    expect($pk->Status)->toBe(StatusPerintahKerja::Diterima)
        ->and($pk->Nomor)->toBe("WO/{$k['Outlet']->Kode}/2610/0001")
        ->and($pk->Total)->toBe('200000.00')
        ->and($baris->pluck('HargaSatuan')->all())->toBe(['50000.00', '10000.00', '55000.00', '85000.00'])
        ->and($baris[0]->IdKaryawan)->toBe($k['Mekanik']->Id)
        ->and($kendaraan->refresh()->KmTerakhir)->toBe(18250)
        // Perintah kerja tidak menggerakkan stok.
        ->and(StokProduk($k, $k['Oli']))->toBe('10.0000')
        ->and(MutasiStok::query()->where('IdProduk', $k['Oli']->Id)->count())->toBe(1);

    // Minta persetujuan + kirim WhatsApp ke pelanggan.
    MasukBengkel($this, $k)->post("/kelola/bengkel/perintah-kerja/{$pk->Uuid}/persetujuan", ['KirimWhatsapp' => true])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($pk->refresh()->Status)->toBe(StatusPerintahKerja::MenungguPersetujuan)->and($pk->PersetujuanDikirimPada)->not->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn (PermintaanHttp $r): bool => $r['target'] === '6281234567890' && str_contains((string) $r['message'], '/servis/') && str_contains((string) $r['message'], 'AD 1234 XY'));
    expect(LogAudit::query()->where('Peristiwa', 'bengkel.persetujuan-minta')->sole()->NilaiBaru)->not->toHaveKey('Token');

    // Halaman publik: rincian tanpa nomor HP, lalu setujui sebagian (servis + oli).
    $jalur = AmbilTautanPersetujuan($this, $k, $pk);
    app(KonteksTenant::class)->Kosongkan();
    $this->get($jalur)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Publik/PersetujuanServis')
        ->where('PerintahKerja.BolehDiputuskan', true)
        ->where('PerintahKerja.Kendaraan.NomorPolisi', 'AD 1234 XY')
        ->where('PerintahKerja.Total', '200000.00')
        ->has('PerintahKerja.Baris', 4)
        ->missing('PerintahKerja.Pelanggan')
        ->missing('PerintahKerja.Baris.0.Karyawan'));
    $this->from($jalur)->post("{$jalur}/setujui", ['Baris' => [$baris[0]->Uuid, $baris[2]->Uuid], 'Catatan' => 'Busi nanti saja'])
        ->assertSessionHasNoErrors()->assertRedirect($jalur);
    // Satu tautan satu keputusan.
    $this->from($jalur)->post("{$jalur}/tolak")->assertSessionHasErrors('Umum');

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $pk->refresh();
    expect($pk->Status)->toBe(StatusPerintahKerja::Disetujui)
        ->and($pk->TotalDisetujui)->toBe('105000.00')
        ->and($pk->DiputuskanLewat?->value)->toBe('Tautan')
        ->and($pk->HashIpPersetujuan)->toHaveLength(64)
        ->and($pk->CatatanPelanggan)->toBe('Busi nanti saja')
        ->and(PerintahKerjaDetail::query()->where('IdPerintahKerja', $pk->Id)->where('Disetujui', true)->count())->toBe(2);

    // Aplikasi kasir: daftar siap tagih hanya membawa baris yang disetujui (harga server, mekanik baris jasa).
    $this->withToken($k['Token'])->getJson('/api/pos/v1/perintah-kerja')->assertOk()
        ->assertJsonCount(1, 'PerintahKerja')
        ->assertJsonPath('PerintahKerja.0.Uuid', $pk->Uuid)
        ->assertJsonPath('PerintahKerja.0.Kendaraan.NomorPolisi', 'AD 1234 XY')
        ->assertJsonCount(2, 'PerintahKerja.0.Baris')
        ->assertJsonPath('PerintahKerja.0.Baris.0.UuidProduk', $k['Servis']->Uuid)
        ->assertJsonPath('PerintahKerja.0.Baris.0.HargaSatuan', '50000.00')
        ->assertJsonPath('PerintahKerja.0.Baris.0.UuidKaryawan', $k['Mekanik']->Uuid)
        ->assertJsonPath('PerintahKerja.0.Baris.1.Jenis', 'Sparepart')
        ->assertJsonPath('PerintahKerja.0.Baris.1.UuidKaryawan', null);
    $this->withToken($k['Token'])->getJson("/api/pos/v1/perintah-kerja/{$pk->Uuid}")->assertOk()->assertJsonPath('PerintahKerja.SiapTagih', true);

    // Dikerjakan → QC → Selesai.
    foreach (['Dikerjakan', 'Qc', 'Selesai'] as $status) {
        MasukBengkel($this, $k)->post("/kelola/bengkel/perintah-kerja/{$pk->Uuid}/status", ['Status' => $status, 'CatatanQc' => 'Rem & tarikan sudah normal'])->assertSessionHasNoErrors();
    }

    // Ditagih di kasir: baris servis tanpa staf mendapat mekanik dari perintah kerja.
    $item = BantuanPenjualan::Item($k, ['Baris' => [
        ['Produk' => $k['Servis'], 'Jumlah' => '1', 'Harga' => '50000.00'],
        ['Produk' => $k['Oli'], 'Jumlah' => '1', 'Harga' => '55000.00'],
    ]], ['UuidPerintahKerja' => $pk->Uuid, 'UuidPelanggan' => $k['Pelanggan']->Uuid]);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $penjualan = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
    expect($pk->refresh()->Status)->toBe(StatusPerintahKerja::Ditagih)
        ->and($pk->IdPenjualan)->toBe($penjualan->Id)
        ->and($penjualan->PerluTinjauan)->toBeFalse()
        ->and(StokProduk($k, $k['Oli']))->toBe('9.0000')
        ->and(StokProduk($k, $k['Busi']))->toBe('5.0000')
        ->and(Komisi::query()->where('IdPenjualan', $penjualan->Id)->where('IdKaryawan', $k['Mekanik']->Id)->count())->toBe(1)
        ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);

    // Kiriman ulang item yang sama idempoten; tidak muncul lagi di daftar siap tagih.
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);
    $this->withToken($k['Token'])->getJson('/api/pos/v1/perintah-kerja')->assertOk()->assertJsonCount(0, 'PerintahKerja');

    // Tidak bisa ditagih dua kali: penjualan kedua diterima (offline-first) tetapi tidak ditautkan + tinjauan.
    $kedua = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Servis'], 'Jumlah' => '1', 'Harga' => '50000.00']]], ['UuidPerintahKerja' => $pk->Uuid]);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$kedua]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Penjualan::query()->where('Uuid', $kedua['Uuid'])->sole()->AlasanTinjauan)->toContain('sudah ditagih lewat penjualan lain')
        ->and($pk->refresh()->IdPenjualan)->toBe($penjualan->Id);

    // Riwayat servis kendaraan memuat perintah kerja beserta penjualan penagihnya.
    MasukBengkel($this, $k)->get("/kelola/bengkel/kendaraan/{$kendaraan->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Bengkel/Kendaraan/Detail')
        ->where('Riwayat.0.Nomor', $pk->Nomor)
        ->where('Riwayat.0.Penjualan.Nomor', $penjualan->Nomor)
        ->where('Kendaraan.JumlahServis', 1));

    // Void penjualan: perintah kerja kembali Selesai, stok oli kembali, bisa ditagih ulang.
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $penjualan)]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($pk->refresh()->Status)->toBe(StatusPerintahKerja::Selesai)
        ->and($pk->IdPenjualan)->toBeNull()
        ->and(StokProduk($k, $k['Oli']))->toBe('10.0000')
        ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([])
        ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', 'PerintahKerja')->where('IdDokumen', $pk->Id)->orderBy('Id')->pluck('StatusKe')->all())
        ->toBe(['Diterima', 'MenungguPersetujuan', 'Disetujui', 'Dikerjakan', 'Qc', 'Selesai', 'Ditagih', 'Selesai']);
    $this->withToken($k['Token'])->getJson('/api/pos/v1/perintah-kerja')->assertOk()->assertJsonCount(1, 'PerintahKerja');
});

it('perintah kerja yang belum disetujui atau tidak dikenal tidak tertagih: penjualan tetap diterima + tinjauan PerintahKerja', function (): void {
    $k = SiapkanBengkel($this, 'Bengkel Sumber Rejeki Klaten');
    $pk = BuatPerintahKerjaUji($this, $k, BuatKendaraanUji($this, $k));

    $belum = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Oli'], 'Jumlah' => '1', 'Harga' => '55000.00']]], ['UuidPerintahKerja' => $pk->Uuid]);
    $asing = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Oli'], 'Jumlah' => '1', 'Harga' => '55000.00']]], ['UuidPerintahKerja' => (string) Str::ulid()]);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$belum, $asing]))->toBe([['Diterima', null], ['Diterima', null]]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Penjualan::query()->where('Uuid', $belum['Uuid'])->sole()->AlasanTinjauan)->toContain('belum bisa ditagih')
        ->and(Penjualan::query()->where('Uuid', $asing['Uuid'])->sole()->AlasanTinjauan)->toContain('PerintahKerja: perintah kerja tidak ditemukan di outlet ini')
        ->and($pk->refresh()->Status)->toBe(StatusPerintahKerja::Diterima)
        ->and($pk->IdPenjualan)->toBeNull()
        ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
});

it('harga mengikuti tier pelanggan. Jenis produk dan mekanik di sparepart ditolak. Revisi setelah ditolak kembali ke Diagnosis', function (): void {
    $k = SiapkanBengkel($this, 'Bengkel Mitra Gold Boyolali');
    $kendaraan = BuatKendaraanUji($this, $k, 'B 9876 KJT');
    $gold = TierPelanggan::query()->create(['Kode' => 'GOLD', 'Nama' => 'Gold', 'MinimalBelanja' => '0']);
    $k['Pelanggan']->forceFill(['IdTier' => $gold->Id])->save();
    $satuan = ProdukSatuan::query()->where('IdProduk', $k['Servis']->Id)->where('DefaultJual', true)->firstOrFail();
    BantuanHarga::TambahHargaDaftar(BantuanHarga::BuatDaftarHarga('Harga member Gold', ['TierPelanggan' => 'GOLD', 'Prioritas' => 10, 'Aktif' => true]), $satuan, '1.0000', '42500.00');

    $pk = BuatPerintahKerjaUji($this, $k, $kendaraan);
    expect(PerintahKerjaDetail::query()->where('IdPerintahKerja', $pk->Id)->orderBy('Urutan')->value('HargaSatuan'))->toBe('42500.00')
        ->and($pk->Total)->toBe('192500.00');

    $isian = fn (array $baris): array => [
        'UuidOutlet' => $k['Outlet']->Uuid, 'UuidPelanggan' => $k['Pelanggan']->Uuid, 'UuidKendaraan' => $kendaraan->Uuid,
        'Keluhan' => 'Aki tekor', 'Baris' => [$baris],
    ];
    MasukBengkel($this, $k)->post('/kelola/bengkel/perintah-kerja', $isian(['Jenis' => 'Sparepart', 'UuidProduk' => $k['Servis']->Uuid, 'Jumlah' => '1']))
        ->assertSessionHasErrors('Baris.0.UuidProduk');
    MasukBengkel($this, $k)->post('/kelola/bengkel/perintah-kerja', $isian(['Jenis' => 'Jasa', 'UuidProduk' => $k['Oli']->Uuid, 'Jumlah' => '1']))
        ->assertSessionHasErrors('Baris.0.UuidProduk');
    MasukBengkel($this, $k)->post('/kelola/bengkel/perintah-kerja', $isian(['Jenis' => 'Sparepart', 'UuidProduk' => $k['Oli']->Uuid, 'Jumlah' => '1', 'UuidKaryawan' => $k['Mekanik']->Uuid]))
        ->assertSessionHasErrors('Baris.0.UuidKaryawan');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(PerintahKerja::query()->count())->toBe(1);

    // Staf mencatat penolakan pelanggan; revisi estimasi mengembalikannya ke Diagnosis.
    MasukBengkel($this, $k)->post("/kelola/bengkel/perintah-kerja/{$pk->Uuid}/persetujuan/catat", ['Setuju' => false, 'Baris' => [], 'Catatan' => 'Mahal, pikir dulu'])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($pk->refresh()->Status)->toBe(StatusPerintahKerja::Ditolak)->and($pk->DiputuskanLewat?->value)->toBe('Staf');
    BuatPerintahKerjaUji($this, $k, $kendaraan);
    MasukBengkel($this, $k)->put("/kelola/bengkel/perintah-kerja/{$pk->Uuid}", [
        'UuidOutlet' => $k['Outlet']->Uuid, 'UuidPelanggan' => $k['Pelanggan']->Uuid, 'UuidKendaraan' => $kendaraan->Uuid,
        'Keluhan' => 'Tarikan berat', 'Baris' => [['Jenis' => 'Jasa', 'UuidProduk' => $k['Servis']->Uuid, 'Jumlah' => '1']],
    ])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($pk->refresh()->Status)->toBe(StatusPerintahKerja::Diagnosis)->and($pk->Total)->toBe('42500.00');
});

it('tautan persetujuan: token salah, slug tenant lain, atau lewat 7 hari = 404. Isolasi tenant back-office & POS. Izin bengkel.kelola', function (): void {
    $k = SiapkanBengkel($this);
    $pk = BuatPerintahKerjaUji($this, $k, BuatKendaraanUji($this, $k));
    MasukBengkel($this, $k)->post("/kelola/bengkel/perintah-kerja/{$pk->Uuid}/persetujuan")->assertSessionHasNoErrors();
    Http::assertSentCount(0);
    $jalur = AmbilTautanPersetujuan($this, $k, $pk);
    $token = basename($jalur);
    $b = SiapkanBengkel($this, 'Bengkel Lain Sukoharjo');
    app(KonteksTenant::class)->Kosongkan();

    $this->get($jalur)->assertOk();
    $this->get("/{$k['Tenant']->Slug}/servis/".Str::random(40))->assertNotFound();
    $this->get("/{$b['Tenant']->Slug}/servis/{$token}")->assertNotFound();
    $this->post("/{$b['Tenant']->Slug}/servis/{$token}/tolak")->assertNotFound();

    // Tenant lain: back-office & POS tidak melihat perintah kerja ini.
    BantuanOrganisasi::Masuk($this, $b['Pemilik'], $b['Tenant']->Id)->get("/kelola/bengkel/perintah-kerja/{$pk->Uuid}")->assertNotFound();
    BantuanOrganisasi::Masuk($this, $b['Pemilik'], $b['Tenant']->Id)->post("/kelola/bengkel/perintah-kerja/{$pk->Uuid}/status", ['Status' => 'Dibatalkan', 'Alasan' => 'Coba batalkan'])->assertNotFound();
    BantuanOrganisasi::Masuk($this, $b['Pemilik'], $b['Tenant']->Id)->getJson('/kelola/bengkel/perintah-kerja')->assertOk()->assertJsonPath('Meta.Total', 0);
    $this->withToken($b['Token'])->getJson("/api/pos/v1/perintah-kerja/{$pk->Uuid}")->assertNotFound();
    $this->withToken($b['Token'])->getJson('/api/pos/v1/perintah-kerja?status=aktif')->assertOk()->assertJsonCount(0, 'PerintahKerja');
    $this->withToken($k['Token'])->getJson('/api/pos/v1/perintah-kerja?status=aktif')->assertOk()->assertJsonCount(1, 'PerintahKerja');

    // Izin: Kasir & Akuntan ditolak, Supervisor boleh.
    $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
    BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id)->get('/kelola/bengkel/perintah-kerja')->assertForbidden();
    $akuntan = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Akuntan);
    BantuanOrganisasi::Masuk($this, $akuntan, $k['Tenant']->Id)->get('/kelola/bengkel/kendaraan')->assertForbidden();
    $supervisor = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Supervisor);
    BantuanOrganisasi::Masuk($this, $supervisor, $k['Tenant']->Id)->getJson('/kelola/bengkel/perintah-kerja')->assertOk()->assertJsonPath('Meta.Total', 1);

    // Lewat 7 hari: tautan tidak berlaku.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Jakarta'));
    app(KonteksTenant::class)->Kosongkan();
    $this->get($jalur)->assertNotFound();
    $this->post("{$jalur}/setujui", ['Baris' => [(string) Str::ulid()]])->assertNotFound();
});

it('pengingat servis berkala H-3 sekali lewat WhatsApp, Kotak Tindakan servis jatuh tempo, selesai saat kendaraan datang lagi', function (): void {
    $k = SiapkanBengkel($this, 'Bengkel Prima Sragen');
    $kendaraan = BuatKendaraanUji($this, $k);
    $pk = BuatPerintahKerjaUji($this, $k, $kendaraan);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $uuidBaris = PerintahKerjaDetail::query()->where('IdPerintahKerja', $pk->Id)->pluck('Uuid')->all();
    MasukBengkel($this, $k)->post("/kelola/bengkel/perintah-kerja/{$pk->Uuid}/persetujuan/catat", ['Setuju' => true, 'Baris' => $uuidBaris])->assertSessionHasNoErrors();
    foreach (['Dikerjakan', 'Qc', 'Selesai'] as $status) {
        MasukBengkel($this, $k)->post("/kelola/bengkel/perintah-kerja/{$pk->Uuid}/status", ['Status' => $status])->assertSessionHasNoErrors();
    }

    // Tanggal tidak boleh hari ini atau lampau; KM harus di atas KM masuk.
    MasukBengkel($this, $k)->put("/kelola/bengkel/perintah-kerja/{$pk->Uuid}/servis-berikutnya", ['ServisBerikutnyaPada' => '2026-10-13'])->assertSessionHasErrors('ServisBerikutnyaPada');
    MasukBengkel($this, $k)->put("/kelola/bengkel/perintah-kerja/{$pk->Uuid}/servis-berikutnya", ['ServisBerikutnyaKm' => 18000])->assertSessionHasErrors('ServisBerikutnyaKm');
    MasukBengkel($this, $k)->put("/kelola/bengkel/perintah-kerja/{$pk->Uuid}/servis-berikutnya", ['ServisBerikutnyaPada' => '2027-01-13', 'ServisBerikutnyaKm' => 20250])->assertSessionHasNoErrors();

    // Jauh dari jatuh tempo: tidak ada pengingat & tidak ada butir tindakan.
    expect(Artisan::call('bengkel:kirim-pengingat-servis', ['--tenant' => [$k['Tenant']->Id]]))->toBe(0);
    Http::assertSentCount(0);

    // H-3: satu pesan, putaran berikutnya tidak mengirim lagi.
    $this->travelTo(CarbonImmutable::parse('2027-01-10 09:10', 'Asia/Jakarta'));
    Artisan::call('bengkel:kirim-pengingat-servis', ['--tenant' => [$k['Tenant']->Id]]);
    Http::assertSentCount(1);
    Http::assertSent(fn (PermintaanHttp $r): bool => $r['target'] === '6281234567890' && str_contains((string) $r['message'], 'AD 1234 XY') && str_contains((string) $r['message'], '20.250 km'));
    Artisan::call('bengkel:kirim-pengingat-servis', ['--tenant' => [$k['Tenant']->Id]]);
    Http::assertSentCount(1);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($pk->refresh()->PengingatServisTerkirimPada)->not->toBeNull();

    $butir = fn (): ?array => collect(MasukBengkel($this, $k)->get('/kelola/tindakan')->assertOk()->viewData('page')['props']['Butir'])
        ->firstWhere('Kunci', 'bengkel.servis-jatuh-tempo');
    expect($butir()['Jumlah'] ?? null)->toBe(1);
    MasukBengkel($this, $k)->getJson('/kelola/bengkel/perintah-kerja?saring[Perhatian]=ServisJatuhTempo')->assertOk()->assertJsonPath('Meta.Total', 1);

    // Kendaraan datang lagi (perintah kerja baru): butir selesai sendiri.
    BuatPerintahKerjaUji($this, $k, $kendaraan);
    expect($butir()['Jumlah'] ?? 0)->toBe(0);
});

it('bagian 3: sparepart ber-batch (FEFO saat ditagih) dan bernomor seri (dicatat per unit, divalidasi tersedia) sampai tagihan kasir', function (): void {
    $k = SiapkanBengkel($this, 'Bengkel Ban & Aki Sukoharjo');
    $kendaraan = BuatKendaraanUji($this, $k, 'AD 5521 QA');
    $aki = BantuanKatalog::BuatProduk(['Nama' => 'Aki Kering GS Astra GTZ5S 12V 3,5Ah', 'Pelacakan' => PelacakanProduk::Batch], '275000.00');
    $ban = BantuanKatalog::BuatProduk(['Nama' => 'Ban Tubeless IRC NR83 90/80-14', 'Pelacakan' => PelacakanProduk::Seri], '310000.00');
    BantuanStokAwal::BuatDanPosting($k['Gudang'], [
        BantuanStokAwal::Baris($aki, '2', '210000', 'GS-2611', '2027-02-28'),
        BantuanStokAwal::Baris($aki, '3', '205000', 'GS-2609', '2026-12-31'),
        BantuanStokAwal::Baris($ban, '3', '240000', nomorSeri: ['IRC-0001', 'IRC-0002', 'IRC-0003']),
    ], $k['Pemilik']->Id, '2026-10-01');

    $isian = fn (array $baris): array => [
        'UuidOutlet' => $k['Outlet']->Uuid, 'UuidPelanggan' => $k['Pelanggan']->Uuid, 'UuidKendaraan' => $kendaraan->Uuid,
        'Keluhan' => 'Ban belakang gundul, aki soak', 'Baris' => $baris,
    ];
    $kirim = fn (array $baris) => MasukBengkel($this, $k)->post('/kelola/bengkel/perintah-kerja', $isian($baris));

    // Nomor seri: jumlah harus cocok per unit, tidak ganda, tersedia di stok toko; produk non-seri tidak boleh membawanya.
    $kirim([['Jenis' => 'Sparepart', 'UuidProduk' => $ban->Uuid, 'Jumlah' => '2', 'NomorSeri' => ['IRC-0001']]])
        ->assertSessionHasErrors(['Baris.0.NomorSeri' => 'Isi 2 nomor seri Ban Tubeless IRC NR83 90/80-14 (satu per unit), baru 1.']);
    $kirim([['Jenis' => 'Sparepart', 'UuidProduk' => $ban->Uuid, 'Jumlah' => '2', 'NomorSeri' => ['IRC-0001', 'irc-0001']]])
        ->assertSessionHasErrors('Baris.0.NomorSeri');
    $kirim([['Jenis' => 'Sparepart', 'UuidProduk' => $ban->Uuid, 'Jumlah' => '1', 'NomorSeri' => ['IRC-9999']]])
        ->assertSessionHasErrors(['Baris.0.NomorSeri' => 'Nomor seri IRC-9999 Ban Tubeless IRC NR83 90/80-14 tidak tersedia di stok toko outlet ini.']);
    $kirim([['Jenis' => 'Sparepart', 'UuidProduk' => $ban->Uuid, 'Jumlah' => '1.5']])->assertSessionHasErrors('Baris.0.Jumlah');
    // Unit yang sama di dua baris ditolak di sini (bukan baru ditolak saat ditagih di kasir).
    $kirim([
        ['Jenis' => 'Sparepart', 'UuidProduk' => $ban->Uuid, 'Jumlah' => '1', 'NomorSeri' => ['IRC-0001']],
        ['Jenis' => 'Sparepart', 'UuidProduk' => $ban->Uuid, 'Jumlah' => '1', 'NomorSeri' => ['irc-0001']],
    ])->assertSessionHasErrors(['Baris.1.NomorSeri' => 'Nomor seri irc-0001 Ban Tubeless IRC NR83 90/80-14 sudah dipakai di baris lain.']);
    $kirim([['Jenis' => 'Sparepart', 'UuidProduk' => $k['Oli']->Uuid, 'Jumlah' => '1', 'NomorSeri' => ['IRC-0001']]])->assertSessionHasErrors('Baris.0.NomorSeri');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(PerintahKerja::query()->count())->toBe(0);

    // Pencarian sparepart kini memuat produk berpelacakan beserta jenis pelacakannya.
    MasukBengkel($this, $k)->getJson('/kelola/bengkel/produk/cari?kata=IRC&jenis=Sparepart&outlet='.$k['Outlet']->Uuid)->assertOk()
        ->assertJsonPath('Data.0.Uuid', $ban->Uuid)->assertJsonPath('Data.0.Pelacakan', 'Seri')->assertJsonPath('Data.0.StokTersedia', '3.0000');

    $kirim([
        ['Jenis' => 'Jasa', 'UuidProduk' => $k['Servis']->Uuid, 'Jumlah' => '1', 'UuidKaryawan' => $k['Mekanik']->Uuid],
        ['Jenis' => 'Sparepart', 'UuidProduk' => $aki->Uuid, 'Jumlah' => '1'],
        ['Jenis' => 'Sparepart', 'UuidProduk' => $ban->Uuid, 'Jumlah' => '2', 'NomorSeri' => [' irc-0002 ', 'IRC-0003']],
    ])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $pk = PerintahKerja::query()->sole();
    $baris = PerintahKerjaDetail::query()->where('IdPerintahKerja', $pk->Id)->orderBy('Urutan')->get();
    expect($baris[1]->NomorSeri)->toBeNull()
        ->and($baris[2]->NomorSeri)->toBe(['irc-0002', 'IRC-0003'])
        ->and($pk->Total)->toBe('945000.00')
        // Perintah kerja tidak menggerakkan stok maupun status nomor seri.
        ->and(StokProduk($k, $ban))->toBe('3.0000');

    MasukBengkel($this, $k)->get("/kelola/bengkel/perintah-kerja/{$pk->Uuid}/ubah")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Isian.Baris.2.Pelacakan', 'Seri')
        ->where('Isian.Baris.2.NomorSeri', ['irc-0002', 'IRC-0003'])
        ->where('Isian.Baris.1.Pelacakan', 'Batch'));

    MasukBengkel($this, $k)->post("/kelola/bengkel/perintah-kerja/{$pk->Uuid}/persetujuan/catat", ['Setuju' => true, 'Baris' => $baris->pluck('Uuid')->all()])->assertSessionHasNoErrors();
    $this->withToken($k['Token'])->getJson("/api/pos/v1/perintah-kerja/{$pk->Uuid}")->assertOk()
        ->assertJsonPath('PerintahKerja.Baris.1.NomorSeri', [])
        ->assertJsonPath('PerintahKerja.Baris.2.NomorSeri', ['irc-0002', 'IRC-0003']);

    // Ditagih di kasir: nomor seri dari perintah kerja ikut baris, batch aki dialokasikan FEFO (GS-2609 lebih dulu).
    $item = BantuanPenjualan::Item($k, ['Baris' => [
        ['Produk' => $k['Servis'], 'Jumlah' => '1', 'Harga' => '50000.00'],
        ['Produk' => $aki, 'Jumlah' => '1', 'Harga' => '275000.00'],
        ['Produk' => $ban, 'Jumlah' => '2', 'Harga' => '310000.00', 'NomorSeri' => ['irc-0002', 'IRC-0003']],
    ]], ['UuidPerintahKerja' => $pk->Uuid, 'UuidPelanggan' => $k['Pelanggan']->Uuid]);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $penjualan = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
    expect($penjualan->PerluTinjauan)->toBeFalse()
        ->and($pk->refresh()->Status)->toBe(StatusPerintahKerja::Ditagih)
        ->and(StokProduk($k, $ban))->toBe('1.0000')
        ->and(NomorSeri::query()->where('IdProduk', $ban->Id)->orderBy('Nomor')->pluck('Status')->map(fn ($s) => $s->value)->all())->toBe(['Tersedia', 'Terjual', 'Terjual'])
        ->and(BatchStok::query()->where('IdProduk', $aki->Id)->orderBy('NomorBatch')->pluck('JumlahSisa', 'NomorBatch')->all())->toBe(['GS-2609' => '2.0000', 'GS-2611' => '2.0000'])
        ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
});
