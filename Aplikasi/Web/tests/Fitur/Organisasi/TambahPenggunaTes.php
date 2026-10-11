<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-22 (F-02 langkah 3): admin tenant menambah pengguna langsung. Karyawan kasir tanpa email cukup PIN; pengguna
 * dengan email mendapat kata sandi awal dan wajib menggantinya saat pertama masuk.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

/**
 * @param  array<string, mixed>  $isian
 * @return array<string, mixed>
 */
function IsianTambahPenggunaUji(int $idTenant, array $isian = []): array
{
    BantuanOrganisasi::AturKonteks($idTenant);

    // D-101: tambah staf lewat formulir Tim; berisi email = jenis Dashboard, tanpa email = Kasir (PIN).
    return [
        'Nama' => 'Siti Kasir',
        'Peran' => BantuanOrganisasi::Peran($idTenant, PeranTenantBawaan::Kasir)->Uuid,
        'SemuaOutlet' => false,
        'Outlet' => [Outlet::query()->where('Kode', 'UTAMA')->value('Uuid')],
        'Akses' => isset($isian['Email']) ? 'Dashboard' : 'Kasir',
        ...$isian,
    ];
}

describe('D-22 tambah pengguna langsung di tenant', function (): void {
    it('audit #34: "Catat juga sebagai karyawan" membuat karyawan tertaut akun dalam satu simpan', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/tim', IsianTambahPenggunaUji($tenant->Id, ['Pin' => '482915', 'JugaKaryawan' => true, 'Jabatan' => 'Barista']))
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $kasir = Pengguna::query()->where('Nama', 'Siti Kasir')->sole();
        $karyawan = Karyawan::query()->where('IdPengguna', $kasir->Id)->sole();
        expect($karyawan->Nama)->toBe('Siti Kasir')->and($karyawan->Jabatan)->toBe('Barista')
            ->and($karyawan->IdOutlet)->toBe(Outlet::query()->where('Kode', 'UTAMA')->value('Id'));
    });

    it('karyawan kasir tanpa email: cukup nama + PIN, bisa masuk aplikasi kasir dengan PIN, tanpa email terkirim', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/tim', IsianTambahPenggunaUji($tenant->Id, ['Pin' => '482915']))
            ->assertRedirect(route('kelola.tim.daftar'))
            ->assertSessionHasNoErrors();

        $kasir = Pengguna::query()->where('Nama', 'Siti Kasir')->sole();
        $anggota = TenantPengguna::query()->where('IdTenant', $tenant->Id)->where('IdPengguna', $kasir->Id)->sole();
        expect($kasir->Email)->toBeNull()
            ->and($kasir->CekHanyaKasir())->toBeTrue()
            ->and($kasir->WajibGantiKataSandi)->toBeFalse()
            ->and(Hash::check('482915', (string) $anggota->HashPin))->toBeTrue()
            ->and(OutletPengguna::query()->where('IdPengguna', $kasir->Id)->count())->toBe(1)
            ->and(LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'pengguna.tambah')->exists())->toBeTrue();
        Mail::assertNothingSent();

        ['Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);
        $this->withToken($token)->postJson('/api/pos/v1/kasir/masuk-pin', ['UuidPengguna' => $kasir->Uuid, 'Pin' => '482915'])
            ->assertOk()
            ->assertJsonPath('Pengguna.Nama', 'Siti Kasir');
    });

    it('karyawan tanpa email wajib PIN kuat; email wajib disertai kata sandi awal', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $this->post('/kelola/tim', IsianTambahPenggunaUji($tenant->Id))->assertSessionHasErrors('Pin');
        $this->post('/kelola/tim', IsianTambahPenggunaUji($tenant->Id, ['Pin' => '123456']))->assertSessionHasErrors('Pin');
        $this->post('/kelola/tim', IsianTambahPenggunaUji($tenant->Id, ['Email' => 'siti@contoh.id']))->assertSessionHasErrors('KataSandi');
        expect(Pengguna::query()->where('Nama', 'Siti Kasir')->exists())->toBeFalse();
    });

    it('dengan email: kata sandi awal, wajib diganti sebelum memilih usaha atau membuka back-office', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/tim', IsianTambahPenggunaUji($tenant->Id, [
                'Nama' => 'Andi Admin',
                'Email' => 'Andi@Contoh.id',
                'KataSandi' => 'awal12345',
                'Peran' => BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Admin)->Uuid,
                'SemuaOutlet' => true,
            ]))
            ->assertSessionHasNoErrors();

        $andi = Pengguna::query()->where('Email', 'andi@contoh.id')->sole();
        expect($andi->WajibGantiKataSandi)->toBeTrue()->and($andi->EmailDiverifikasiPada)->not->toBeNull();

        $this->post('/keluar');
        $this->flushSession();
        BantuanOrganisasi::Masuk($this, $andi, $tenant->Id);
        $this->get('/kelola')->assertRedirect(route('kata-sandi.ganti'));
        $this->get('/pilih-tenant')->assertRedirect(route('kata-sandi.ganti'));

        $this->post('/ganti-kata-sandi', ['KataSandiLama' => 'awal12345', 'KataSandi' => 'baru67890x', 'KonfirmasiKataSandi' => 'baru67890x'])
            ->assertRedirect(route('kelola.beranda'));
        expect($andi->refresh()->WajibGantiKataSandi)->toBeFalse()
            ->and(Hash::check('baru67890x', $andi->KataSandi))->toBeTrue();
        $this->get('/kelola')->assertOk();
    });

    it('email yang sudah punya akun Payoung tidak bisa ditambah langsung (harus lewat undangan)', function (): void {
        ['Tenant' => $tenantA, 'Pemilik' => $pemilikA] = BantuanOrganisasi::BuatTenant('Kopi A');
        ['Pemilik' => $pemilikB] = BantuanOrganisasi::BuatTenant('Kopi B');

        BantuanOrganisasi::Masuk($this, $pemilikA, $tenantA->Id)
            ->post('/kelola/tim', IsianTambahPenggunaUji($tenantA->Id, ['Email' => $pemilikB->Email, 'KataSandi' => 'awal12345']))
            ->assertSessionHasErrors('Email');
        expect(Hash::check('awal12345', $pemilikB->refresh()->KataSandi))->toBeFalse();
    });

    it('kasir tidak berhak menambah pengguna; Admin tidak bisa memberi peran Pemilik', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        $admin = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Admin);

        BantuanOrganisasi::Masuk($this, $kasir, $tenant->Id)
            ->post('/kelola/tim', IsianTambahPenggunaUji($tenant->Id, ['Pin' => '482915']))
            ->assertForbidden();

        $this->post('/keluar');
        $this->flushSession();
        BantuanOrganisasi::Masuk($this, $admin, $tenant->Id)
            ->post('/kelola/tim', IsianTambahPenggunaUji($tenant->Id, [
                'Pin' => '482915',
                'Peran' => BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Pemilik)->Uuid,
            ]))
            ->assertSessionHasErrors();
        expect(Pengguna::query()->where('Nama', 'Siti Kasir')->exists())->toBeFalse();
    });
});

