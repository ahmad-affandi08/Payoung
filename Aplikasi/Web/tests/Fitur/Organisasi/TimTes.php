<?php

declare(strict_types=1);

use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\Peran;
use App\Domain\Organisasi\Model\TenantPengguna;
use Illuminate\Support\Facades\Mail;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-101 (F-02, F-18): halaman Tim — satu daftar & satu formulir untuk akun (pengguna) dan data karyawan. Akun dan
 * karyawan tetap dua entitas; yang diuji di sini adalah penggabungannya, tiga jenis akses saat menambah, dan
 * nonaktif/aktif sekaligus.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

/**
 * @param  array<string, mixed>  $isian
 * @return array<string, mixed>
 */
function IsianTimUji(int $idTenant, array $isian = []): array
{
    BantuanOrganisasi::AturKonteks($idTenant);

    return [
        'Nama' => 'Siti Barista',
        'Akses' => 'Kasir',
        'Pin' => '482915',
        'Peran' => BantuanOrganisasi::Peran($idTenant, PeranTenantBawaan::Kasir)->Uuid,
        'SemuaOutlet' => false,
        'Outlet' => [Outlet::query()->orderBy('Id')->value('Uuid')],
        'JugaKaryawan' => true,
        ...$isian,
    ];
}

describe('D-101 daftar Tim: satu baris per orang', function (): void {
    it('akun yang tertaut ke karyawan jadi satu baris; akun tanpa karyawan dan karyawan tanpa akun jadi baris sendiri', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $masuk = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);
        $masuk->post('/kelola/tim', IsianTimUji($tenant->Id, ['Jabatan' => 'Barista', 'GajiPokok' => '2500000']))->assertSessionHasNoErrors();
        $masuk->post('/kelola/tim', IsianTimUji($tenant->Id, ['Nama' => 'Akuntan Luar', 'Akses' => 'Dashboard', 'Email' => 'akuntan@contoh.id', 'KataSandi' => 'Rahasia123', 'Pin' => '', 'JugaKaryawan' => false]))->assertSessionHasNoErrors();
        $masuk->post('/kelola/tim', ['Akses' => 'Tanpa', 'Nama' => 'Rina Pelayan', 'Jabatan' => 'Pelayan'])->assertSessionHasNoErrors();

        $tim = collect($this->get('/kelola/tim')->assertOk()->assertInertia(fn ($h) => $h->component('Kelola/Tim/Daftar'))->inertiaProps('Tim'));
        $siti = $tim->firstWhere('Nama', 'Siti Barista');
        $akuntan = $tim->firstWhere('Nama', 'Akuntan Luar');
        $rina = $tim->firstWhere('Nama', 'Rina Pelayan');

        // Pemilik + tiga orang baru; Siti tidak muncul dua kali (akun + karyawan).
        expect($tim)->toHaveCount(4)->and($tim->where('Nama', 'Siti Barista'))->toHaveCount(1)
            ->and($siti['UuidPengguna'])->not->toBeNull()->and($siti['Karyawan']['Jabatan'])->toBe('Barista')->and($siti['Karyawan']['GajiPokok'])->toBe('2500000.00')
            ->and($akuntan['UuidPengguna'])->not->toBeNull()->and($akuntan['Karyawan'])->toBeNull()
            ->and($rina['UuidPengguna'])->toBeNull()->and($rina['Karyawan']['Nama'])->toBe('Rina Pelayan')
            ->and($tim->pluck('Status')->unique()->all())->toBe(['Aktif']);
    });

    it('pelihat tanpa izin lihat pengguna (Akuntan) hanya menerima data karyawan: tanpa email, peran, akun, dan undangan', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $masuk = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);
        $masuk->post('/kelola/tim', IsianTimUji($tenant->Id, ['GajiPokok' => '2500000']))->assertSessionHasNoErrors();
        $masuk->post('/kelola/tim', ['Akses' => 'Tanpa', 'Nama' => 'Rina Pelayan'])->assertSessionHasNoErrors();
        $akuntan = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Akuntan);

        $tim = collect(BantuanOrganisasi::Masuk($this, $akuntan, $tenant->Id)->get('/kelola/tim')->assertOk()->inertiaProps('Tim'));

        // Hanya dua karyawan; tidak ada baris akun (Pemilik, Akuntan) dan tidak ada data akses.
        expect($tim->pluck('Nama')->sort()->values()->all())->toBe(['Rina Pelayan', 'Siti Barista'])
            ->and($tim->every(fn (array $b): bool => $b['Email'] === null && $b['UuidPengguna'] === null && $b['NamaPeran'] === null))->toBeTrue()
            // Gaji hanya untuk karyawan.kelola.
            ->and($tim->every(fn (array $b): bool => $b['Karyawan']['GajiPokok'] === null))->toBeTrue();
    });

    it('kasir tanpa izin lihat apa pun tidak bisa membuka Tim', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);

        BantuanOrganisasi::Masuk($this, $kasir, $tenant->Id)->get('/kelola/tim')->assertForbidden();
    });

    it('isolasi tenant: tenant lain tidak bisa mengubah status anggota tenant ini', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/tim', IsianTimUji($tenant->Id))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $siti = Pengguna::query()->where('Nama', 'Siti Barista')->sole();

        ['Tenant' => $lain, 'Pemilik' => $pemilikLain] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::Masuk($this, $pemilikLain, $lain->Id)->post('/kelola/tim/status', ['Aksi' => 'Nonaktifkan', 'UuidPengguna' => $siti->Uuid])->assertNotFound();
    });
});

