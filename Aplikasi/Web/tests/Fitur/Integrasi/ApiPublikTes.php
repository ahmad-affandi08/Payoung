<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Integrasi\ApiPublik\Model\TokenApiTenant;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Persediaan\Aksi\SimpanPenyesuaianStok;
use App\Domain\Persediaan\Data\DataBarisDokumenStok;
use App\Domain\Persediaan\Data\DataPenyesuaianStok;
use App\Domain\Persediaan\Enum\AlasanPenyesuaian;
use App\Domain\Persediaan\Enum\StatusPenyesuaianStok;
use App\Domain\Persediaan\Model\PenyesuaianStok;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * X7 Open API v1 bagian 1 (PRD §16.1 lapisan Publik): Owner membuat token bercakupan di Pengaturan › Token API (paket
 * ber-fitur `api.publik`); aplikasi lain membaca produk/stok/penjualan/pelanggan lewat `/api/v1` dengan kursor. Token
 * tenant lain, dicabut, kedaluwarsa, atau tanpa cakupan ditolak; Id internal tidak pernah keluar.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

function AktifkanApiPublik(Tenant $tenant): void
{
    OverrideTenant::query()->create([
        'IdTenant' => $tenant->Id,
        'Jenis' => JenisOverride::Fitur,
        'Kunci' => 'api.publik',
        'BerakhirPada' => now()->addDays(30),
        'Alasan' => 'Uji integrasi aplikasi akuntansi',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
    BantuanOrganisasi::AturKonteks($tenant->Id);
}

/**
 * @param  list<string>  $cakupan
 */
function BuatTokenUji(mixed $tes, array $k, array $cakupan): string
{
    BantuanOrganisasi::Masuk($tes, $k['Pemilik'], $k['Tenant']->Id)
        ->post('/kelola/pengaturan/api', ['Nama' => 'Aplikasi akuntansi', 'Cakupan' => $cakupan])
        ->assertRedirect('/kelola/pengaturan/api')
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('Kilat');
    $token = session('TokenApiBaru')['Token'] ?? null;
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($token)->toBeString()->toMatch('/^payoung_\d+_[A-Za-z0-9]{40}$/');

    return (string) $token;
}

it('paket tanpa api.publik tidak bisa membuat token; Owner dengan fitur membuat token, token asli hanya tampil sekali', function (): void {
    $k = BantuanPenjualan::Siapkan($this, 'Toko Sembako Integrasi Sukoharjo');
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)
        ->post('/kelola/pengaturan/api', ['Nama' => 'Aplikasi akuntansi', 'Cakupan' => ['produk:baca']])
        ->assertSessionHasErrors();

    AktifkanApiPublik($k['Tenant']);
    $token = BuatTokenUji($this, $k, ['produk:baca']);
    $baris = TokenApiTenant::query()->sole();
    expect($baris->HashToken)->not->toContain($token)
        ->and($baris->Prefiks)->toStartWith("payoung_{$k['Tenant']->Id}_")
        ->and($baris->Cakupan)->toBe(['produk:baca']);

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)->get('/kelola/pengaturan/api')
        ->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Pengaturan/Api')
            ->where('TokenBaru', null)
            ->where('Token.0.Prefiks', $baris->Prefiks)
            ->missing('Token.0.HashToken'));

    // Admin (bukan Owner) tidak boleh mengelola token.
    $admin = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Admin);
    BantuanOrganisasi::Masuk($this, $admin, $k['Tenant']->Id)->get('/kelola/pengaturan/api')->assertForbidden();
});

