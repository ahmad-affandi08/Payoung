<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\AturanKehadiran;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\TenantPengguna;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-18 bagian 1 (PRD "Rincian F-18 bagian 1"): data karyawan (akun tertaut unik, gaji hanya untuk pengelola), jadwal
 * kerja mingguan per outlet (simpan, hapus, bentrok outlet lain, salin minggu lalu), absensi dari POS lewat outbox
 * `Absensi.Masuk`/`Absensi.Keluar` (idempoten, karyawan dibuat otomatis, swafoto JPEG privat), rekap absensi dengan
 * keterlambatan, izin & isolasi tenant.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

/** JPEG minimal (tanda tangan FFD8FF) untuk uji swafoto. */
function SwafotoUji(): string
{
    return base64_encode("\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");
}

/**
 * @param  array<string, mixed>  $data
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemAbsensi(string $jenis, array $data, ?string $uuid = null): array
{
    return ['Jenis' => $jenis, 'Uuid' => $uuid ?? BantuanKasir::Uuid(), 'Data' => $data];
}

/** Waktu hari ini jam [jam]:[menit] zona outlet uji (WIB), dalam UTC ISO. */
function JamHariIni(int $jam, int $menit): string
{
    return CarbonImmutable::now('Asia/Jakarta')->setTime($jam, $menit)->utc()->toIso8601ZuluString();
}

/**
 * @return array<string, mixed>
 */
function SiapkanKaryawan(TestCase $tes, string $nama = 'Kopi Senja Karyawan'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $nama);
    BantuanOrganisasi::Masuk($tes, $k['Pemilik'], $k['Tenant']->Id);

    return $k;
}

describe('F-18 data karyawan', function (): void {
    it('tambah tanpa akun, tautkan akun lalu akun ganda ditolak, ubah, nonaktifkan; gaji hanya untuk karyawan.kelola', function (): void {
        $k = SiapkanKaryawan($this);

        $this->post('/kelola/tim', ['Akses' => 'Tanpa', 'Nama' => 'Rina Wulandari', 'Jabatan' => 'Barista', 'LevelStaf' => 'Senior', 'GajiPokok' => '3500000', 'UuidOutlet' => $k['Outlet']->Uuid])
            ->assertSessionHasNoErrors()->assertRedirect(route('kelola.tim.daftar'));
        $this->post('/kelola/tim', ['Akses' => 'Tanpa', 'Nama' => 'Pak Joko Tukang Parkir', 'GajiPokok' => '1,5'])->assertSessionHasErrors('GajiPokok');
        $this->post('/kelola/tim', ['Akses' => 'Tanpa', 'Nama' => 'Pak Joko Tukang Parkir'])->assertSessionHasNoErrors();

        $rina = Karyawan::query()->where('Nama', 'Rina Wulandari')->sole();
        $joko = Karyawan::query()->where('Nama', 'Pak Joko Tukang Parkir')->sole();
        expect($rina->IdPengguna)->toBeNull()->and((string) $rina->GajiPokok)->toBe('3500000.00')->and($rina->IdOutlet)->toBe($k['Outlet']->Id);

        // Menautkan akun ke karyawan lewat panel ubah; satu akun hanya boleh tertaut ke satu karyawan.
        $this->put("/kelola/karyawan/{$rina->Uuid}", ['Nama' => 'Rina Wulandari', 'Jabatan' => 'Barista', 'GajiPokok' => '3500000', 'UuidPengguna' => $k['Kasir']->Uuid, 'UuidOutlet' => $k['Outlet']->Uuid])->assertSessionHasNoErrors();
        $this->put("/kelola/karyawan/{$joko->Uuid}", ['Nama' => 'Rina Kedua', 'UuidPengguna' => $k['Kasir']->Uuid])->assertSessionHasErrors('UuidPengguna');
        expect($rina->refresh()->IdPengguna)->toBe($k['Kasir']->Id);

        $this->put("/kelola/karyawan/{$rina->Uuid}", ['Nama' => 'Rina Wulandari', 'Jabatan' => 'Kepala Barista', 'UuidPengguna' => $k['Kasir']->Uuid])->assertSessionHasNoErrors();
        expect($rina->refresh()->Jabatan)->toBe('Kepala Barista')->and($rina->GajiPokok)->toBeNull();
        $this->post('/kelola/tim/status', ['Aksi' => 'Nonaktifkan', 'UuidKaryawan' => $rina->Uuid])->assertSessionHasNoErrors();
        expect($rina->refresh()->Status)->toBe(StatusKaryawan::Nonaktif)
            ->and(LogAudit::query()->where('Peristiwa', 'karyawan.nonaktifkan')->count())->toBe(1)
            ->and(LogAudit::query()->where('Peristiwa', 'karyawan.tambah')->value('NilaiBaru'))->not->toContain('3500000');

        $baris = fn () => collect($this->get('/kelola/tim')->assertOk()->inertiaProps('Tim'))->first(fn (array $b): bool => ($b['Karyawan']['Uuid'] ?? null) === $rina->Uuid);
        expect($baris()['Karyawan']['Jabatan'])->toBe('Kepala Barista')->and($baris()['Status'])->not->toBe('Aktif');
        $this->post('/kelola/tim/status', ['Aksi' => 'Aktifkan', 'UuidKaryawan' => $rina->Uuid])->assertSessionHasNoErrors();
        $this->put("/kelola/karyawan/{$rina->Uuid}", ['Nama' => 'Rina Wulandari', 'GajiPokok' => '4000000'])->assertSessionHasNoErrors();

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Supervisor);
        $this->get('/kelola/tim')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Tim/Daftar')->where('Izin.KelolaKaryawan', false)->has('OpsiPengguna', 0)
            ->where('Tim', fn ($tim) => collect($tim)->every(fn (array $b): bool => ($b['Karyawan']['GajiPokok'] ?? null) === null)));
        $this->post('/kelola/tim', ['Akses' => 'Tanpa', 'Nama' => 'Tidak Boleh'])->assertForbidden();

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/tim')->assertForbidden();
    });

    it('halaman tambah (halaman penuh): opsi outlet & izin; tanpa izin tambah 403', function (): void {
        $k = SiapkanKaryawan($this);
        $this->get('/kelola/tim/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Tim/Tambah')
            ->where('BolehKaryawan', true)->where('BolehAkun', true)
            ->has('OpsiOutlet.0', fn (AssertableInertia $o) => $o->hasAll(['Uuid', 'Nama']))
            ->where('KaryawanTertaut', null));

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Supervisor);
        $this->get('/kelola/tim/buat')->assertForbidden();
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/tim/buat')->assertForbidden();
    });
});

