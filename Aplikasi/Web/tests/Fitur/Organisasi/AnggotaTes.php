<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Organisasi\Model\UndanganPengguna;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

function AnggotaUji(int $idTenant, int $idPengguna): TenantPengguna
{
    return TenantPengguna::query()->where('IdTenant', $idTenant)->where('IdPengguna', $idPengguna)->sole();
}

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

describe('Peran & akses outlet anggota (F-02 langkah 3)', function (): void {
    it('Pemilik mengubah peran dan outlet yang ditugaskan; OutletPengguna mengikuti. Tercatat di log audit', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $utama = Outlet::query()->sole();
        $peranManajer = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::ManajerOutlet);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->put("/kelola/pengguna/{$kasir->Uuid}/akses", ['Peran' => $peranManajer->Uuid, 'SemuaOutlet' => false, 'Outlet' => [$utama->Uuid]])
            ->assertSessionHasNoErrors();

        $anggota = AnggotaUji($tenant->Id, $kasir->Id);
        expect($anggota->IdPeran)->toBe($peranManajer->Id)->and($anggota->SemuaOutlet)->toBeFalse();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $penugasan = OutletPengguna::query()->where('IdPengguna', $kasir->Id)->sole();
        expect($penugasan->IdOutlet)->toBe($utama->Id)->and($penugasan->IdPeran)->toBe($peranManajer->Id);
        $this->assertDatabaseHas('LogAudit', ['IdTenant' => $tenant->Id, 'Peristiwa' => 'pengguna.ubah-akses', 'IdObjek' => $anggota->Id]);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->put("/kelola/pengguna/{$kasir->Uuid}/akses", ['Peran' => $peranManajer->Uuid, 'SemuaOutlet' => true])
            ->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(OutletPengguna::query()->where('IdPengguna', $kasir->Id)->count())->toBe(0);
    });

    it('menunjuk Pemilik kedua lalu menurunkan Pemilik pertama; Pemilik terakhir tidak bisa diturunkan', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $admin = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Admin);
        $peranPemilik = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Pemilik);
        $peranAdmin = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Admin);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->put("/kelola/pengguna/{$admin->Uuid}/akses", ['Peran' => $peranPemilik->Uuid, 'SemuaOutlet' => false, 'Outlet' => []])
            ->assertSessionHasNoErrors();
        expect(AnggotaUji($tenant->Id, $admin->Id)->Pemilik)->toBeTrue()->and(AnggotaUji($tenant->Id, $admin->Id)->SemuaOutlet)->toBeTrue();

        BantuanOrganisasi::Masuk($this, $admin, $tenant->Id)
            ->put("/kelola/pengguna/{$pemilik->Uuid}/akses", ['Peran' => $peranAdmin->Uuid, 'SemuaOutlet' => true])
            ->assertSessionHasNoErrors();
        expect(AnggotaUji($tenant->Id, $pemilik->Id)->Pemilik)->toBeFalse();

        // Kini $admin satu-satunya Pemilik: tidak bisa mengubah dirinya dan tidak ada Pemilik lain yang bisa menurunkannya.
        BantuanOrganisasi::Masuk($this, $admin, $tenant->Id)
            ->put("/kelola/pengguna/{$admin->Uuid}/akses", ['Peran' => $peranAdmin->Uuid, 'SemuaOutlet' => true])
            ->assertSessionHasErrors('Umum');
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->put("/kelola/pengguna/{$admin->Uuid}/akses", ['Peran' => $peranAdmin->Uuid, 'SemuaOutlet' => true])
            ->assertSessionHasErrors('Umum');
        expect(AnggotaUji($tenant->Id, $admin->Id)->Pemilik)->toBeTrue();
    });

    it('anti-eskalasi: Admin tidak bisa mengubah Pemilik, menunjuk Pemilik, atau mengubah aksesnya sendiri', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $admin = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Admin);
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        $peranPemilik = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Pemilik)->Uuid;
        $peranKasir = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Kasir)->Uuid;
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $admin, $tenant->Id);

        $masuk()->put("/kelola/pengguna/{$pemilik->Uuid}/akses", ['Peran' => $peranKasir, 'SemuaOutlet' => true])->assertSessionHasErrors('Umum');
        $masuk()->post('/kelola/tim/status', ['Aksi' => 'Nonaktifkan', 'UuidPengguna' => $pemilik->Uuid])->assertSessionHasErrors('Umum');
        $masuk()->put("/kelola/pengguna/{$kasir->Uuid}/akses", ['Peran' => $peranPemilik, 'SemuaOutlet' => true])->assertSessionHasErrors('Peran');
        $masuk()->put("/kelola/pengguna/{$admin->Uuid}/akses", ['Peran' => $peranKasir, 'SemuaOutlet' => true])->assertSessionHasErrors('Umum');

        expect(AnggotaUji($tenant->Id, $pemilik->Id)->Status)->toBe(StatusKeanggotaan::Aktif)
            ->and(AnggotaUji($tenant->Id, $kasir->Id)->Pemilik)->toBeFalse();
    });

    it('anggota yang hanya mengelola satu outlet tidak bisa memberi akses semua outlet atau outlet lain', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $utama = Outlet::query()->sole();
        $cabang = Outlet::query()->create(['IdMerek' => $utama->IdMerek, 'Kode' => 'CBG1', 'Nama' => 'Cabang Kartasura']);
        $admin = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Admin, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $utama->Id, 'IdPengguna' => $admin->Id, 'IdPeran' => BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Admin)->Id]);
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir, semuaOutlet: false);
        $peranKasir = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Kasir)->Uuid;
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $admin, $tenant->Id);

        $masuk()->put("/kelola/pengguna/{$kasir->Uuid}/akses", ['Peran' => $peranKasir, 'SemuaOutlet' => true])->assertSessionHasErrors('SemuaOutlet');
        $masuk()->put("/kelola/pengguna/{$kasir->Uuid}/akses", ['Peran' => $peranKasir, 'Outlet' => [$cabang->Uuid]])->assertSessionHasErrors('Outlet');
        $masuk()->put("/kelola/pengguna/{$kasir->Uuid}/akses", ['Peran' => $peranKasir, 'Outlet' => [$utama->Uuid]])->assertSessionHasNoErrors();
    });
});