describe('D-101 tambah anggota tim: tiga jenis akses, satu formulir', function (): void {
    it('Kasir: akun PIN tanpa email + karyawan tertaut berikut gaji, lembur & potongan', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/tim', IsianTimUji($tenant->Id, [
                'Email' => 'diabaikan@contoh.id', 'KataSandi' => 'Diabaikan123', 'Jabatan' => 'Barista', 'LevelStaf' => 'Senior',
                'GajiPokok' => '2500000', 'TarifLemburPerJam' => '20000', 'PotonganTerlambatPerMenit' => '1000', 'PotonganTidakMasukPerHari' => '100000',
            ]))
            ->assertRedirect(route('kelola.tim.daftar'))
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $siti = Pengguna::query()->where('Nama', 'Siti Barista')->sole();
        $karyawan = Karyawan::query()->where('IdPengguna', $siti->Id)->sole();
        expect($siti->Email)->toBeNull()->and($siti->CekHanyaKasir())->toBeTrue()
            ->and($karyawan->Jabatan)->toBe('Barista')->and($karyawan->LevelStaf)->toBe('Senior')
            ->and($karyawan->GajiPokok)->toBe('2500000.00')->and($karyawan->TarifLemburPerJam)->toBe('20000.00')
            ->and($karyawan->PotonganTerlambatPerMenit)->toBe('1000.00')->and($karyawan->PotonganTidakMasukPerHari)->toBe('100000.00');
        Mail::assertNothingSent();
    });

    it('Dashboard: wajib email & kata sandi awal; tanpa email ditolak dengan pesan jelas', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $masuk = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $masuk->post('/kelola/tim', IsianTimUji($tenant->Id, ['Akses' => 'Dashboard', 'Pin' => '']))->assertSessionHasErrors('Email');
        $masuk->post('/kelola/tim', IsianTimUji($tenant->Id, ['Akses' => 'Dashboard', 'Pin' => '', 'Email' => 'manajer@contoh.id']))->assertSessionHasErrors('KataSandi');
        $masuk->post('/kelola/tim', IsianTimUji($tenant->Id, ['Akses' => 'Dashboard', 'Pin' => '', 'Email' => 'manajer@contoh.id', 'KataSandi' => 'Rahasia123', 'JugaKaryawan' => false]))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $manajer = Pengguna::query()->where('Email', 'manajer@contoh.id')->sole();
        expect($manajer->WajibGantiKataSandi)->toBeTrue()->and(Karyawan::query()->where('IdPengguna', $manajer->Id)->exists())->toBeFalse();
    });

    it('Tanpa akun: hanya data karyawan (jadwal & gaji), tidak ada pengguna baru dan tidak memakai kursi pengguna', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $penggunaAwal = TenantPengguna::query()->where('IdTenant', $tenant->Id)->count();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/tim', ['Akses' => 'Tanpa', 'Nama' => 'Rina Pelayan', 'Jabatan' => 'Pelayan', 'GajiPokok' => '2000000', 'UuidOutlet' => Outlet::query()->orderBy('Id')->value('Uuid')])
            ->assertRedirect(route('kelola.tim.daftar'))
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $rina = Karyawan::query()->where('Nama', 'Rina Pelayan')->sole();
        expect($rina->IdPengguna)->toBeNull()->and($rina->GajiPokok)->toBe('2000000.00')->and($rina->IdOutlet)->not->toBeNull()
            ->and(TenantPengguna::query()->where('IdTenant', $tenant->Id)->count())->toBe($penggunaAwal);
    });

    it('jenis akses wajib dipilih', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/tim', ['Nama' => 'Tanpa Jenis'])->assertSessionHasErrors('Akses');
    });

    it('"Beri akses masuk" untuk karyawan yang sudah ada: formulir terisi & akun baru ditautkan (bukan karyawan kedua)', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $karyawan = Karyawan::query()->create(['Nama' => 'Rina Pelayan', 'Jabatan' => 'Pelayan', 'GajiPokok' => '2000000']);
        $masuk = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $this->get("/kelola/tim/buat?karyawan={$karyawan->Uuid}")->assertInertia(fn ($h) => $h->component('Kelola/Tim/Tambah')
            ->where('KaryawanTertaut.Nama', 'Rina Pelayan')->where('BolehAkun', true)->where('BolehKaryawan', true));

        $masuk->post('/kelola/tim', IsianTimUji($tenant->Id, ['Nama' => 'Rina Pelayan', 'UuidKaryawan' => $karyawan->Uuid]))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $rina = Pengguna::query()->where('Nama', 'Rina Pelayan')->sole();
        expect(Karyawan::query()->count())->toBe(1)->and($karyawan->refresh()->IdPengguna)->toBe($rina->Id)->and($karyawan->GajiPokok)->toBe('2000000.00');
    });

    it('kasir (tanpa izin) tidak bisa membuka formulir maupun menyimpan', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        $masuk = BantuanOrganisasi::Masuk($this, $kasir, $tenant->Id);

        $masuk->get('/kelola/tim/buat')->assertForbidden();
        $masuk->post('/kelola/tim', ['Akses' => 'Tanpa', 'Nama' => 'Coba Coba'])->assertForbidden();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Karyawan::query()->where('Nama', 'Coba Coba')->exists())->toBeFalse();
    });
});