it('token membaca produk & stok dengan kursor, tanpa cakupan 403. Token tenant lain tidak melihat datanya', function (): void {
    $k = BantuanPenjualan::Siapkan($this, 'Toko Sembako Integrasi Sukoharjo');
    AktifkanApiPublik($k['Tenant']);
    $beras = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Beras Pandan Wangi Karung 5 kg', '40', '60000', '75000.00');
    BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Gula Pasir Lokal Kemasan 1 kg', '100', '14000', '18000.00');
    $token = BuatTokenUji($this, $k, ['produk:baca', 'stok:baca']);
    $api = fn (string $url, ?string $t = null) => $this->withToken($t ?? $token)->getJson($url);

    $halaman1 = $api('/api/v1/produk?per=1')->assertOk()->json();
    expect($halaman1['Data'])->toHaveCount(1)
        ->and($halaman1['Data'][0]['Nama'])->toBe('Beras Pandan Wangi Karung 5 kg')
        ->and($halaman1['Data'][0]['Satuan'][0]['HargaDasar'])->toBe('75000.00')
        ->and($halaman1['Data'][0])->not->toHaveKey('Id')
        ->and($halaman1['Kursor']['Berikutnya'])->toBeString();
    $halaman2 = $api('/api/v1/produk?per=1&kursor='.$halaman1['Kursor']['Berikutnya'])->assertOk()->json();
    expect($halaman2['Data'][0]['Nama'])->toBe('Gula Pasir Lokal Kemasan 1 kg');
    $api('/api/v1/produk?kursor=rusak!!')->assertStatus(422)->assertJsonPath('Galat.Kode', 'KursorTidakValid');
    $api("/api/v1/produk/{$beras->Uuid}")->assertOk()->assertJsonPath('Data.Uuid', $beras->Uuid);

    // K-16: penanda favorit ikut di bentuk produk (field baru, kompatibel mundur).
    expect($halaman1['Data'][0])->toHaveKey('Favorit')->and($halaman1['Data'][0]['Favorit'])->toBeFalse();
    $beras->forceFill(['Favorit' => true])->save();
    $api("/api/v1/produk/{$beras->Uuid}")->assertOk()->assertJsonPath('Data.Favorit', true);

    $stok = $api('/api/v1/stok')->assertOk()->json('Data');
    expect(collect($stok)->firstWhere('UuidProduk', $beras->Uuid))->toMatchArray(['JumlahTersedia' => '40.0000', 'NamaGudang' => $k['Gudang']->Nama]);

    $api('/api/v1/penjualan?dari=2026-10-01&sampai=2026-10-05')->assertForbidden()->assertJsonPath('Galat.Kode', 'CakupanTidakCukup');
    $this->withToken('payoung_1_salah'.str_repeat('x', 30))->getJson('/api/v1/produk')->assertUnauthorized()->assertJsonPath('Galat.Kode', 'TokenApiTidakValid');

    // Token berbentuk sah tetapi rahasia tenant lain: tidak bisa membuka tenant ini.
    $lain = BantuanPenjualan::Siapkan($this, 'Toko Lain Karanganyar');
    AktifkanApiPublik($lain['Tenant']);
    $tokenLain = BuatTokenUji($this, $lain, ['produk:baca']);
    $palsu = preg_replace('/^payoung_\d+_/', "payoung_{$k['Tenant']->Id}_", $tokenLain);
    $this->withToken((string) $palsu)->getJson('/api/v1/produk')->assertUnauthorized();
    expect(collect($api('/api/v1/produk', $tokenLain)->json('Data'))->pluck('Nama'))->not->toContain('Beras Pandan Wangi Karung 5 kg');
});

it('penjualan per rentang tanggal dengan baris & pembayaran. Rentang tidak valid 422. Dicabut & kedaluwarsa 401', function (): void {
    $k = BantuanPenjualan::Siapkan($this, 'Toko Sembako Integrasi Sukoharjo');
    AktifkanApiPublik($k['Tenant']);
    $beras = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Beras Pandan Wangi Karung 5 kg', '40', '60000', '75000.00');
    $jual = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $beras, 'Jumlah' => '2', 'Harga' => '75000.00']]]);
    $token = BuatTokenUji($this, $k, ['penjualan:baca', 'pelanggan:baca']);
    $api = fn (string $url) => $this->withToken($token)->getJson($url);

    $data = $api('/api/v1/penjualan?dari=2026-10-05&sampai=2026-10-05')->assertOk()->json('Data');
    expect($data)->toHaveCount(1)
        ->and($data[0]['Nomor'])->toBe($jual->Nomor)
        ->and($data[0]['TotalAkhir'])->toBe((string) $jual->TotalAkhir)
        ->and($data[0]['Baris'][0])->toMatchArray(['UuidProduk' => $beras->Uuid, 'Jumlah' => '2.0000', 'HargaSatuan' => '75000.00'])
        ->and($data[0]['Baris'][0])->not->toHaveKey('HppSatuan')
        ->and($data[0]['Pembayaran'])->not->toBeEmpty();
    $api("/api/v1/penjualan/{$jual->Uuid}")->assertOk()->assertJsonPath('Data.Uuid', $jual->Uuid);
    $api('/api/v1/penjualan?dari=2026-10-05')->assertStatus(422)->assertJsonPath('Galat.Kode', 'RentangTanggalTidakValid');
    $api('/api/v1/penjualan?dari=2026-01-01&sampai=2026-10-05')->assertStatus(422);
    $api('/api/v1/pelanggan')->assertOk();

    // Dicabut: langsung ditolak.
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)
        ->delete('/kelola/pengaturan/api/'.TokenApiTenant::query()->sole()->Uuid)->assertSessionHasNoErrors();
    $api('/api/v1/pelanggan')->assertUnauthorized();

    // Kedaluwarsa: ditolak setelah tanggalnya lewat.
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)
        ->post('/kelola/pengaturan/api', ['Nama' => 'Laporan BI', 'Cakupan' => ['penjualan:baca'], 'KedaluwarsaPada' => '2026-10-10'])
        ->assertSessionHasNoErrors();
    $tokenSementara = (string) session('TokenApiBaru')['Token'];
    $this->withToken($tokenSementara)->getJson('/api/v1/penjualan?dari=2026-10-05&sampai=2026-10-05')->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-10-11 03:00:00', 'UTC'));
    $this->withToken($tokenSementara)->getJson('/api/v1/penjualan?dari=2026-10-05&sampai=2026-10-05')->assertUnauthorized();
});