describe('Nonaktifkan & aktifkan kembali anggota', function (): void {
    it('anggota nonaktif langsung kehilangan akses. Undangan yang ia kirim ikut dibatalkan. Bisa diaktifkan kembali', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $admin = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Admin);
        BantuanOrganisasi::Masuk($this, $admin, $tenant->Id)
            ->post('/kelola/pengguna/undangan', ['Email' => 'kasir.baru@contoh.id', 'Peran' => BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Kasir)->Uuid, 'SemuaOutlet' => true])
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/tim/status', ['Aksi' => 'Nonaktifkan', 'UuidPengguna' => $admin->Uuid])->assertSessionHasNoErrors();

        $anggota = AnggotaUji($tenant->Id, $admin->Id);
        expect($anggota->Status)->toBe(StatusKeanggotaan::Nonaktif)->and($anggota->DinonaktifkanPada)->not->toBeNull()
            ->and(UndanganPengguna::query()->sole()->DibatalkanPada)->not->toBeNull();
        BantuanOrganisasi::Masuk($this, $admin, $tenant->Id)->get('/kelola/outlet')->assertRedirect(route('pilih-tenant'));
        $this->assertDatabaseHas('LogAudit', ['IdTenant' => $tenant->Id, 'Peristiwa' => 'pengguna.nonaktifkan', 'IdObjek' => $anggota->Id]);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/tim/status', ['Aksi' => 'Aktifkan', 'UuidPengguna' => $admin->Uuid])->assertSessionHasNoErrors();
        expect(AnggotaUji($tenant->Id, $admin->Id)->Status)->toBe(StatusKeanggotaan::Aktif);
        BantuanOrganisasi::Masuk($this, $admin, $tenant->Id)->get('/kelola/outlet')->assertOk();
    });

    it('tidak bisa menonaktifkan Pemilik terakhir atau diri sendiri', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $pemilikKedua = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Pemilik);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/tim/status', ['Aksi' => 'Nonaktifkan', 'UuidPengguna' => $pemilik->Uuid])->assertSessionHasErrors('Umum');
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/tim/status', ['Aksi' => 'Nonaktifkan', 'UuidPengguna' => $pemilikKedua->Uuid])->assertSessionHasNoErrors();

        // Sisa satu Pemilik aktif: tidak ada jalan menonaktifkannya.
        expect(TenantPengguna::query()->where('IdTenant', $tenant->Id)->where('Pemilik', true)->where('Status', 'Aktif')->count())->toBe(1);
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/tim/status', ['Aksi' => 'Nonaktifkan', 'UuidPengguna' => $pemilik->Uuid])->assertSessionHasErrors('Umum');
        expect(AnggotaUji($tenant->Id, $pemilik->Id)->Status)->toBe(StatusKeanggotaan::Aktif);
    });

    it('halaman undang pengguna (halaman penuh): opsi peran, outlet, kursi, tanpa pengguna.undang 403. Kirim kembali ke daftar', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get('/kelola/pengguna/undangan/buat')->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/Pengguna/Buat')
                ->has('Peran', count(PeranTenantBawaan::cases()))
                ->has('Outlet', 1)
                ->has('Outlet.0', fn (AssertableInertia $outlet) => $outlet->hasAll(['Uuid', 'Kode', 'Nama']))
                ->where('BatasPengguna', ['Batas' => 20, 'Terpakai' => 1])
                ->missing('Anggota'));

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/pengguna/undangan', ['Email' => 'calon@contoh.id', 'Peran' => BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Akuntan)->Uuid, 'SemuaOutlet' => true])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('kelola.tim.daftar'));

        $manajer = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::ManajerOutlet);
        BantuanOrganisasi::Masuk($this, $manajer, $tenant->Id)->get('/kelola/pengguna/undangan/buat')->assertForbidden();
    });

    it('daftar pengguna menampilkan anggota, peran, undangan menunggu, dan kursi terpakai', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/pengguna/undangan', ['Email' => 'calon@contoh.id', 'Peran' => BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Akuntan)->Uuid, 'SemuaOutlet' => true])
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get('/kelola/tim')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/Tim/Daftar')
                ->has('Tim', 2)
                ->has('Undangan', 1)
                ->where('Undangan.0.Email', 'calon@contoh.id')
                ->where('Undangan.0.NamaPeran', 'Akuntan')
                ->has('Peran', count(PeranTenantBawaan::cases()))
                ->where('BatasPengguna', ['Batas' => 20, 'Terpakai' => 3])
                ->where('UuidSaya', $pemilik->Uuid));
    });
});