describe('D-101 nonaktifkan & aktifkan satu orang sekaligus', function (): void {
    it('akun dan karyawan berhenti bersama dan aktif kembali bersama', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $masuk = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);
        $masuk->post('/kelola/tim', IsianTimUji($tenant->Id))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $siti = Pengguna::query()->where('Nama', 'Siti Barista')->sole();
        $karyawan = Karyawan::query()->where('IdPengguna', $siti->Id)->sole();

        $masuk->post('/kelola/tim/status', ['Aksi' => 'Nonaktifkan', 'UuidPengguna' => $siti->Uuid, 'UuidKaryawan' => $karyawan->Uuid])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(TenantPengguna::query()->where('IdTenant', $tenant->Id)->where('IdPengguna', $siti->Id)->value('Status'))->toBe(StatusKeanggotaan::Nonaktif)
            ->and($karyawan->refresh()->Status->value)->toBe('Nonaktif');
        $baris = collect($this->get('/kelola/tim')->inertiaProps('Tim'))->firstWhere('Nama', 'Siti Barista');
        expect($baris['Status'])->toBe('Nonaktif');

        $masuk->post('/kelola/tim/status', ['Aksi' => 'Aktifkan', 'UuidPengguna' => $siti->Uuid, 'UuidKaryawan' => $karyawan->Uuid])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(TenantPengguna::query()->where('IdTenant', $tenant->Id)->where('IdPengguna', $siti->Id)->value('Status'))->toBe(StatusKeanggotaan::Aktif)
            ->and($karyawan->refresh()->Status->value)->toBe('Aktif');
    });

    it('karyawan tanpa akun cukup UuidKaryawan; tanpa satu pun yang dipilih ditolak', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $masuk = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);
        $masuk->post('/kelola/tim', ['Akses' => 'Tanpa', 'Nama' => 'Rina Pelayan'])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $rina = Karyawan::query()->where('Nama', 'Rina Pelayan')->sole();

        $masuk->post('/kelola/tim/status', ['Aksi' => 'Nonaktifkan', 'UuidKaryawan' => $rina->Uuid])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect($rina->refresh()->Status->value)->toBe('Nonaktif');
        $masuk->post('/kelola/tim/status', ['Aksi' => 'Nonaktifkan'])->assertSessionHasErrors();
    });

    it('izin dicek per bagian: tanpa izin nonaktifkan pengguna tidak bisa menyentuh akun', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/tim', IsianTimUji($tenant->Id))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $siti = Pengguna::query()->where('Nama', 'Siti Barista')->sole();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);

        BantuanOrganisasi::Masuk($this, $kasir, $tenant->Id)->post('/kelola/tim/status', ['Aksi' => 'Nonaktifkan', 'UuidPengguna' => $siti->Uuid])->assertForbidden();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(TenantPengguna::query()->where('IdTenant', $tenant->Id)->where('IdPengguna', $siti->Id)->value('Status'))->toBe(StatusKeanggotaan::Aktif);
    });
});