describe('D-46 data karyawan & pengguna saling terhubung', function (): void {
    it('daftar Tim menggabungkan akun dan karyawannya jadi satu baris; "Catat sebagai karyawan" menautkan akun tanpa mengisi nama dua kali, sekali saja', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $masuk = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);
        $masuk->post('/kelola/tim', IsianTambahPenggunaUji($tenant->Id, ['Pin' => '482915', 'JugaKaryawan' => false]))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $siti = Pengguna::query()->where('Nama', 'Siti Kasir')->sole();
        expect(Karyawan::query()->where('IdPengguna', $siti->Id)->exists())->toBeFalse();

        $baris = fn () => collect($this->get('/kelola/tim')->assertOk()->inertiaProps('Tim'))->firstWhere('UuidPengguna', $siti->Uuid);
        expect($baris()['Karyawan'])->toBeNull();

        $this->post("/kelola/pengguna/{$siti->Uuid}/karyawan")->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $karyawan = Karyawan::query()->where('IdPengguna', $siti->Id)->sole();
        expect($karyawan->Nama)->toBe('Siti Kasir');
        expect($baris()['Karyawan']['Uuid'])->toBe($karyawan->Uuid)->and($baris()['Karyawan']['Status'])->toBe('Aktif')
            ->and(collect($this->get('/kelola/tim')->inertiaProps('Tim'))->where('Nama', 'Siti Kasir'))->toHaveCount(1);

        $this->post("/kelola/pengguna/{$siti->Uuid}/karyawan")->assertSessionHasErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Karyawan::query()->where('IdPengguna', $siti->Id)->count())->toBe(1);
    });

    it('"Catat sebagai karyawan" butuh karyawan.kelola dan hanya untuk anggota tenant ini', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/tim', IsianTambahPenggunaUji($tenant->Id, ['Pin' => '482915', 'JugaKaryawan' => false]))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $siti = Pengguna::query()->where('Nama', 'Siti Kasir')->sole();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);

        BantuanOrganisasi::Masuk($this, $kasir, $tenant->Id)->post("/kelola/pengguna/{$siti->Uuid}/karyawan")->assertForbidden();

        ['Tenant' => $lain, 'Pemilik' => $pemilikLain] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::Masuk($this, $pemilikLain, $lain->Id)->post("/kelola/pengguna/{$siti->Uuid}/karyawan")->assertNotFound();
    });

    it('"Buatkan akun" dari karyawan: formulir terisi, akun baru ditautkan ke karyawan yang sama (bukan karyawan kedua)', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $karyawan = Karyawan::query()->create(['Nama' => 'Siti Kasir', 'Jabatan' => 'Barista', 'GajiPokok' => '2500000']);
        $masuk = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $this->get("/kelola/tim/buat?karyawan={$karyawan->Uuid}")->assertInertia(fn ($h) => $h->component('Kelola/Tim/Tambah')
            ->where('KaryawanTertaut.Nama', 'Siti Kasir')->where('KaryawanTertaut.Jabatan', 'Barista'));

        $masuk->post('/kelola/tim', IsianTambahPenggunaUji($tenant->Id, ['Pin' => '482915', 'UuidKaryawan' => $karyawan->Uuid]))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $siti = Pengguna::query()->where('Nama', 'Siti Kasir')->sole();
        expect(Karyawan::query()->count())->toBe(1)
            ->and($karyawan->refresh()->IdPengguna)->toBe($siti->Id)
            ->and($karyawan->GajiPokok)->toBe('2500000.00');

        // Karyawan yang sudah punya akun tidak lagi ditawari "Buatkan akun" dan tidak bisa ditautkan dua kali.
        $this->get("/kelola/tim/buat?karyawan={$karyawan->Uuid}")->assertInertia(fn ($h) => $h->where('KaryawanTertaut', null));
        $masuk->post('/kelola/tim', IsianTambahPenggunaUji($tenant->Id, ['Nama' => 'Siti Dua', 'Pin' => '482916', 'UuidKaryawan' => $karyawan->Uuid]))->assertSessionHasErrors('UuidKaryawan');
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Pengguna::query()->where('Nama', 'Siti Dua')->exists())->toBeFalse();
    });

    it('karyawan yang tertaut ke akun memakai nama akun, apa pun yang diketik di formulir karyawan', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $masuk = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);
        $masuk->post('/kelola/tim', IsianTambahPenggunaUji($tenant->Id, ['Pin' => '482915', 'JugaKaryawan' => true]))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $siti = Pengguna::query()->where('Nama', 'Siti Kasir')->sole();
        $karyawan = Karyawan::query()->where('IdPengguna', $siti->Id)->sole();

        $masuk->put("/kelola/karyawan/{$karyawan->Uuid}", ['Nama' => 'Nama Lain Sekali', 'UuidPengguna' => $siti->Uuid])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Karyawan::query()->where('IdPengguna', $siti->Id)->sole()->Nama)->toBe('Siti Kasir');
    });
});
