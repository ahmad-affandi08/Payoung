<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Aksi\CatatAbsensiWeb;
use App\Domain\Karyawan\Data\DataAbsensiWeb;
use App\Domain\Karyawan\Kueri\RekapKehadiranPeriode;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Pengguna;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-18 | D-180: "Ikut absensi" per karyawan. Absen lewat login beremail; manajemen (pemilik, admin, akuntan, manajer
 * outlet, supervisor) bawaannya tidak ikut dan tidak pernah dianggap terlambat atau alpa.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

/**
 * @param  array<string, mixed>  $isian
 * @return array<string, mixed>
 */
function IsianStafDashboard(int $idTenant, PeranTenantBawaan $peran, string $nama, array $isian = []): array
{
    return [
        'Nama' => $nama,
        'Akses' => 'Dashboard',
        'Email' => strtolower(str_replace(' ', '.', $nama)).'@gmail.com',
        'KataSandi' => 'Rahasia123',
        'Peran' => BantuanOrganisasi::Peran($idTenant, $peran)->Uuid,
        'SemuaOutlet' => true,
        'Outlet' => [],
        'JugaKaryawan' => true,
        ...$isian,
    ];
}

function KaryawanTim(string $nama): Karyawan
{
    return Karyawan::query()->where('Nama', $nama)->sole();
}

describe('D-180 isian awal "Ikut absensi" menurut peran', function (): void {
    it('Kasir beremail ikut absensi; Manajer Outlet dan Supervisor tidak; bisa ditimpa pemilik toko', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $masuk = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $masuk->post('/kelola/tim', IsianStafDashboard($tenant->Id, PeranTenantBawaan::Kasir, 'Sari Kasir'))->assertSessionHasNoErrors();
        $masuk->post('/kelola/tim', IsianStafDashboard($tenant->Id, PeranTenantBawaan::ManajerOutlet, 'Mario Manajer'))->assertSessionHasNoErrors();
        $masuk->post('/kelola/tim', IsianStafDashboard($tenant->Id, PeranTenantBawaan::Supervisor, 'Susi Supervisor'))->assertSessionHasNoErrors();
        $masuk->post('/kelola/tim', IsianStafDashboard($tenant->Id, PeranTenantBawaan::Supervisor, 'Sigit Supervisor Absen', ['IkutAbsensi' => true]))->assertSessionHasNoErrors();
        $masuk->post('/kelola/tim', IsianStafDashboard($tenant->Id, PeranTenantBawaan::Kasir, 'Kiki Kasir Bebas', ['IkutAbsensi' => false]))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(KaryawanTim('Sari Kasir')->IkutAbsensi)->toBeTrue()
            ->and(KaryawanTim('Mario Manajer')->IkutAbsensi)->toBeFalse()
            ->and(KaryawanTim('Susi Supervisor')->IkutAbsensi)->toBeFalse()
            ->and(KaryawanTim('Sigit Supervisor Absen')->IkutAbsensi)->toBeTrue()
            ->and(KaryawanTim('Kiki Kasir Bebas')->IkutAbsensi)->toBeFalse();
    });

    it('peran Karyawan (tanpa izin back-office) ikut absensi', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/tim', IsianStafDashboard($tenant->Id, PeranTenantBawaan::Karyawan, 'Wati Karyawan'))
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(KaryawanTim('Wati Karyawan')->IkutAbsensi)->toBeTrue()
            ->and(PeranTenantBawaan::Karyawan->AmbilIzin())->toBe([]);
    });

    it('formulir Tambah mengirim bawaan IkutAbsensi tiap peran supaya isian awalnya benar', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $peran = collect(BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get('/kelola/tim/buat')->assertOk()->inertiaProps('Peran'))->keyBy('Nama');

        expect($peran['Kasir']['IkutAbsensi'])->toBeTrue()
            ->and($peran['Karyawan']['IkutAbsensi'])->toBeTrue()
            ->and($peran['Supervisor']['IkutAbsensi'])->toBeFalse()
            ->and($peran['Manajer Outlet']['IkutAbsensi'])->toBeFalse()
            ->and($peran['Admin']['IkutAbsensi'])->toBeFalse()
            ->and($peran['Pemilik (Owner)']['IkutAbsensi'])->toBeFalse();
    });
});