it('X7 bagian 4 stok:tulis: penyesuaian stok dari sistem lain langsung diposting di bawah batas, menunggu persetujuan di atasnya. Idempoten per Uuid. Audit atas nama Owner pembuat token', function (): void {
    $k = BantuanPenjualan::Siapkan($this, 'Toko Bangunan Sumber Rejeki Boyolali');
    AktifkanApiPublik($k['Tenant']);
    $semen = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Semen Gresik Portland Komposit 40 kg', '200', '52000', '63500.00');
    $baca = BuatTokenUji($this, $k, ['stok:baca']);
    $tulis = BuatTokenUji($this, $k, ['stok:baca', 'stok:tulis']);
    $saldo = fn (): string => (string) SaldoStok::query()->where('IdProduk', $semen->Id)->where('IdGudang', $k['Gudang']->Id)->value('JumlahTersedia');
    $badan = fn (array $timpa = []): array => array_replace([
        'Uuid' => (string) Str::ulid(),
        'UuidGudang' => $k['Gudang']->Uuid,
        'Tanggal' => '2026-10-05',
        'Alasan' => 'Rusak',
        'Keterangan' => 'Zak sobek saat bongkar truk',
        'Baris' => [['UuidProduk' => $semen->Uuid, 'Jumlah' => '-3']],
    ], $timpa);
    $kirim = fn (string $token, array $isi) => $this->withToken($token)->postJson('/api/v1/stok/penyesuaian', $isi);

    $kirim($baca, $badan())->assertForbidden()->assertJsonPath('Galat.Kode', 'CakupanTidakCukup');

    // Di bawah batas persetujuan (3 × 52.000 < 500.000): langsung diposting.
    $isi = $badan();
    $hasil = $kirim($tulis, $isi)->assertCreated()->json('Data');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($hasil['Uuid'])->toBe($isi['Uuid'])
        ->and($hasil['Status'])->toBe('Diposting')
        ->and($hasil['Nomor'])->toStartWith('PS/')
        ->and($hasil['Baris'])->toBe([['UuidProduk' => $semen->Uuid, 'Jumlah' => '-3.0000', 'NomorBatch' => null]])
        ->and($saldo())->toBe('197.0000');

    // Kirim ulang Uuid yang sama: dokumen yang sama, stok tidak berkurang lagi.
    expect($kirim($tulis, $isi)->assertOk()->json('Data.Nomor'))->toBe($hasil['Nomor']);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($saldo())->toBe('197.0000')
        ->and(PenyesuaianStok::query()->count())->toBe(1);
    // Uuid sama dengan isi berbeda bukan kiriman ulang: 409, bukan 200 yang menyesatkan integrator.
    $kirim($tulis, array_replace($isi, ['Baris' => [['UuidProduk' => $semen->Uuid, 'Jumlah' => '-4']]]))
        ->assertStatus(409)->assertJsonPath('Galat.Kode', 'UuidSudahDipakai');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($saldo())->toBe('197.0000')->and(PenyesuaianStok::query()->count())->toBe(1);
    $audit = LogAudit::query()->where('Peristiwa', 'penyesuaian-stok.posting')->sole();
    expect($audit->IdPengguna)->toBe($k['Pemilik']->Id)->and($audit->AgenPengguna)->toContain('Token API payoung_');

    // Di atas batas (20 × 52.000): menunggu persetujuan di back-office, stok belum bergerak.
    $besar = $kirim($tulis, $badan(['Alasan' => 'Hilang', 'Baris' => [['UuidProduk' => $semen->Uuid, 'Jumlah' => '-20']]]))->assertCreated()->json('Data');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($besar['Status'])->toBe('MenungguPersetujuan')->and($saldo())->toBe('197.0000');

    // Stok masuk wajib alasan Lainnya + harga modal.
    $kirim($tulis, $badan(['Alasan' => 'Lainnya', 'Keterangan' => 'Temuan stok opname gudang belakang', 'Baris' => [['UuidProduk' => $semen->Uuid, 'Jumlah' => '5']]]))
        ->assertStatus(422)->assertJsonStructure(['Galat' => ['Kode', 'Pesan']]);
    $masuk = $kirim($tulis, $badan(['Alasan' => 'Lainnya', 'Keterangan' => 'Temuan stok opname gudang belakang', 'Baris' => [['UuidProduk' => $semen->Uuid, 'Jumlah' => '5', 'HppSatuan' => '52000']]]))
        ->assertCreated()->json('Data');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($masuk['Status'])->toBe('Diposting')
        ->and($saldo())->toBe('202.0000')
        ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);

    // Kunci respons = skema spesifikasi publik.
    $skema = json_decode((string) file_get_contents(public_path('pengembang/openapi-v1.json')), true)['components']['schemas']['HasilPenyesuaianStok'];
    expect(array_keys($masuk))->toEqualCanonicalizing(array_keys($skema['properties']))
        ->and(array_keys($masuk['Baris'][0]))->toEqualCanonicalizing(array_keys($skema['properties']['Baris']['items']['properties']));
});