describe('F-18 jadwal kerja', function (): void {
    it('simpan, ubah, hapus jadwal minggu; jam tidak valid & bentrok outlet lain ditolak; salin minggu lalu', function (): void {
        $k = SiapkanKaryawan($this);
        $this->post('/kelola/tim', ['Akses' => 'Tanpa', 'Nama' => 'Dimas Pratama', 'UuidOutlet' => $k['Outlet']->Uuid])->assertSessionHasNoErrors();
        $dimas = Karyawan::query()->sole();
        $senin = CarbonImmutable::parse('2026-09-21');
        $isian = fn (array $sel): array => ['UuidOutlet' => $k['Outlet']->Uuid, 'Senin' => '2026-09-21', 'Sel' => $sel];

        $this->get('/kelola/karyawan/jadwal?minggu=2026-09-23')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Karyawan/Jadwal')->where('Senin', '2026-09-21')->has('Jadwal.Hari', 7)->has('Jadwal.Baris', 1));

        $this->put('/kelola/karyawan/jadwal', $isian([
            ['UuidKaryawan' => $dimas->Uuid, 'Tanggal' => '2026-09-21', 'JamMulai' => '08:00', 'JamSelesai' => '16:00'],
            ['UuidKaryawan' => $dimas->Uuid, 'Tanggal' => '2026-09-22', 'JamMulai' => '22:00', 'JamSelesai' => '06:00'],
        ]))->assertSessionHasNoErrors();
        expect(JadwalKerja::query()->count())->toBe(2);

        $this->put('/kelola/karyawan/jadwal', $isian([['UuidKaryawan' => $dimas->Uuid, 'Tanggal' => '2026-09-23', 'JamMulai' => '25:00', 'JamSelesai' => '16:00']]))->assertSessionHasErrors('Sel.0.JamMulai');
        $this->put('/kelola/karyawan/jadwal', $isian([['UuidKaryawan' => $dimas->Uuid, 'Tanggal' => '2026-09-30', 'JamMulai' => '08:00', 'JamSelesai' => '16:00']]))->assertSessionHasErrors('Sel.0.Tanggal');
        $this->put('/kelola/karyawan/jadwal', $isian([['UuidKaryawan' => $dimas->Uuid, 'Tanggal' => '2026-09-22', 'JamMulai' => null, 'JamSelesai' => null]]))->assertSessionHasNoErrors();
        expect(JadwalKerja::query()->count())->toBe(1);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $lain = BantuanHarga::BuatOutlet('KRT', 'Cabang Kartasura');
        $this->put('/kelola/karyawan/jadwal', ['UuidOutlet' => $lain->Uuid, 'Senin' => '2026-09-21', 'Sel' => [['UuidKaryawan' => $dimas->Uuid, 'Tanggal' => '2026-09-21', 'JamMulai' => '09:00', 'JamSelesai' => '17:00']]])
            ->assertSessionHasErrors('Sel.0.JamMulai');

        $this->post('/kelola/karyawan/jadwal/salin', ['UuidOutlet' => $k['Outlet']->Uuid, 'Senin' => $senin->addDays(7)->toDateString()])->assertSessionHasNoErrors();
        $baru = JadwalKerja::query()->whereDate('Tanggal', '2026-09-28')->sole();
        expect([$baru->JamMulai, $baru->JamSelesai])->toBe(['08:00', '16:00'])
            ->and(LogAudit::query()->where('Peristiwa', 'jadwal-kerja.salin')->count())->toBe(1);
    });
});

