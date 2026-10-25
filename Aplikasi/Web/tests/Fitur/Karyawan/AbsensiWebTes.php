<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Aksi\AturTautanAbsen;
use App\Domain\Karyawan\Aksi\TinjauWajahKaryawan;
use App\Domain\Karyawan\Aksi\UbahStatusKaryawan;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Enum\StatusWajahKaryawan;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\AturanKehadiran;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\PermintaanKoreksiAbsensi;
use App\Domain\Karyawan\Model\WajahKaryawan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Tenant\Kueri\ProfilTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-18 bagian 4 (D-37, D-180): absensi web dari HP pribadi setelah login. Daftar wajah sekali (persetujuan PDP), absen
 * masuk/keluar dengan wajah yang cocok (selalu ditolak bila tidak cocok); lokasi yang janggal tetap tercatat dan
 * ditandai "perlu dicek", kecuali aturan tolak-di-luar-radius menyala.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00', 'Asia/Jakarta'));
});

/** Sidik wajah uji: 128 bilangan bulat; [geser] menghasilkan wajah yang berbeda. */
function SidikWajahUji(int $geser = 0): array
{
    return array_map(fn (int $i): int => (int) round(1000 * sin(($i + $geser * 37) * 0.7)), range(0, 127));
}

function SwafotoAbsenWebUji(): string
{
    return base64_encode("\xFF\xD8\xFF\xE0".str_repeat('a', 200));
}

/**
 * Karyawan yang sudah login (peran Karyawan, tanpa izin back-office) di tenant uji dengan satu outlet ber-lokasi.
 *
 * @return array{0: array<string, mixed>, 1: Karyawan, 2: string, 3: Pengguna} [konteks, karyawan, alamat absen, akun]
 */
function SiapkanAbsensiWeb(TestCase $tes, bool $wajahDisetujui = true): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Kedai Absen Web');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $k['Outlet']->forceFill(['Lintang' => '-7.5560000', 'Bujur' => '110.8310000', 'RadiusAbsensiMeter' => 100])->save();
    $akun = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Karyawan);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $karyawan = Karyawan::query()->create(['Nama' => 'Rina Wulandari', 'IdOutlet' => $k['Outlet']->Id, 'IdPengguna' => $akun->Id]);
    $alamat = '/kelola/absen';
    BantuanOrganisasi::Masuk($tes, $akun, $k['Tenant']->Id);

    if ($wajahDisetujui) {
        $tes->postJson("{$alamat}/wajah", ['SidikWajah' => [SidikWajahUji(), SidikWajahUji(), SidikWajahUji()], 'Foto' => [SwafotoAbsenWebUji(), SwafotoAbsenWebUji(), SwafotoAbsenWebUji()], 'Persetujuan' => true])->assertCreated();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    }

    return [$k, $karyawan->refresh(), $alamat, $akun];
}

/** Masuk lagi sebagai karyawan setelah sempat bertindak sebagai pemilik toko. */
function MasukSebagaiKaryawanAbsen(TestCase $tes, array $k, Pengguna $akun): void
{
    BantuanOrganisasi::Masuk($tes, $akun, $k['Tenant']->Id);
}

/** @return array<string, mixed> */
function KirimanAbsenWebUji(array $ubah = []): array
{
    return ['Uuid' => (string) Str::ulid(), 'Lintang' => '-7.5560000', 'Bujur' => '110.8315000', 'AkurasiMeter' => 15, 'SidikWajah' => SidikWajahUji(), 'Swafoto' => SwafotoAbsenWebUji(), ...$ubah];
}

it('halaman Absen hanya untuk karyawan yang ikut absensi dan tertaut ke akunnya; tanpa sidik wajah, foto, atau koordinat', function (): void {
    [$k, $karyawan, $alamat, $akun] = SiapkanAbsensiWeb($this);

    $this->get($alamat)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Absen/Saya')
        ->where('NamaKaryawan', 'Rina Wulandari')
        ->where('Wajah.Status', 'Disetujui')
        ->where('AbsensiTerbuka', null)
        ->where('SimpanFoto', true)
        ->where('BisaDashboard', false)
        ->where('Berlogin', true)
        ->where('AlamatDasar', '/kelola/absen')
        ->where('AbsenSaya', true)
        ->missing('SidikWajah'));

    // Pemilik toko tanpa data karyawan: tidak ada yang bisa diabsen, halaman dianggap tidak ada.
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->get($alamat)->assertNotFound();
    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji())->assertNotFound();

    // Karyawan yang tidak ikut absensi (mis. dicopot pemilik toko): sama, 404.
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $karyawan->forceFill(['IkutAbsensi' => false])->save();
    MasukSebagaiKaryawanAbsen($this, $k, $akun);
    $this->get($alamat)->assertNotFound();
});

it('staf yang akunnya hanya untuk absen (tanpa izin back-office) dialihkan dari Beranda ke halaman Absen; pemilik tidak', function (): void {
    [$k, , , $akun] = SiapkanAbsensiWeb($this);

    $this->get('/kelola')->assertRedirect(route('kelola.absen'));

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->get('/kelola')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Beranda')->where('AbsenSaya', false));

    // Karyawan yang juga punya izin lain (Supervisor) tetap ke Beranda, dengan kartu pintasan Absen.
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $spv = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Supervisor);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    Karyawan::query()->create(['Nama' => 'Susi Supervisor', 'IdOutlet' => $k['Outlet']->Id, 'IdPengguna' => $spv->Id, 'IkutAbsensi' => true]);
    BantuanOrganisasi::Masuk($this, $spv, $k['Tenant']->Id);
    $this->get('/kelola')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Beranda')->where('AbsenSaya', true));
    $this->get('/kelola/absen')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('BisaDashboard', true));
    expect($akun->Id)->not->toBe($spv->Id);
});