describe('Aksi massal anggota', function (): void {
    it('nonaktifkan dan aktifkan banyak sekaligus, yang sudah berstatus itu dilewati, audit per anggota', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $kasir1 = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        $kasir2 = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $masuk()->post('/kelola/tim/status', ['Aksi' => 'Nonaktifkan', 'UuidPengguna' => $kasir1->Uuid])->assertSessionHasNoErrors();
        $masuk()->post('/kelola/pengguna/massal', ['Aksi' => 'Nonaktifkan', 'Uuid' => [$kasir1->Uuid, $kasir2->Uuid]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat', '1 pengguna dinonaktifkan dan langsung keluar dari usaha ini. 1 dilewati karena sudah begitu.');
        expect(AnggotaUji($tenant->Id, $kasir1->Id)->Status)->toBe(StatusKeanggotaan::Nonaktif)
            ->and(AnggotaUji($tenant->Id, $kasir2->Id)->Status)->toBe(StatusKeanggotaan::Nonaktif);

        $masuk()->post('/kelola/pengguna/massal', ['Aksi' => 'Aktifkan', 'Uuid' => [$kasir1->Uuid, $kasir2->Uuid]])
            ->assertSessionHas('Kilat', '2 pengguna aktif kembali.');
        expect(AnggotaUji($tenant->Id, $kasir1->Id)->Status)->toBe(StatusKeanggotaan::Aktif);
        $this->assertDatabaseHas('LogAudit', ['IdTenant' => $tenant->Id, 'Peristiwa' => 'pengguna.nonaktifkan', 'IdObjek' => AnggotaUji($tenant->Id, $kasir2->Id)->Id]);
    });

    it('penjaga tetap berlaku: diri sendiri dan Pemilik terakhir menolak seluruhnya, ganti peran mempertahankan outlet', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $kasir1 = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir, semuaOutlet: false);
        $kasir2 = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $utama = Outlet::query()->sole();
        $peranKasir = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Kasir);
        OutletPengguna::query()->create(['IdOutlet' => $utama->Id, 'IdPengguna' => $kasir1->Id, 'IdPeran' => $peranKasir->Id]);
        $peranManajer = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::ManajerOutlet);
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        // Menyertakan diri sendiri menolak semua (satu transaksi).
        $masuk()->post('/kelola/pengguna/massal', ['Aksi' => 'Nonaktifkan', 'Uuid' => [$kasir1->Uuid, $pemilik->Uuid]])->assertSessionHasErrors('Umum');
        expect(AnggotaUji($tenant->Id, $kasir1->Id)->Status)->toBe(StatusKeanggotaan::Aktif);

        $masuk()->post('/kelola/pengguna/massal', ['Aksi' => 'Peran', 'Uuid' => [$kasir1->Uuid, $kasir2->Uuid], 'UuidPeran' => $peranManajer->Uuid])
            ->assertSessionHasNoErrors()->assertSessionHas('Kilat', '2 pengguna berganti peran.');
        expect(AnggotaUji($tenant->Id, $kasir1->Id)->IdPeran)->toBe($peranManajer->Id)->and(AnggotaUji($tenant->Id, $kasir1->Id)->SemuaOutlet)->toBeFalse()
            ->and(AnggotaUji($tenant->Id, $kasir2->Id)->SemuaOutlet)->toBeTrue();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(OutletPengguna::query()->where('IdPengguna', $kasir1->Id)->sole()->IdOutlet)->toBe($utama->Id);

        $masuk()->post('/kelola/pengguna/massal', ['Aksi' => 'Peran', 'Uuid' => [$kasir1->Uuid]])->assertSessionHasErrors('UuidPeran');
        $masuk()->post('/kelola/pengguna/massal', ['Aksi' => 'Peran', 'Uuid' => [$kasir1->Uuid, '01J9ZZZZZZZZZZZZZZZZZZZZZZ'], 'UuidPeran' => $peranKasir->Uuid])->assertSessionHasErrors('Uuid');
        $masuk()->post('/kelola/pengguna/massal', ['Aksi' => 'Hapus', 'Uuid' => [$kasir1->Uuid]])->assertSessionHasErrors('Aksi');
    });

    it('kasir tanpa izin pengguna ditolak', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        $lain = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);

        BantuanOrganisasi::Masuk($this, $kasir, $tenant->Id)->post('/kelola/pengguna/massal', ['Aksi' => 'Nonaktifkan', 'Uuid' => [$lain->Uuid]])->assertForbidden();
    });
});