it('X7 stok:tulis menolak lokasi/produk tak dikenal (termasuk milik tenant lain), produk bernomor seri, dan badan tidak valid tanpa membuat dokumen', function (): void {
    $k = BantuanPenjualan::Siapkan($this, 'Toko Bangunan Sumber Rejeki Boyolali');
    AktifkanApiPublik($k['Tenant']);
    $semen = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Semen Gresik Portland Komposit 40 kg', '200', '52000', '63500.00');
    $seri = BantuanKatalog::BuatProduk(['Nama' => 'Bor Listrik Makita HP1630', 'Pelacakan' => PelacakanProduk::Seri], '650000.00');
    $tulis = BuatTokenUji($this, $k, ['stok:tulis']);
    $lain = BantuanPenjualan::Siapkan($this, 'Toko Sebelah Klaten');
    $kirim = fn (array $timpa) => $this->withToken($tulis)->postJson('/api/v1/stok/penyesuaian', array_replace([
        'Uuid' => (string) Str::ulid(),
        'UuidGudang' => $k['Gudang']->Uuid,
        'Tanggal' => '2026-10-05',
        'Alasan' => 'Rusak',
        'Baris' => [['UuidProduk' => $semen->Uuid, 'Jumlah' => '-1']],
    ], $timpa));

    $kirim(['UuidGudang' => $lain['Gudang']->Uuid])->assertStatus(422)->assertJsonPath('Galat.Kode', 'GudangTidakDikenal');
    $kirim(['Baris' => [['UuidProduk' => (string) Str::ulid(), 'Jumlah' => '-1']]])->assertStatus(422)->assertJsonPath('Galat.Kode', 'ProdukTidakDikenal');
    $kirim(['Baris' => [['UuidProduk' => $seri->Uuid, 'Jumlah' => '-1']]])->assertStatus(422)->assertJsonPath('Galat.Kode', 'PelacakanBelumDidukung');
    $kirim(['Baris' => [['UuidProduk' => $semen->Uuid, 'Jumlah' => '-1', 'NomorBatch' => 'BT-01']]])->assertStatus(422);
    $kirim(['Alasan' => 'Dicuri'])->assertStatus(422)->assertJsonPath('Galat.Kode', 'ValidasiGagal');
    $kirim(['Uuid' => 'bukan-ulid'])->assertStatus(422)->assertJsonPath('Galat.Kode', 'ValidasiGagal');
    $kirim(['Baris' => []])->assertStatus(422)->assertJsonPath('Galat.Kode', 'ValidasiGagal');

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(PenyesuaianStok::query()->count())->toBe(0);

    // Uuid yang sudah dipakai draf back-office tidak membuat draf itu terajukan dengan isi lamanya.
    $uuidDraf = (string) Str::ulid();
    app(SimpanPenyesuaianStok::class)->Jalankan(new DataPenyesuaianStok(
        uuid: $uuidDraf,
        idGudang: $k['Gudang']->Id,
        tanggal: CarbonImmutable::parse('2026-10-05'),
        alasan: AlasanPenyesuaian::Rusak,
        keterangan: null,
        baris: [new DataBarisDokumenStok(idProduk: $semen->Id, jumlah: Kuantitas::Dari('-5'))],
    ), null);
    $kirim(['Uuid' => $uuidDraf])->assertStatus(422)->assertJsonPath('Galat.Kode', 'UuidSudahDipakai');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(PenyesuaianStok::query()->where('Uuid', $uuidDraf)->sole()->Status)->toBe(StatusPenyesuaianStok::Draf);
});