it('daftar wajah: persetujuan pemrosesan wajib, jumlah foto pas, tidak bisa daftar ganda; absen ditolak sebelum mendaftar dan langsung bisa sesudahnya (D-45)', function (): void {
    [$k, , $alamat] = SiapkanAbsensiWeb($this, wajahDisetujui: false);
    $isi = ['SidikWajah' => [SidikWajahUji(), SidikWajahUji(), SidikWajahUji()], 'Foto' => [SwafotoAbsenWebUji(), SwafotoAbsenWebUji(), SwafotoAbsenWebUji()]];

    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji())->assertStatus(422)->assertJsonPath('Galat.Kode', 'WajahBelumDisetujui');
    $this->postJson("{$alamat}/wajah", [...$isi, 'Persetujuan' => false])->assertStatus(422)->assertJsonPath('Galat.Kode', 'PersetujuanWajib');
    $this->postJson("{$alamat}/wajah", [...$isi, 'Foto' => [SwafotoAbsenWebUji()], 'Persetujuan' => true])->assertStatus(422)->assertJsonPath('Galat.Kode', 'JumlahFotoWajah');
    $this->postJson("{$alamat}/wajah", [...$isi, 'Persetujuan' => true])->assertCreated()->assertJsonPath('Status', 'Disetujui');
    $this->postJson("{$alamat}/wajah", [...$isi, 'Persetujuan' => true])->assertStatus(422)->assertJsonPath('Galat.Kode', 'WajahSudahTerdaftar');
    // Tanpa menunggu pengelola: setelah mendaftar, karyawan langsung bisa absen.
    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji())->assertOk();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Absensi::query()->count())->toBe(1)
        ->and(WajahKaryawan::query()->sole()->DitinjauOleh)->toBeNull();
});

it('masuk & keluar di dalam radius dengan wajah cocok: Sumber Web, jarak & kemiripan tercatat, idempoten per Uuid', function (): void {
    [$k, $karyawan, $alamat] = SiapkanAbsensiWeb($this);
    $masuk = KirimanAbsenWebUji();

    $this->postJson("{$alamat}/masuk", $masuk)->assertOk()->assertJsonPath('JarakMeter', 55);
    $this->postJson("{$alamat}/masuk", $masuk)->assertOk();
    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji())->assertStatus(422)->assertJsonPath('Galat.Kode', 'SudahAbsenMasuk');

    $this->travel(8)->hours();
    $keluar = KirimanAbsenWebUji(['Uuid' => $masuk['Uuid'], 'Bujur' => '110.8310000']);
    $this->postJson("{$alamat}/keluar", $keluar)->assertOk()->assertJsonPath('JarakMeter', 0);
    $this->postJson("{$alamat}/keluar", $keluar)->assertOk();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $a = Absensi::query()->sole();
    expect($a->Sumber)->toBe('Web')
        ->and($a->IdKaryawan)->toBe($karyawan->Id)
        ->and($a->IdOutlet)->toBe($k['Outlet']->Id)
        ->and($a->IdPerangkat)->toBeNull()
        ->and($a->TanggalBisnis->toDateString())->toBe('2026-10-05')
        ->and($a->JarakMasukMeter)->toBe(55)
        ->and($a->KemiripanWajahMasuk)->toBe('1.0000')
        ->and($a->KeluarPada)->not->toBeNull()
        ->and($a->PathSwafotoMasuk)->not->toBeNull()
        ->and($a->PathSwafotoKeluar)->not->toBeNull()
        ->and($a->LintangMasuk)->toBe('-7.5560000')
        ->and($a->PerluDicek)->toBeFalse()
        ->and($a->CatatanPeriksa)->toBeNull();
});

it('wajah lain ditolak tanpa baris & tanpa swafoto; jejaknya di audit tanpa sidik wajah', function (): void {
    [$k, , $alamat] = SiapkanAbsensiWeb($this);

    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji(['SidikWajah' => SidikWajahUji(5)]))->assertStatus(422)->assertJsonPath('Galat.Kode', 'WajahTidakCocok');

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Absensi::query()->count())->toBe(0)
        ->and(LogAudit::query()->where('Peristiwa', 'absensi.web.wajah-tidak-cocok')->sole()->NilaiBaru)->not->toHaveKey('SidikWajah')
        // Hanya 3 foto pendaftaran wajah yang tersimpan; swafoto absen yang ditolak tidak pernah ditulis.
        ->and(Storage::disk('local')->allFiles())->toHaveCount(3);
});

it('cek wajah langsung (D-186): hanya jawaban Cocok ya/tidak, tidak mencatat absen atau audit, tanpa skor atau sidik terdaftar', function (): void {
    [$k, , $alamat] = SiapkanAbsensiWeb($this);

    $cocok = $this->postJson("{$alamat}/cek-wajah", ['SidikWajah' => SidikWajahUji()])->assertOk();
    $beda = $this->postJson("{$alamat}/cek-wajah", ['SidikWajah' => SidikWajahUji(5)])->assertOk();

    expect($cocok->json())->toBe(['Cocok' => true])
        ->and($beda->json())->toBe(['Cocok' => false]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Absensi::query()->count())->toBe(0)
        ->and(LogAudit::query()->where('Peristiwa', 'like', 'absensi.web.%')->count())->toBe(0);
});

it('cek wajah langsung: sidik cacat ditolak 422, wajah belum disetujui selalu tidak cocok, dan pemilik tanpa data karyawan 404', function (): void {
    [$k, , $alamat] = SiapkanAbsensiWeb($this, false);

    $this->postJson("{$alamat}/cek-wajah", ['SidikWajah' => SidikWajahUji()])->assertOk()->assertExactJson(['Cocok' => false]);
    $this->postJson("{$alamat}/cek-wajah", ['SidikWajah' => [1, 2, 3]])->assertStatus(422);
    $this->postJson("{$alamat}/cek-wajah", [])->assertStatus(422);

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->postJson("{$alamat}/cek-wajah", ['SidikWajah' => SidikWajahUji()])->assertNotFound();
});

it('bawaan: di luar radius, GPS lemah, atau outlet tanpa lokasi tetap tercatat dan ditandai perlu dicek dengan catatan', function (): void {
    [$k, , $alamat] = SiapkanAbsensiWeb($this);

    // 195 m dari outlet (radius 100 m).
    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji(['Bujur' => '110.8330000']))->assertOk()->assertJsonPath('PerluDicek', true);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $a = Absensi::query()->sole();
    expect($a->PerluDicek)->toBeTrue()
        ->and($a->CatatanPeriksa)->toContain('Di luar radius')->toContain($k['Outlet']->Nama)
        ->and($a->JarakMasukMeter)->toBeGreaterThan(100)
        ->and(LogAudit::query()->where('Peristiwa', 'absensi.web.di-luar-radius')->count())->toBe(1);

    // Keluar dengan sinyal GPS lemah: tanda masuk dipertahankan, catatan keluar ditambahkan.
    $this->postJson("{$alamat}/keluar", KirimanAbsenWebUji(['Uuid' => $a->Uuid, 'AkurasiMeter' => 500]))->assertOk()->assertJsonPath('PerluDicek', true);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($a->refresh()->CatatanPeriksa)->toContain('Di luar radius')->toContain('Keluar: Sinyal GPS lemah');

    // Hari berikutnya, outlet tanpa titik lokasi: tercatat di outlet karyawan, catatan menyebut titik belum diatur.
    $this->travel(1)->day();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $k['Outlet']->forceFill(['Lintang' => null, 'Bujur' => null])->save();
    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji())->assertOk()->assertJsonPath('PerluDicek', true);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $kedua = Absensi::query()->latest('Id')->firstOrFail();
    expect($kedua->IdOutlet)->toBe($k['Outlet']->Id)->and($kedua->JarakMasukMeter)->toBeNull()->and($kedua->CatatanPeriksa)->toContain('belum diatur');
});