describe('F-18 absensi dari POS', function (): void {
    it('masuk & keluar idempoten dengan swafoto; karyawan dibuat otomatis; rekap menghitung terlambat', function (): void {
        $k = SiapkanKaryawan($this);
        $uuid = BantuanKasir::Uuid();
        $masuk = ItemAbsensi('Absensi.Masuk', ['UuidPengguna' => $k['Kasir']->Uuid, 'MasukPada' => JamHariIni(9, 40), 'Swafoto' => SwafotoUji()], $uuid);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$masuk]))->toBe([['Diterima', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$masuk]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $karyawan = Karyawan::query()->sole();
        $absensi = Absensi::query()->sole();
        expect($karyawan->IdPengguna)->toBe($k['Kasir']->Id)
            ->and($absensi->Uuid)->toBe($uuid)
            ->and($absensi->PathSwafotoMasuk)->not->toBeNull()
            ->and(Storage::disk('local')->exists((string) $absensi->PathSwafotoMasuk))->toBeTrue();

        $keluarAwal = ItemAbsensi('Absensi.Keluar', ['UuidAbsensi' => $uuid, 'UuidPengguna' => $k['Kasir']->Uuid, 'KeluarPada' => JamHariIni(9, 0)]);
        $keluar = ItemAbsensi('Absensi.Keluar', ['UuidAbsensi' => $uuid, 'UuidPengguna' => $k['Kasir']->Uuid, 'KeluarPada' => JamHariIni(17, 5), 'Swafoto' => null]);
        $keluarLain = ItemAbsensi('Absensi.Keluar', ['UuidAbsensi' => $uuid, 'UuidPengguna' => $k['Supervisor']->Uuid, 'KeluarPada' => JamHariIni(17, 5)]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$keluarAwal, $keluarLain, $keluar]))
            ->toBe([['Ditolak', 'WaktuTidakValid'], ['Ditolak', 'AbsensiTidakDitemukan'], ['Diterima', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$keluar]))->toBe([['Duplikat', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $tanggal = $absensi->refresh()->TanggalBisnis->toDateString();
        JadwalKerja::query()->create(['IdKaryawan' => $karyawan->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => $tanggal, 'JamMulai' => '09:00', 'JamSelesai' => '17:00']);

        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $this->getJson('/kelola/karyawan/absensi?cari=')->assertOk()
            ->assertJsonPath('Meta.Total', 1)
            ->assertJsonPath('Data.0.JamMasuk', '09:40')
            ->assertJsonPath('Data.0.JamKeluar', '17:05')
            ->assertJsonPath('Data.0.DurasiMenit', 445)
            ->assertJsonPath('Data.0.TerlambatMenit', 40)
            ->assertJsonPath('Data.0.Status', 'Terlambat')
            ->assertJsonPath('Data.0.AdaSwafotoMasuk', true)
            ->assertJsonPath('Data.0.AdaSwafotoKeluar', false);
        $this->get("/kelola/karyawan/absensi/{$uuid}/swafoto/masuk")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get("/kelola/karyawan/absensi/{$uuid}/swafoto/keluar")->assertNotFound();

        $b = BantuanPenjualan::Siapkan($this, 'Tenant Lain Absensi');
        BantuanOrganisasi::Masuk($this, $b['Pemilik'], $b['Tenant']->Id);
        $this->get("/kelola/karyawan/absensi/{$uuid}/swafoto/masuk")->assertNotFound();
        $this->getJson('/kelola/karyawan/absensi?cari=')->assertOk()->assertJsonPath('Meta.Total', 0);
    });

    it('kiriman ulang absen masuk & keluar setelah akses pengguna dicabut tetap Duplikat, bukan macet KasirTidakDitemukan', function (): void {
        $k = SiapkanKaryawan($this);
        $uuid = BantuanKasir::Uuid();
        $masuk = ItemAbsensi('Absensi.Masuk', ['UuidPengguna' => $k['Kasir']->Uuid, 'MasukPada' => JamHariIni(8, 0)], $uuid);
        $keluar = ItemAbsensi('Absensi.Keluar', ['UuidAbsensi' => $uuid, 'UuidPengguna' => $k['Kasir']->Uuid, 'KeluarPada' => JamHariIni(16, 0)]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$masuk, $keluar]))->toBe([['Diterima', null], ['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        TenantPengguna::query()->where('IdTenant', $k['Tenant']->Id)->where('IdPengguna', $k['Kasir']->Id)->update(['Status' => StatusKeanggotaan::Nonaktif->value]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$masuk, $keluar]))->toBe([['Duplikat', null], ['Duplikat', null]])
            // Absen baru dari pengguna yang sudah dicabut tetap ditolak.
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemAbsensi('Absensi.Masuk', ['UuidPengguna' => $k['Kasir']->Uuid, 'MasukPada' => JamHariIni(8, 5)])]))->toBe([['Ditolak', 'KasirTidakDitemukan']]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Absensi::query()->count())->toBe(1);
    });

    it('swafoto bukan JPEG, pengguna di luar outlet, dan karyawan nonaktif ditolak', function (): void {
        $k = SiapkanKaryawan($this);
        $png = base64_encode("\x89PNG\r\n\x1a\n0000");

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            ItemAbsensi('Absensi.Masuk', ['UuidPengguna' => $k['Kasir']->Uuid, 'MasukPada' => JamHariIni(8, 0), 'Swafoto' => $png]),
            ItemAbsensi('Absensi.Masuk', ['UuidPengguna' => BantuanKasir::Uuid(), 'MasukPada' => JamHariIni(8, 0)]),
        ]))->toBe([['Ditolak', 'SwafotoTidakValid'], ['Ditolak', 'KasirTidakDitemukan']]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Absensi::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
        Karyawan::query()->create(['Nama' => 'Supervisor Nonaktif', 'IdPengguna' => $k['Supervisor']->Id, 'Status' => StatusKaryawan::Nonaktif]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            ItemAbsensi('Absensi.Masuk', ['UuidPengguna' => $k['Supervisor']->Uuid, 'MasukPada' => JamHariIni(8, 0), 'Swafoto' => SwafotoUji()]),
        ]))->toBe([['Ditolak', 'KaryawanNonaktif']]);
        expect(Storage::disk('local')->allFiles())->toBe([]);
    });
});