describe('D-180 ikut absensi butuh akun beremail', function (): void {
    it('Kasir (PIN, tanpa email) dan Tanpa akun tidak pernah ikut absensi, apa pun isiannya', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $masuk = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $masuk->post('/kelola/tim', [
            'Nama' => 'Pin Kasir', 'Akses' => 'Kasir', 'Pin' => '482915', 'IkutAbsensi' => true, 'JugaKaryawan' => true,
            'Peran' => BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Kasir)->Uuid,
            'SemuaOutlet' => false, 'Outlet' => [Outlet::query()->orderBy('Id')->value('Uuid')],
        ])->assertSessionHasNoErrors();
        $masuk->post('/kelola/tim', ['Akses' => 'Tanpa', 'Nama' => 'Tanpa Akun', 'IkutAbsensi' => true])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(KaryawanTim('Pin Kasir')->IkutAbsensi)->toBeFalse()
            ->and(KaryawanTim('Tanpa Akun')->IkutAbsensi)->toBeFalse();
    });

    it('mengubah karyawan tanpa akun beremail menjadi ikut absensi ditolak dengan pesan jelas; dengan akun Gmail diterima', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $masuk = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);
        $masuk->post('/kelola/tim', ['Akses' => 'Tanpa', 'Nama' => 'Tanpa Akun'])->assertSessionHasNoErrors();
        $masuk->post('/kelola/tim', IsianStafDashboard($tenant->Id, PeranTenantBawaan::Supervisor, 'Susi Supervisor'))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $tanpa = KaryawanTim('Tanpa Akun');
        $susi = KaryawanTim('Susi Supervisor');
        $akunSusi = Pengguna::query()->whereKey($susi->IdPengguna)->sole();

        $masuk->put("/kelola/karyawan/{$tanpa->Uuid}", ['Nama' => 'Tanpa Akun', 'IkutAbsensi' => true])
            ->assertSessionHasErrors(['IkutAbsensi' => 'Ikut absensi butuh akun masuk dengan email (Gmail), karena karyawan absen setelah login. Buat akun dashboard untuknya dulu.']);
        $masuk->put("/kelola/karyawan/{$susi->Uuid}", ['Nama' => $akunSusi->Nama, 'UuidPengguna' => $akunSusi->Uuid, 'IkutAbsensi' => true])
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect($tanpa->refresh()->IkutAbsensi)->toBeFalse()->and($susi->refresh()->IkutAbsensi)->toBeTrue();
    });

    it('ubah data karyawan tanpa menyebut IkutAbsensi tidak mengubah nilainya', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $masuk = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);
        $masuk->post('/kelola/tim', IsianStafDashboard($tenant->Id, PeranTenantBawaan::Kasir, 'Sari Kasir'))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $sari = KaryawanTim('Sari Kasir');
        $akun = Pengguna::query()->whereKey($sari->IdPengguna)->sole();

        $masuk->put("/kelola/karyawan/{$sari->Uuid}", ['Nama' => $akun->Nama, 'UuidPengguna' => $akun->Uuid, 'Jabatan' => 'Barista'])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect($sari->refresh()->IkutAbsensi)->toBeTrue()->and($sari->Jabatan)->toBe('Barista');
    });
});

describe('D-180 yang tidak ikut absensi tidak pernah dianggap alpa', function (): void {
    it('rekap kehadiran (dasar potongan gaji) mengabaikan jadwal karyawan yang tidak ikut absensi', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $outlet = Outlet::query()->orderBy('Id')->firstOrFail();
        $ikut = Karyawan::query()->create(['Nama' => 'Ikut Absen', 'IdOutlet' => $outlet->Id]);
        $tidak = Karyawan::query()->create(['Nama' => 'Manajer Bebas', 'IdOutlet' => $outlet->Id, 'IkutAbsensi' => false]);

        foreach ([$ikut, $tidak] as $orang) {
            JadwalKerja::query()->create(['IdKaryawan' => $orang->Id, 'IdOutlet' => $outlet->Id, 'Tanggal' => '2026-10-05', 'JamMulai' => '08:00', 'JamSelesai' => '16:00']);
        }

        $hasil = app(RekapKehadiranPeriode::class)->Hitung('2026-10-01', '2026-10-31', CarbonImmutable::parse('2026-11-01'));

        expect($hasil)->toHaveKey($ikut->Id)->and($hasil[$ikut->Id]['HariTidakMasuk'])->toBe(1)
            ->and($hasil)->not->toHaveKey($tidak->Id);
    });

    it('absen web ditolak untuk karyawan yang tidak ikut absensi', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $manajer = Karyawan::query()->create(['Nama' => 'Manajer Bebas', 'IkutAbsensi' => false]);
        $data = new DataAbsensiWeb('01HZZZZZZZZZZZZZZZZZZZZZZZ', '-7.5666670', '110.8166670', 10, [1, 2], 'x');

        expect(fn () => app(CatatAbsensiWeb::class)->Masuk($manajer, $data))
            ->toThrow(PelanggaranAturanBisnis::class, 'tidak diwajibkan absen');
    });
});