it('aturan tolak-di-luar-radius: di luar radius, akurasi buruk, atau outlet tanpa lokasi ditolak tanpa baris', function (): void {
    [$k, , $alamat] = SiapkanAbsensiWeb($this);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    AturanKehadiran::query()->create(['TolakDiLuarRadius' => true]);

    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji(['Bujur' => '110.8330000']))->assertStatus(422)->assertJsonPath('Galat.Kode', 'DiLuarRadius');
    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji(['AkurasiMeter' => 500]))->assertStatus(422)->assertJsonPath('Galat.Kode', 'AkurasiLokasiRendah');

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Absensi::query()->count())->toBe(0);

    $k['Outlet']->forceFill(['Lintang' => null, 'Bujur' => null])->save();
    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji())->assertStatus(422)->assertJsonPath('Galat.Kode', 'OutletTanpaLokasi');
});

it('aturan simpan foto bukti: dimatikan = swafoto tidak disimpan dan tidak diminta, wajah tetap dicocokkan; dinyalakan = swafoto wajib', function (): void {
    [$k, , $alamat] = SiapkanAbsensiWeb($this);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    // Bawaan: foto wajib dikirim.
    $this->postJson("{$alamat}/masuk", [...KirimanAbsenWebUji(), 'Swafoto' => null])->assertStatus(422)->assertJsonPath('Galat.Kode', 'SwafotoWajib');

    AturanKehadiran::query()->create(['SimpanFotoBukti' => false]);
    $tanpaFoto = fn (array $ubah = []): array => [...KirimanAbsenWebUji($ubah), 'Swafoto' => null];
    // Wajah lain tetap ditolak walau foto tidak disimpan.
    $this->postJson("{$alamat}/masuk", $tanpaFoto(['SidikWajah' => SidikWajahUji(5)]))->assertStatus(422)->assertJsonPath('Galat.Kode', 'WajahTidakCocok');
    $masuk = $tanpaFoto();
    $this->postJson("{$alamat}/masuk", $masuk)->assertOk();
    $this->get($alamat)->assertInertia(fn (AssertableInertia $h) => $h->where('SimpanFoto', false));

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $a = Absensi::query()->sole();
    expect($a->PathSwafotoMasuk)->toBeNull()
        ->and($a->KemiripanWajahMasuk)->toBe('1.0000')
        // Hanya 3 foto pendaftaran wajah di disk.
        ->and(Storage::disk('local')->allFiles())->toHaveCount(3);
});

