<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Peran;
use App\Domain\Organisasi\Model\UndanganPengguna;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

describe('Isolasi tenant F-02 (§13.4): ID tebakan lintas tenant diperlakukan sebagai tidak ada', function (): void {
    it('Pemilik tenant A tidak bisa membuka atau mengubah outlet, gudang, merek, peran, anggota, dan undangan tenant B', function (): void {
        ['Tenant' => $tenantA, 'Pemilik' => $pemilikA] = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        ['Tenant' => $tenantB, 'Pemilik' => $pemilikB] = BantuanOrganisasi::BuatTenant('Toko Budi');
        BantuanFitur::Beri($tenantA->Id, 'stok.multi-gudang');
        $kasirB = BantuanOrganisasi::TambahAnggota($tenantB->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $pemilikB, $tenantB->Id)->post('/kelola/peran', ['Nama' => 'Rahasia B', 'Izin' => ['penjualan.buat']]);
        BantuanOrganisasi::Masuk($this, $pemilikB, $tenantB->Id)
            ->post('/kelola/pengguna/undangan', ['Email' => 'calon.b@contoh.id', 'Peran' => BantuanOrganisasi::Peran($tenantB->Id, PeranTenantBawaan::Kasir)->Uuid, 'SemuaOutlet' => true])
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenantB->Id);
        $outletB = Outlet::query()->sole();
        $gudangB = Gudang::query()->sole();
        $merekB = Merek::query()->sole();
        $peranB = Peran::query()->where('Nama', 'Rahasia B')->sole();
        $undanganB = UndanganPengguna::query()->where('IdTenant', $tenantB->Id)->sole();
        BantuanOrganisasi::AturKonteks($tenantA->Id);
        $outletA = Outlet::query()->sole();

        $masukA = fn () => BantuanOrganisasi::Masuk($this, $pemilikA, $tenantA->Id);
        $masukA()->get("/kelola/outlet/{$outletB->Uuid}")->assertNotFound();
        $masukA()->put("/kelola/outlet/{$outletB->Uuid}", ['Nama' => 'Diambil alih', 'Kode' => 'AMBIL', 'Merek' => $merekB->Uuid, 'ZonaWaktu' => 'WIB', 'JamTutupBuku' => '04:00'])->assertNotFound();
        $masukA()->post("/kelola/outlet/{$outletB->Uuid}/arsipkan")->assertNotFound();
        $masukA()->post("/kelola/outlet/{$outletB->Uuid}/gudang", ['Nama' => 'Susupan', 'Kode' => 'SUSUP', 'Jenis' => 'Gudang'])->assertNotFound();
        $masukA()->post("/kelola/outlet/{$outletA->Uuid}/gudang/{$gudangB->Uuid}/arsipkan")->assertNotFound();
        $masukA()->put("/kelola/merek/{$merekB->Uuid}", ['Nama' => 'Diubah'])->assertNotFound();
        $masukA()->delete("/kelola/merek/{$merekB->Uuid}")->assertNotFound();
        $masukA()->put("/kelola/peran/{$peranB->Uuid}", ['Nama' => 'Diubah', 'Izin' => ['penjualan.buat']])->assertNotFound();
        $masukA()->delete("/kelola/peran/{$peranB->Uuid}")->assertNotFound();
        $masukA()->put("/kelola/pengguna/{$kasirB->Uuid}/akses", ['Peran' => BantuanOrganisasi::Peran($tenantA->Id, PeranTenantBawaan::Admin)->Uuid, 'SemuaOutlet' => true])->assertNotFound();
        $masukA()->post('/kelola/tim/status', ['Aksi' => 'Nonaktifkan', 'UuidPengguna' => $kasirB->Uuid])->assertNotFound();
        $masukA()->post("/kelola/pengguna/undangan/{$undanganB->Uuid}/batalkan")->assertNotFound();

        // Memakai peran atau outlet tenant B saat mengundang di tenant A juga ditolak.
        $masukA()->post('/kelola/pengguna/undangan', ['Email' => 'x@contoh.id', 'Peran' => $peranB->Uuid, 'SemuaOutlet' => true])->assertSessionHasErrors('Peran');
        $masukA()->post('/kelola/pengguna/undangan', ['Email' => 'x@contoh.id', 'Peran' => BantuanOrganisasi::Peran($tenantA->Id, PeranTenantBawaan::Kasir)->Uuid, 'Outlet' => [$outletB->Uuid]])->assertSessionHasErrors('Outlet');
        $masukA()->post('/kelola/outlet', ['Nama' => 'Cabang', 'Kode' => 'CBG1', 'Merek' => $merekB->Uuid, 'ZonaWaktu' => 'WIB', 'JamTutupBuku' => '04:00'])->assertSessionHasErrors('Merek');

        BantuanOrganisasi::AturKonteks($tenantB->Id);
        expect($outletB->refresh()->Nama)->toBe('Toko Budi')
            ->and($merekB->refresh()->Nama)->toBe('Toko Budi')
            ->and(Peran::query()->whereKey($peranB->Id)->exists())->toBeTrue()
            ->and($undanganB->refresh()->DibatalkanPada)->toBeNull();
    });

    it('daftar outlet, pengguna, peran, dan log audit hanya berisi data tenant aktif', function (): void {
        ['Tenant' => $tenantA, 'Pemilik' => $pemilikA] = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        ['Tenant' => $tenantB, 'Pemilik' => $pemilikB] = BantuanOrganisasi::BuatTenant('Toko Budi');
        BantuanOrganisasi::TambahAnggota($tenantB->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $pemilikB, $tenantB->Id)->post('/kelola/merek', ['Nama' => 'Merek Rahasia B'])->assertSessionHasNoErrors();
        $masukA = fn () => BantuanOrganisasi::Masuk($this, $pemilikA, $tenantA->Id);

        $masukA()->get('/kelola/outlet')->assertInertia(fn (AssertableInertia $halaman) => $halaman->has('Outlet', 1)->has('Merek', 1)->where('Merek.0.Nama', 'Kopi Nusantara'));
        $masukA()->get('/kelola/tim')->assertInertia(fn (AssertableInertia $halaman) => $halaman->has('Tim', 1)->where('Tim.0.Email', $pemilikA->Email));
        $masukA()->get('/kelola/peran')->assertInertia(fn (AssertableInertia $halaman) => $halaman->has('Peran', count(PeranTenantBawaan::cases())));
        $masukA()->get('/kelola/log-audit')->assertInertia(function (AssertableInertia $halaman): void {
            $halaman->where('Log.Data', fn ($log) => collect($log)->every(fn (array $baris) => $baris['Peristiwa'] !== 'merek.buat'));
        });

        BantuanOrganisasi::AturKonteks($tenantA->Id);
        expect(LogAudit::query()->pluck('IdTenant')->unique()->values()->all())->toBe([$tenantA->Id]);
    });

    it('pengguna yang bukan anggota tenant aktif tidak bisa membuka back-office tenant itu lewat sesi palsu', function (): void {
        ['Tenant' => $tenantA] = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        ['Pemilik' => $pemilikB] = BantuanOrganisasi::BuatTenant('Toko Budi');

        BantuanOrganisasi::Masuk($this, $pemilikB, $tenantA->Id)->get('/kelola/outlet')->assertRedirect(route('pilih-tenant'));
    });
});