describe('D-44 absen POS vs jadwal', function (): void {
    it('dengan WajibJadwal absen POS tetap diterima (kasir bisa offline) tetapi tanpa jadwal ditandai Di luar jadwal dan muncul di Kotak Tindakan', function (): void {
        $k = SiapkanKaryawan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        AturanKehadiran::query()->create(['WajibJadwal' => true]);
        $uuid = BantuanKasir::Uuid();
        $masuk = ItemAbsensi('Absensi.Masuk', ['UuidPengguna' => $k['Kasir']->Uuid, 'MasukPada' => JamHariIni(8, 0)], $uuid);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$masuk]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Absensi::query()->where('Uuid', $uuid)->sole()->DiluarJadwal)->toBeTrue();

        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $butir = null;
        $this->get('/kelola/tindakan')->assertOk()->assertInertia(function (AssertableInertia $h) use (&$butir) {
            $butir = collect($h->toArray()['props']['Butir'])->firstWhere('Kunci', 'karyawan.absen-diluar-jadwal');
        });
        expect($butir)->not->toBeNull()->and($butir['Jumlah'])->toBe(1);
        $daftar = $this->getJson('/kelola/karyawan/absensi?saring[DiluarJadwal]=1')->assertOk();
        expect($daftar->json('Meta.Total'))->toBe(1)->and($daftar->json('Data.0.DiluarJadwal'))->toBeTrue();
    });

    it('sesuai jadwal tidak ditandai; tanpa aturan WajibJadwal tidak pernah ditandai', function (): void {
        $k = SiapkanKaryawan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $hariIni = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        $uuidA = BantuanKasir::Uuid();
        $uuidB = BantuanKasir::Uuid();

        // Tanpa aturan: tidak ditandai walau tanpa jadwal.
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemAbsensi('Absensi.Masuk', ['UuidPengguna' => $k['Kasir']->Uuid, 'MasukPada' => JamHariIni(8, 0)], $uuidA)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Absensi::query()->where('Uuid', $uuidA)->sole()->DiluarJadwal)->toBeFalse();

        $karyawan = Karyawan::query()->sole();
        Absensi::query()->delete();
        AturanKehadiran::query()->create(['WajibJadwal' => true]);
        JadwalKerja::query()->create(['IdKaryawan' => $karyawan->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => $hariIni, 'JamMulai' => '07:00', 'JamSelesai' => '23:00']);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemAbsensi('Absensi.Masuk', ['UuidPengguna' => $k['Kasir']->Uuid, 'MasukPada' => JamHariIni(8, 0)], $uuidB)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Absensi::query()->where('Uuid', $uuidB)->sole()->DiluarJadwal)->toBeFalse();
    });
});