it('karyawan dinonaktifkan: halaman Absen tidak ada lagi dan wajah beserta fotonya dihapus', function (): void {
    [$k, $karyawan, $alamat] = SiapkanAbsensiWeb($this);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    app(UbahStatusKaryawan::class)->Jalankan($karyawan, StatusKaryawan::Nonaktif, $k['Pemilik']->Id);

    $this->get($alamat)->assertNotFound();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(WajahKaryawan::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('tinjau wajah: tolak wajib beralasan dan menghapus sidik & foto; sesudahnya karyawan bisa daftar ulang', function (): void {
    [$k, , $alamat] = SiapkanAbsensiWeb($this, wajahDisetujui: false);
    $isi = ['SidikWajah' => [SidikWajahUji(), SidikWajahUji(), SidikWajahUji()], 'Foto' => [SwafotoAbsenWebUji(), SwafotoAbsenWebUji(), SwafotoAbsenWebUji()], 'Persetujuan' => true];
    $this->postJson("{$alamat}/wajah", $isi)->assertCreated();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $wajah = WajahKaryawan::query()->sole();
    expect(fn () => app(TinjauWajahKaryawan::class)->Jalankan($wajah, false, '  ', $k['Pemilik']->Id))->toThrow(PelanggaranAturanBisnis::class, 'alasan');

    app(TinjauWajahKaryawan::class)->Jalankan($wajah, false, 'Foto gelap, ulangi di tempat terang', $k['Pemilik']->Id);
    $wajah->refresh();
    expect($wajah->Status)->toBe(StatusWajahKaryawan::Ditolak)
        ->and($wajah->SidikWajah)->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    $this->get($alamat)->assertInertia(fn (AssertableInertia $h) => $h->where('Wajah.AlasanTolak', 'Foto gelap, ulangi di tempat terang'));
    $this->postJson("{$alamat}/wajah", $isi)->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(WajahKaryawan::query()->sole()->Status)->toBe(StatusWajahKaryawan::Disetujui);
});

it('back-office panel wajah: panel tanpa sidik wajah, tinjau lewat HTTP, foto hanya karyawan.kelola', function (): void {
    [$k, $karyawan, $alamat, $akun] = SiapkanAbsensiWeb($this, wajahDisetujui: false);
    $this->postJson("{$alamat}/wajah", ['SidikWajah' => [SidikWajahUji(), SidikWajahUji(), SidikWajahUji()], 'Foto' => [SwafotoAbsenWebUji(), SwafotoAbsenWebUji(), SwafotoAbsenWebUji()], 'Persetujuan' => true])->assertCreated();
    $panel = "/kelola/karyawan/{$karyawan->Uuid}";

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->getJson("{$panel}/absen-hp")->assertOk()
        ->assertJsonPath('Wajah.Status', 'Disetujui')
        ->assertJsonPath('Wajah.JumlahFoto', 3)
        ->assertJsonMissingPath('Wajah.SidikWajah')
        // D-198: tautan absen pribadi ada lagi, tetapi belum dibuat sampai pengelola membuatnya.
        ->assertJsonPath('Tautan', null);
    $this->get("{$panel}/wajah/foto/0")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->post("{$panel}/tautan-absen")->assertRedirect();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($this->getJson("{$panel}/absen-hp")->json('Tautan'))->toStartWith('http');

    $this->post("{$panel}/wajah/tinjau", ['Setujui' => false, 'Alasan' => ''])->assertSessionHasErrors('Alasan');
    // D-45: wajah yang sudah aktif tidak perlu disetujui lagi, tetapi pengelola masih boleh menolak fotonya.
    $this->post("{$panel}/wajah/tinjau", ['Setujui' => true])->assertSessionHasErrors();
    $this->post("{$panel}/wajah/tinjau", ['Setujui' => false, 'Alasan' => 'Foto gelap'])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(WajahKaryawan::query()->sole()->Status)->toBe(StatusWajahKaryawan::Ditolak);

    // Karyawan melihat alasannya di halaman Absen dan boleh mendaftar ulang.
    MasukSebagaiKaryawanAbsen($this, $k, $akun);
    $this->get($alamat)->assertInertia(fn (AssertableInertia $h) => $h->where('Wajah.Status', 'Ditolak')->where('Wajah.AlasanTolak', 'Foto gelap'));

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->delete("{$panel}/wajah")->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(WajahKaryawan::query()->count())->toBe(0);

    // Pemegang karyawan.lihat saja (Supervisor) tidak boleh melihat foto wajah maupun panel.
    $supervisor = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Supervisor);
    BantuanOrganisasi::Masuk($this, $supervisor, $k['Tenant']->Id);
    $this->getJson("{$panel}/absen-hp")->assertForbidden();
    $this->get("{$panel}/wajah/foto/0")->assertForbidden();

    // Karyawan tenant lain = 404.
    $lain = BantuanPenjualan::Siapkan($this, 'Toko Lain Panel');
    BantuanOrganisasi::Masuk($this, $lain['Pemilik'], $lain['Tenant']->Id);
    $this->getJson("{$panel}/absen-hp")->assertNotFound();
});

it('lokasi absensi outlet: simpan titik & radius (dinormalkan 7 desimal), validasi, hapus titik; rekap absensi memuat jarak', function (): void {
    [$k, , $alamat, $akun] = SiapkanAbsensiWeb($this);
    $outlet = "/kelola/outlet/{$k['Outlet']->Uuid}";
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

    $this->post("{$outlet}/lokasi-absensi", ['Lintang' => '-7.55612', 'Bujur' => '110.83', 'RadiusAbsensiMeter' => 150])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $baris = $k['Outlet']->refresh();
    expect($baris->Lintang)->toBe('-7.5561200')->and($baris->Bujur)->toBe('110.8300000')->and($baris->RadiusAbsensiMeter)->toBe(150);

    $this->post("{$outlet}/lokasi-absensi", ['Lintang' => '-7.5', 'Bujur' => null, 'RadiusAbsensiMeter' => 100])->assertSessionHasErrors('Lintang');
    $this->post("{$outlet}/lokasi-absensi", ['Lintang' => '-97.5', 'Bujur' => '110', 'RadiusAbsensiMeter' => 100])->assertSessionHasErrors('Lintang');
    $this->post("{$outlet}/lokasi-absensi", ['Lintang' => '-7.5', 'Bujur' => '110', 'RadiusAbsensiMeter' => 5])->assertSessionHasErrors('RadiusAbsensiMeter');
    $this->get($outlet)->assertInertia(fn (AssertableInertia $h) => $h->where('LokasiAbsensi.RadiusMeter', 150));

    $this->post("{$outlet}/lokasi-absensi", ['Lintang' => '-7.5560000', 'Bujur' => '110.8310000', 'RadiusAbsensiMeter' => 100])->assertSessionHasNoErrors();
    MasukSebagaiKaryawanAbsen($this, $k, $akun);
    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji())->assertOk();
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->getJson('/kelola/karyawan/absensi')->assertOk()
        ->assertJsonPath('Data.0.Sumber', 'Web')
        ->assertJsonPath('Data.0.JarakMasukMeter', 55)
        ->assertJsonPath('Data.0.KemiripanWajahMasuk', '1.0000');

    $this->post("{$outlet}/lokasi-absensi", ['Lintang' => null, 'Bujur' => null, 'RadiusAbsensiMeter' => 100])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($k['Outlet']->refresh()->Lintang)->toBeNull();
});

it('batas percobaan per akun: satu karyawan yang kena batas tidak menghabiskan jatah rekan satu wifi', function (): void {
    [$k, , $alamat] = SiapkanAbsensiWeb($this, wajahDisetujui: false);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $akunRekan = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Karyawan);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    Karyawan::query()->create(['Nama' => 'Dewi Lestari', 'IdOutlet' => $k['Outlet']->Id, 'IdPengguna' => $akunRekan->Id]);

    foreach (range(1, 10) as $_) {
        $this->postJson("{$alamat}/masuk", [])->assertStatus(422);
    }

    $this->postJson("{$alamat}/masuk", [])->assertStatus(429);
    BantuanOrganisasi::Masuk($this, $akunRekan, $k['Tenant']->Id);
    $this->postJson("{$alamat}/masuk", [])->assertStatus(422);
});

it('masuk memilih outlet terdekat yang radiusnya memuat posisi; jadwal dinilai per tanggal bisnis (kemarin boleh, besok tidak)', function (): void {
    [$k, $karyawan, $alamat] = SiapkanAbsensiWeb($this);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    // Aturan tolak-di-luar-radius menyala supaya pemilihan outlet terlihat dari penolakan.
    AturanKehadiran::query()->create(['TolakDiLuarRadius' => true]);
    // Outlet A: radius 20 m, posisi ±30 m darinya. Outlet B: radius 100 m, posisi ±58 m darinya.
    $k['Outlet']->forceFill(['RadiusAbsensiMeter' => 20])->save();
    $b = BantuanJurnal::BuatOutlet('ABS-B', 'Outlet Sebelah');
    $b->forceFill(['Lintang' => '-7.5560000', 'Bujur' => '110.8318000', 'RadiusAbsensiMeter' => 100])->save();
    $posisi = ['Bujur' => '110.8312700'];

    // Outlet utama A saja: di luar radius A, walau di dalam radius B.
    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji($posisi))->assertStatus(422)->assertJsonPath('Galat.Kode', 'DiLuarRadius');

    // Jadwal di B untuk besok (tanggal bisnis) tidak membuka B hari ini.
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    JadwalKerja::query()->create(['IdKaryawan' => $karyawan->Id, 'IdOutlet' => $b->Id, 'Tanggal' => '2026-10-06', 'JamMulai' => '08:00', 'JamSelesai' => '16:00']);
    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji($posisi))->assertStatus(422)->assertJsonPath('Galat.Kode', 'DiLuarRadius');

    // Jadwal kemarin (shift malam lewat tengah malam) membuka B; B dipilih walau A lebih dekat.
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    JadwalKerja::query()->create(['IdKaryawan' => $karyawan->Id, 'IdOutlet' => $b->Id, 'Tanggal' => '2026-10-04', 'JamMulai' => '22:00', 'JamSelesai' => '06:00']);
    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji($posisi))->assertOk();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Absensi::query()->sole()->IdOutlet)->toBe($b->Id)
        ->and(LogAudit::query()->where('Peristiwa', 'absensi.web.di-luar-radius')->latest('Id')->first()?->Ip)->not->toBeNull();
});