describe('D-104 cakupan outlet mengikuti peran', function (): void {
    it('formulir Tim menyebut cakupan tiap peran: Admin/Akuntan/Pemilik Semua, peran operasional Terpilih, peran kustom Bebas', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        Peran::query()->create(['Nama' => 'Admin Cabang', 'Bawaan' => false]);

        $peran = collect(BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get('/kelola/tim/buat')->assertOk()->inertiaProps('Peran'));
        $cakupan = fn (string $nama): ?string => $peran->firstWhere('Nama', $nama)['Cakupan'] ?? null;

        expect($cakupan('Pemilik (Owner)'))->toBe('Semua')
            ->and($cakupan('Admin'))->toBe('Semua')
            ->and($cakupan('Akuntan'))->toBe('Semua')
            ->and($cakupan('Manajer Outlet'))->toBe('Terpilih')
            ->and($cakupan('Staf Gudang'))->toBe('Terpilih')
            ->and($cakupan('Kasir'))->toBe('Terpilih')
            ->and($cakupan('Admin Cabang'))->toBe('Bebas');
    });

    it('Admin selalu mendapat semua outlet: pilihan outlet yang terkirim diabaikan, tidak ada Admin setengah terkurung', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/tim', IsianTimUji($tenant->Id, [
            'Nama' => 'Admin Cabang A',
            'Akses' => 'Dashboard',
            'Email' => 'admin.a@contoh.id',
            'KataSandi' => 'Rahasia123',
            'Pin' => '',
            'Peran' => BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Admin)->Uuid,
            'SemuaOutlet' => false,
        ]))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $admin = Pengguna::query()->where('Email', 'admin.a@contoh.id')->sole();
        $anggota = TenantPengguna::query()->where('IdTenant', $tenant->Id)->where('IdPengguna', $admin->Id)->sole();

        expect($anggota->SemuaOutlet)->toBeTrue()
            ->and(OutletPengguna::query()->where('IdPengguna', $admin->Id)->count())->toBe(0);
    });

    it('Staf Gudang tetap terkurung di outlet yang dipilih', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/tim', IsianTimUji($tenant->Id, [
            'Nama' => 'Gudang Cabang',
            'Peran' => BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::StafGudang)->Uuid,
        ]))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $gudang = Pengguna::query()->where('Nama', 'Gudang Cabang')->sole();
        $anggota = TenantPengguna::query()->where('IdTenant', $tenant->Id)->where('IdPengguna', $gudang->Id)->sole();

        expect($anggota->SemuaOutlet)->toBeFalse()
            ->and(OutletPengguna::query()->where('IdPengguna', $gudang->Id)->count())->toBe(1);
    });
});