it('keluar setelah titik lokasi outlet dihapus: bawaan tetap tercatat dan ditandai; dengan aturan tolak diarahkan ke koreksi absensi pengelola', function (): void {
    [$k, , $alamat] = SiapkanAbsensiWeb($this);
    $masuk = KirimanAbsenWebUji();
    $this->postJson("{$alamat}/masuk", $masuk)->assertOk();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $k['Outlet']->forceFill(['Lintang' => null, 'Bujur' => null])->save();
    AturanKehadiran::query()->create(['TolakDiLuarRadius' => true]);
    $this->postJson("{$alamat}/keluar", KirimanAbsenWebUji(['Uuid' => $masuk['Uuid']]))
        ->assertStatus(422)
        ->assertJsonPath('Galat.Kode', 'OutletTanpaLokasi')
        ->assertJsonPath('Galat.Pesan', fn (string $pesan): bool => str_contains($pesan, 'koreksi absensi'));

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    AturanKehadiran::query()->update(['TolakDiLuarRadius' => false]);
    $this->postJson("{$alamat}/keluar", KirimanAbsenWebUji(['Uuid' => $masuk['Uuid']]))->assertOk()->assertJsonPath('PerluDicek', true);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Absensi::query()->sole()->KeluarPada)->not->toBeNull();
});

it('kalibrasi wajah: sebaran diterima & ditolak per kelompok 0,05, ringkasan, hanya karyawan.kelola', function (): void {
    [$k, , $alamat] = SiapkanAbsensiWeb($this);
    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji(['SidikWajah' => SidikWajahUji(5)]))->assertStatus(422);
    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji())->assertOk();

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $hasil = $this->getJson('/kelola/karyawan/absensi/kalibrasi-wajah')->assertOk()
        ->assertJsonPath('Ambang', '0.70')
        ->assertJsonPath('JumlahDiterima', 1)
        ->assertJsonPath('JumlahDitolak', 1)
        ->assertJsonPath('PersenDitolak', '50.0')
        ->assertJsonPath('TerendahDiterima', '1.00')
        ->assertJsonPath('CukupData', false)
        ->assertJsonCount(15, 'Kelompok');
    $kelompok = collect($hasil->json('Kelompok'));
    expect($kelompok->sum('Diterima'))->toBe(1)
        ->and($kelompok->sum('Ditolak'))->toBe(1)
        ->and($kelompok->last())->toMatchArray(['Dari' => '0.95', 'Sampai' => '1.00', 'Diterima' => 1]);

    $supervisor = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Supervisor);
    BantuanOrganisasi::Masuk($this, $supervisor, $k['Tenant']->Id);
    $this->getJson('/kelola/karyawan/absensi/kalibrasi-wajah')->assertForbidden();
});

it('minta koreksi: karyawan mengirim alasan, kirim ulang tidak menumpuk, alasan divalidasi, hanya yang wajib absen', function (): void {
    [$k, $karyawan, $alamat, $akun] = SiapkanAbsensiWeb($this);

    $this->postJson("{$alamat}/minta-koreksi", ['Jenis' => 'Masuk', 'Alasan' => 'abc'])->assertStatus(422);
    $this->postJson("{$alamat}/minta-koreksi", ['Jenis' => 'Lembur', 'Alasan' => 'Kamera depan rusak'])->assertStatus(422);
    $uuid = $this->postJson("{$alamat}/minta-koreksi", ['Jenis' => 'Masuk', 'Alasan' => 'Kamera depan rusak'])->assertCreated()->assertJsonPath('Status', 'Menunggu')->json('Uuid');
    // Kirim ulang untuk jenis yang sama mengembalikan permintaan yang sama.
    $this->postJson("{$alamat}/minta-koreksi", ['Jenis' => 'Masuk', 'Alasan' => 'Kamera depan rusak lagi'])->assertCreated()->assertJsonPath('Uuid', $uuid);
    $this->postJson("{$alamat}/minta-koreksi", ['Jenis' => 'Keluar', 'Alasan' => 'Wajah tidak dikenali'])->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(PermintaanKoreksiAbsensi::query()->count())->toBe(2)
        ->and(LogAudit::query()->where('Peristiwa', 'absensi.koreksi.diminta')->count())->toBe(2);

    // Karyawan yang tidak ikut absensi tidak punya halaman maupun jalur permintaan.
    $karyawan->forceFill(['IkutAbsensi' => false])->save();
    $this->postJson("{$alamat}/minta-koreksi", ['Jenis' => 'Masuk', 'Alasan' => 'Kamera depan rusak'])->assertNotFound();
});

it('pengelola: permintaan koreksi tampil di rekap & Kotak Tindakan, absensi manual menutupnya, tutup/tolak idempoten dan tidak bisa dibalik', function (): void {
    [$k, $karyawan, $alamat] = SiapkanAbsensiWeb($this);
    $this->postJson("{$alamat}/minta-koreksi", ['Jenis' => 'Masuk', 'Alasan' => 'Kamera depan rusak'])->assertCreated();
    $this->postJson("{$alamat}/minta-koreksi", ['Jenis' => 'Keluar', 'Alasan' => 'Wajah tidak dikenali'])->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $masuk = PermintaanKoreksiAbsensi::query()->where('Jenis', 'Masuk')->sole();
    $keluar = PermintaanKoreksiAbsensi::query()->where('Jenis', 'Keluar')->sole();

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->get('/kelola/karyawan/absensi')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->has('PermintaanKoreksi', 2)
        ->where('PermintaanKoreksi.0.NamaKaryawan', 'Rina Wulandari'));
    $this->get('/kelola/tindakan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Butir', fn ($butir) => collect($butir)->contains(fn ($b) => $b['Kunci'] === 'karyawan.permintaan-koreksi' && $b['Jumlah'] === 2)));

    // Mencatat absensi manual dari permintaan menutupnya sebagai Selesai.
    $this->post('/kelola/karyawan/absensi', ['Karyawan' => $karyawan->Uuid, 'Outlet' => $k['Outlet']->Uuid, 'Tanggal' => '2026-10-05', 'JamMasuk' => '08:00', 'Alasan' => 'Kamera rusak, dicek manual', 'UuidPermintaan' => $masuk->Uuid])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($masuk->refresh()->Status->value)->toBe('Selesai')->and($masuk->DiprosesOleh)->toBe($k['Pemilik']->Id);

    // Ditolak dengan sekali ketuk; mengulang status yang sama aman, membaliknya ditolak.
    $this->post("/kelola/karyawan/absensi/permintaan-koreksi/{$keluar->Uuid}/tutup", ['Status' => 'Ditolak'])->assertSessionHasNoErrors();
    $this->post("/kelola/karyawan/absensi/permintaan-koreksi/{$keluar->Uuid}/tutup", ['Status' => 'Ditolak'])->assertSessionHasNoErrors();
    $this->post("/kelola/karyawan/absensi/permintaan-koreksi/{$keluar->Uuid}/tutup", ['Status' => 'Selesai'])->assertSessionHasErrors();
    $this->post("/kelola/karyawan/absensi/permintaan-koreksi/{$keluar->Uuid}/tutup", ['Status' => 'Menunggu'])->assertSessionHasErrors('Status');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($keluar->refresh()->Status->value)->toBe('Ditolak')
        ->and(LogAudit::query()->where('Peristiwa', 'absensi.koreksi.ditutup')->count())->toBe(2);

    $this->get('/kelola/karyawan/absensi')->assertInertia(fn (AssertableInertia $h) => $h->has('PermintaanKoreksi', 0));

    // Pemegang karyawan.lihat saja (Supervisor) tidak melihat daftar permintaan.
    $supervisor = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Supervisor);
    BantuanOrganisasi::Masuk($this, $supervisor, $k['Tenant']->Id);
    $this->post("/kelola/karyawan/absensi/permintaan-koreksi/{$keluar->Uuid}/tutup", ['Status' => 'Selesai'])->assertForbidden();
});

it('absen yang perlu dicek: terlihat di rekap & Kotak Tindakan, bisa difilter, ditandai sudah dicek (idempoten, teraudit)', function (): void {
    [$k, , $alamat] = SiapkanAbsensiWeb($this);
    // Di luar radius: tercatat tetapi ditandai.
    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji(['Bujur' => '110.8330000']))->assertOk()->assertJsonPath('PerluDicek', true);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $a = Absensi::query()->sole();

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->get('/kelola/tindakan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Butir', fn ($butir) => collect($butir)->contains(fn ($b) => $b['Kunci'] === 'karyawan.absen-perlu-dicek' && $b['Jumlah'] === 1)));
    $this->getJson('/kelola/karyawan/absensi?saring[PerluDicek]=1')->assertOk()->assertJsonPath('Meta.Total', 1)->assertJsonPath('Data.0.PerluDicek', true);

    $this->post("/kelola/karyawan/absensi/{$a->Uuid}/dicek")->assertSessionHasNoErrors();
    $this->post("/kelola/karyawan/absensi/{$a->Uuid}/dicek")->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($a->refresh()->PerluDicek)->toBeFalse()->and($a->CatatanPeriksa)->toContain('Di luar radius')
        ->and(LogAudit::query()->where('Peristiwa', 'absensi.dicek')->count())->toBe(1);
    $this->getJson('/kelola/karyawan/absensi?saring[PerluDicek]=1')->assertJsonPath('Meta.Total', 0);
});

it('aturan kehadiran: sakelar simpan foto bukti dan tolak-di-luar-radius tersimpan dan terbaca kembali', function (): void {
    [$k] = SiapkanAbsensiWeb($this);
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

    $this->get('/kelola/karyawan/aturan-kehadiran')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Aturan.SimpanFotoBukti', true)->where('Aturan.TolakDiLuarRadius', false));
    $this->put('/kelola/karyawan/aturan-kehadiran', ['WajibJadwal' => false, 'MasukPalingAwalMenit' => 60, 'ToleransiTerlambatMenit' => 10, 'ToleransiPulangCepatMenit' => 10, 'LemburSetelahMenit' => 30, 'PengingatShiftAktif' => false, 'PengingatShiftMenitSebelum' => 60, 'PeringatanPengelolaAktif' => false, 'PeringatanPengelolaSetelahMenit' => 30, 'SimpanFotoBukti' => false, 'TolakDiLuarRadius' => true])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $aturan = AturanKehadiran::query()->sole();
    expect($aturan->SimpanFotoBukti)->toBeFalse()->and($aturan->TolakDiLuarRadius)->toBeTrue();

    // Tidak dikirim = nilai tersimpan tidak berubah.
    $this->put('/kelola/karyawan/aturan-kehadiran', ['WajibJadwal' => false, 'MasukPalingAwalMenit' => 60, 'ToleransiTerlambatMenit' => 15, 'ToleransiPulangCepatMenit' => 10, 'LemburSetelahMenit' => 30, 'PengingatShiftAktif' => false, 'PengingatShiftMenitSebelum' => 60, 'PeringatanPengelolaAktif' => false, 'PeringatanPengelolaSetelahMenit' => 30])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($aturan->refresh()->SimpanFotoBukti)->toBeFalse()->and($aturan->TolakDiLuarRadius)->toBeTrue()->and($aturan->ToleransiTerlambatMenit)->toBe(15);
});

describe('D-44 jadwal kerja ↔ absensi web', function (): void {
    it('tanpa aturan WajibJadwal (bawaan) absen tanpa jadwal tetap diterima; dengan aturan ditolak tanpa baris dan tercatat di audit', function (): void {
        [$k, $karyawan, $alamat] = SiapkanAbsensiWeb($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        AturanKehadiran::query()->create(['WajibJadwal' => true]);

        $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji())->assertStatus(422)->assertJsonPath('Galat.Kode', 'TidakAdaJadwal');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Absensi::query()->count())->toBe(0)
            ->and(LogAudit::query()->where('Peristiwa', 'absensi.web.ditolak-jadwal')->count())->toBe(1);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        AturanKehadiran::query()->update(['WajibJadwal' => false]);
        $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji())->assertOk();
        expect($karyawan->refresh()->Nama)->toBe('Rina Wulandari');
    });

    it('jadwal membuka jendela masuk: terlalu awal dan shift sudah berakhir ditolak, di dalam jendela diterima', function (): void {
        [$k, $karyawan, $alamat] = SiapkanAbsensiWeb($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        AturanKehadiran::query()->create(['WajibJadwal' => true, 'MasukPalingAwalMenit' => 60]);

        // Sekarang 08.00. Shift 10.00–18.00 baru dibuka 09.00.
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        JadwalKerja::query()->create(['IdKaryawan' => $karyawan->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-10-05', 'JamMulai' => '10:00', 'JamSelesai' => '18:00']);
        $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji())->assertStatus(422)->assertJsonPath('Galat.Kode', 'TerlaluAwal');

        // Shift 00.00–07.00 sudah berakhir.
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        JadwalKerja::query()->where('IdKaryawan', $karyawan->Id)->update(['JamMulai' => '00:00', 'JamSelesai' => '07:00']);
        $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji())->assertStatus(422)->assertJsonPath('Galat.Kode', 'ShiftSudahBerakhir');

        // Shift 09.00–17.00: jendela buka 08.00 → diterima tepat 08.00.
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        JadwalKerja::query()->where('IdKaryawan', $karyawan->Id)->update(['JamMulai' => '09:00', 'JamSelesai' => '17:00']);
        $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji())->assertOk();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Absensi::query()->count())->toBe(1);
    });

    it('jadwal di outlet lain tidak membolehkan absen di outlet ini; shift malam kemarin tetap sah di pagi hari', function (): void {
        [$k, $karyawan, $alamat] = SiapkanAbsensiWeb($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        AturanKehadiran::query()->create(['WajibJadwal' => true]);
        $b = BantuanJurnal::BuatOutlet('PSR', 'Cabang Pasar');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        JadwalKerja::query()->create(['IdKaryawan' => $karyawan->Id, 'IdOutlet' => $b->Id, 'Tanggal' => '2026-10-05', 'JamMulai' => '08:00', 'JamSelesai' => '16:00']);

        $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji())->assertStatus(422)->assertJsonPath('Galat.Kode', 'JadwalDiOutletLain');

        // Shift malam 22.00–09.00 yang dimulai kemarin masih berlaku pukul 08.00 di outlet ini.
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        JadwalKerja::query()->create(['IdKaryawan' => $karyawan->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-10-04', 'JamMulai' => '22:00', 'JamSelesai' => '09:00']);
        $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji())->assertOk();
    });
});

/*
 * D-198: tautan absen pribadi yang bisa dibagikan, jalan masuk kedua di samping login. Aksi dan aturannya sama
 * (wajah dicocokkan, lokasi menandai perlu-dicek); bedanya hanya cara karyawan dikenali.
 */

/**
 * Karyawan tanpa akun dashboard (staf yang hanya diberi tautan) di tenant uji dengan satu outlet ber-lokasi.
 *
 * @return array{0: array<string, mixed>, 1: Karyawan, 2: string, 3: string} [konteks, karyawan, alamat tautan, token]
 */
function SiapkanAbsenTautan(TestCase $tes): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Kedai Absen Tautan');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $k['Outlet']->forceFill(['Lintang' => '-7.5560000', 'Bujur' => '110.8310000', 'RadiusAbsensiMeter' => 100])->save();
    $karyawan = Karyawan::query()->create(['Nama' => 'Budi Tanpa Akun', 'IdOutlet' => $k['Outlet']->Id, 'IkutAbsensi' => false]);
    $token = app(AturTautanAbsen::class)->Jalankan($karyawan, true, $k['Pemilik']->Id);
    $slug = app(ProfilTenant::class)->AmbilSlug($k['Tenant']->Id);

    return [$k, $karyawan->refresh(), "/{$slug}/absen/{$token}", (string) $token];
}

it('D-198 pengelola membuat tautan: karyawan ikut absensi otomatis, tautan tampil di panel, buat ulang mematikan yang lama, cabut menghapusnya', function (): void {
    $k = BantuanPenjualan::Siapkan($this, 'Kedai Tautan Pengelola');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $karyawan = Karyawan::query()->create(['Nama' => 'Budi Tanpa Akun', 'IdOutlet' => $k['Outlet']->Id, 'IkutAbsensi' => false]);
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

    $this->post("/kelola/karyawan/{$karyawan->Uuid}/tautan-absen")->assertRedirect();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $karyawan->refresh();
    $lama = $this->getJson("/kelola/karyawan/{$karyawan->Uuid}/absen-hp")->assertOk()->json('Tautan');
    expect($karyawan->IkutAbsensi)->toBeTrue()
        ->and($karyawan->HashTokenAbsen)->toBe(AturTautanAbsen::Hash((string) $karyawan->TokenAbsen))
        ->and($lama)->toBe(url('/'.app(ProfilTenant::class)->AmbilSlug($k['Tenant']->Id).'/absen/'.$karyawan->TokenAbsen))
        ->and(LogAudit::query()->where('Peristiwa', 'karyawan.tautan-absen.buat')->exists())->toBeTrue();
    // Token mentah tidak pernah ikut ke log audit.
    expect(json_encode(LogAudit::query()->where('Peristiwa', 'karyawan.tautan-absen.buat')->first()?->NilaiBaru))->not->toContain((string) $karyawan->TokenAbsen);

    $this->post("/kelola/karyawan/{$karyawan->Uuid}/tautan-absen")->assertRedirect();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $baru = $this->getJson("/kelola/karyawan/{$karyawan->Uuid}/absen-hp")->json('Tautan');
    expect($baru)->not->toBe($lama);

    $this->delete("/kelola/karyawan/{$karyawan->Uuid}/tautan-absen")->assertRedirect();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($this->getJson("/kelola/karyawan/{$karyawan->Uuid}/absen-hp")->json('Tautan'))->toBeNull()
        ->and($karyawan->refresh()->HashTokenAbsen)->toBeNull();
});

it('D-198 karyawan nonaktif tidak bisa diberi tautan; tanpa izin karyawan.kelola ditolak', function (): void {
    $k = BantuanPenjualan::Siapkan($this, 'Kedai Tautan Nonaktif');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $karyawan = Karyawan::query()->create(['Nama' => 'Budi Tanpa Akun', 'IdOutlet' => $k['Outlet']->Id, 'Status' => StatusKaryawan::Nonaktif->value]);
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

    $this->post("/kelola/karyawan/{$karyawan->Uuid}/tautan-absen")->assertSessionHasErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($karyawan->refresh()->HashTokenAbsen)->toBeNull();

    $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
    BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id);
    $this->post("/kelola/karyawan/{$karyawan->Uuid}/tautan-absen")->assertForbidden();
});

it('D-198 staf tanpa akun absen lewat tautan: halaman, daftar wajah, masuk, lalu keluar', function (): void {
    [$k, $karyawan, $alamat] = SiapkanAbsenTautan($this);

    $this->get($alamat)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->component('Publik/Absensi')
        ->where('NamaKaryawan', 'Budi Tanpa Akun')
        ->where('Berlogin', false)
        ->where('BisaDashboard', false)
        ->where('AlamatDasar', url($alamat))
        ->where('Wajah', null)
        ->missing('SidikWajah'));

    $this->postJson("{$alamat}/wajah", ['SidikWajah' => [SidikWajahUji(), SidikWajahUji(), SidikWajahUji()], 'Foto' => [SwafotoAbsenWebUji(), SwafotoAbsenWebUji(), SwafotoAbsenWebUji()], 'Persetujuan' => true])->assertCreated();
    $this->postJson("{$alamat}/cek-wajah", ['SidikWajah' => SidikWajahUji()])->assertOk()->assertJsonPath('Cocok', true);
    $this->postJson("{$alamat}/cek-wajah", ['SidikWajah' => SidikWajahUji(5)])->assertOk()->assertJsonPath('Cocok', false);

    $masuk = $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji())->assertOk()->assertJsonPath('PerluDicek', false)->json();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Absensi::query()->where('IdKaryawan', $karyawan->Id)->whereNull('KeluarPada')->count())->toBe(1);

    $this->travel(8)->hours();
    $this->postJson("{$alamat}/keluar", KirimanAbsenWebUji(['Uuid' => $masuk['Uuid']]))->assertOk();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Absensi::query()->where('IdKaryawan', $karyawan->Id)->whereNotNull('KeluarPada')->count())->toBe(1);
});

it('D-198 wajah yang tidak cocok tetap ditolak lewat tautan, dan permintaan koreksi ke manajer berjalan', function (): void {
    [$k, $karyawan, $alamat] = SiapkanAbsenTautan($this);
    $this->postJson("{$alamat}/wajah", ['SidikWajah' => [SidikWajahUji(), SidikWajahUji(), SidikWajahUji()], 'Foto' => [SwafotoAbsenWebUji(), SwafotoAbsenWebUji(), SwafotoAbsenWebUji()], 'Persetujuan' => true])->assertCreated();

    $this->postJson("{$alamat}/masuk", KirimanAbsenWebUji(['SidikWajah' => SidikWajahUji(5)]))->assertStatus(422);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Absensi::query()->where('IdKaryawan', $karyawan->Id)->count())->toBe(0);

    $this->postJson("{$alamat}/minta-koreksi", ['Jenis' => 'Masuk', 'Alasan' => 'Kamera depan rusak'])->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(PermintaanKoreksiAbsensi::query()->where('IdKaryawan', $karyawan->Id)->count())->toBe(1);
});

it('D-198 tautan salah, dicabut, dibuat ulang, karyawan nonaktif, tidak ikut absensi, atau slug salah = 404 yang sama', function (): void {
    [$k, $karyawan, $alamat, $token] = SiapkanAbsenTautan($this);
    $slug = app(ProfilTenant::class)->AmbilSlug($k['Tenant']->Id);

    $this->get("/{$slug}/absen/".str_repeat('a', 40))->assertNotFound();
    $this->get("/slug-lain-tidak-ada/absen/{$token}")->assertNotFound();
    $this->postJson("/{$slug}/absen/".str_repeat('a', 40).'/masuk', KirimanAbsenWebUji())->assertNotFound();
    $this->get($alamat)->assertOk();

    // Tiap permintaan publik mengosongkan konteks tenant, jadi ditetapkan lagi sebelum menyentuh data.
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $karyawan->forceFill(['IkutAbsensi' => false])->save();
    $this->get($alamat)->assertNotFound();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $karyawan->forceFill(['IkutAbsensi' => true])->save();
    $this->get($alamat)->assertOk();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    app(UbahStatusKaryawan::class)->Jalankan($karyawan, StatusKaryawan::Nonaktif, $k['Pemilik']->Id);
    $this->get($alamat)->assertNotFound();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $karyawan->forceFill(['Status' => StatusKaryawan::Aktif->value])->save();

    app(AturTautanAbsen::class)->Jalankan($karyawan->refresh(), true, $k['Pemilik']->Id);
    $this->get($alamat)->assertNotFound();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    app(AturTautanAbsen::class)->Jalankan($karyawan->refresh(), false, $k['Pemilik']->Id);
    expect($karyawan->refresh()->TokenAbsen)->toBeNull();
});

it('D-198 tautan karyawan tenant lain tidak membuka karyawan ini: token hanya berlaku di slug tenantnya', function (): void {
    [, , , $token] = SiapkanAbsenTautan($this);
    $lain = BantuanPenjualan::Siapkan($this, 'Kedai Tetangga');
    $slugLain = app(ProfilTenant::class)->AmbilSlug($lain['Tenant']->Id);

    $this->get("/{$slugLain}/absen/{$token}")->assertNotFound();
});

it('D-198 karyawan yang punya akun login juga bisa absen lewat tautan, dan catatannya sama dengan absen berlogin', function (): void {
    [$k, $karyawan, $alamatLogin, $akun] = SiapkanAbsensiWeb($this);
    $token = app(AturTautanAbsen::class)->Jalankan($karyawan, true, $k['Pemilik']->Id);
    $alamatTautan = '/'.app(ProfilTenant::class)->AmbilSlug($k['Tenant']->Id)."/absen/{$token}";

    // Rute tautan tidak memakai perantara autentikasi (dibuktikan juga oleh tes staf tanpa akun di atas): siapa pun yang
    // memegang tautan, berakun atau tidak, dikenali dari tokennya.
    $this->get($alamatTautan)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->component('Publik/Absensi')
        ->where('NamaKaryawan', 'Rina Wulandari')
        ->where('Berlogin', false)
        ->where('Wajah.Status', 'Disetujui'));
    $this->postJson("{$alamatTautan}/masuk", KirimanAbsenWebUji())->assertOk();

    // Sesudah login, halaman berlogin melihat absen yang sama (satu karyawan, satu riwayat).
    BantuanOrganisasi::Masuk($this, $akun, $k['Tenant']->Id);
    $this->get($alamatLogin)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('AbsensiTerbuka', fn ($terbuka) => $terbuka !== null));
});
