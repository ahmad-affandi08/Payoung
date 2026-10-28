<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Enum\JenisPerangkat;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\KodeAktivasi;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Organisasi\Model\Perangkat;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

function PerangkatUjiOutletUtama(int $idTenant): Outlet
{
    BantuanOrganisasi::AturKonteks($idTenant);

    return Outlet::query()->orderBy('Id')->firstOrFail();
}

function PerangkatUjiBuatOutlet(int $idTenant, string $kode): Outlet
{
    BantuanOrganisasi::AturKonteks($idTenant);

    return Outlet::query()->create(['IdMerek' => Merek::query()->value('Id'), 'Kode' => $kode, 'Nama' => "Cabang {$kode}"]);
}

describe('F-02 langkah 5: tambah perangkat & kode aktivasi di back-office', function (): void {
    it('membuat perangkat berkode {KodeOutlet}-K01, kode aktivasi 8 karakter tanpa karakter ambigu + QR, disimpan sebagai hash', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $outlet = PerangkatUjiOutletUtama($tenant->Id);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/perangkat', ['Nama' => 'Kasir Depan', 'Outlet' => $outlet->Uuid, 'Jenis' => 'Kasir'])
            ->assertRedirect('/kelola/perangkat')
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('Kilat');

        $kode = session('KodeAktivasiBaru');
        expect($kode['Kode'])->toMatch('/^[2-9A-HJ-NP-Z]{8}$/')
            ->and($kode['KodePerangkat'])->toBe("{$outlet->Kode}-K01");

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $perangkat = Perangkat::query()->sole();
        $baris = KodeAktivasi::query()->where('IdPerangkat', $perangkat->Id)->sole();
        expect($perangkat->Kode)->toBe("{$outlet->Kode}-K01")
            ->and($perangkat->AmbilStatus())->toBe('BelumDiaktifkan')
            ->and($baris->HashKode)->not->toBe($kode['Kode'])
            ->and($baris->HashKode)->toBe(KodeAktivasi::BuatHashKode($kode['Kode']))
            ->and($baris->KedaluwarsaPada->diffInMinutes(now(), true))->toBeGreaterThan(14.9)->toBeLessThanOrEqual(15.0);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->withSession(['KodeAktivasiBaru' => $kode])
            ->get('/kelola/perangkat')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/Perangkat/Daftar')
                ->where('Perangkat.0.Kode', "{$outlet->Kode}-K01")
                ->where('Perangkat.0.Status', 'BelumDiaktifkan')
                ->where('KodeAktivasiBaru.Kode', $kode['Kode'])
                ->where('KodeAktivasiBaru.QrSvg', fn (string $svg) => str_contains($svg, '<svg'))
                ->where('Outlet.0.BatasPerangkat', ['Batas' => 5, 'Terpakai' => 1]));
    });

    it('masa berlaku kode aktivasi mengikuti konfigurasi (dinaikkan untuk peninjauan Play Store)', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $outlet = PerangkatUjiOutletUtama($tenant->Id);
        config(['organisasi.MenitBerlakuKodeAktivasi' => 10080]);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/perangkat', ['Nama' => 'Peninjau', 'Outlet' => $outlet->Uuid, 'Jenis' => 'Kasir'])
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $baris = KodeAktivasi::query()->where('IdPerangkat', Perangkat::query()->sole()->Id)->sole();
        expect($baris->KedaluwarsaPada->diffInMinutes(now(), true))->toBeGreaterThan(10079.9)->toBeLessThanOrEqual(10080.0);
    });

    it('BR-02.2: perangkat pertama mengunci kode outlet sehingga kode tidak bisa diubah lagi', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $outlet = PerangkatUjiOutletUtama($tenant->Id);
        expect($outlet->KodeDikunciPada)->toBeNull();

        BantuanPerangkat::BuatPerangkat($tenant->Id, $outlet);

        expect($outlet->refresh()->KodeDikunciPada)->not->toBeNull();
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->put("/kelola/outlet/{$outlet->Uuid}", ['Nama' => $outlet->Nama, 'Kode' => 'BARU', 'Merek' => Merek::query()->value('Uuid'), 'ZonaWaktu' => 'WIB', 'JamTutupBuku' => '04:00'])
            ->assertSessionHasErrors('Kode');
        expect(LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'outlet.kunci-kode')->count())->toBe(1);
    });

    it('kode perangkat berurutan per outlet & jenis dan tidak pernah dipakai ulang setelah dicabut', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $outlet = PerangkatUjiOutletUtama($tenant->Id);
        $kode = $outlet->Kode;

        $k1 = BantuanPerangkat::BuatPerangkat($tenant->Id, $outlet)['Perangkat'];
        $k2 = BantuanPerangkat::BuatPerangkat($tenant->Id, $outlet, nama: 'Kasir Belakang')['Perangkat'];
        $dapur = BantuanPerangkat::BuatPerangkat($tenant->Id, $outlet, JenisPerangkat::Kds, 'Layar Dapur')['Perangkat'];
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post("/kelola/perangkat/{$k2->Uuid}/cabut")->assertSessionHasNoErrors();
        $k3 = BantuanPerangkat::BuatPerangkat($tenant->Id, $outlet)['Perangkat'];

        expect([$k1->Kode, $k2->Kode, $dapur->Kode, $k3->Kode])->toBe(["{$kode}-K01", "{$kode}-K02", "{$kode}-D01", "{$kode}-K03"]);
    });

    it('membuat ulang kode membatalkan kode lama. Mencabut membatalkan kode yang belum dipakai', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        ['Perangkat' => $perangkat, 'Kode' => $kodeLama] = BantuanPerangkat::BuatPerangkat($tenant->Id);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post("/kelola/perangkat/{$perangkat->Uuid}/kode-aktivasi")->assertSessionHasNoErrors();
        $kodeBaru = session('KodeAktivasiBaru')['Kode'];

        expect($kodeBaru)->not->toBe($kodeLama)
            ->and(KodeAktivasi::query()->where('HashKode', KodeAktivasi::BuatHashKode($kodeLama))->sole()->CekBerlaku())->toBeFalse()
            ->and(KodeAktivasi::query()->where('HashKode', KodeAktivasi::BuatHashKode($kodeBaru))->sole()->CekBerlaku())->toBeTrue();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post("/kelola/perangkat/{$perangkat->Uuid}/cabut")->assertSessionHasNoErrors();
        expect(KodeAktivasi::query()->where('HashKode', KodeAktivasi::BuatHashKode($kodeBaru))->sole()->CekBerlaku())->toBeFalse();

        // Perangkat dicabut bersifat final: tidak bisa dibuatkan kode atau diganti nama.
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post("/kelola/perangkat/{$perangkat->Uuid}/kode-aktivasi")->assertSessionHasErrors('Umum');
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->put("/kelola/perangkat/{$perangkat->Uuid}", ['Nama' => 'Baru'])->assertSessionHasErrors('Nama');
    });

    it('mengubah nama perangkat tanpa mengubah kodenya', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $perangkat = BantuanPerangkat::BuatPerangkat($tenant->Id)['Perangkat'];

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->put("/kelola/perangkat/{$perangkat->Uuid}", ['Nama' => 'Kasir Lantai 2'])->assertSessionHasNoErrors();

        expect($perangkat->refresh()->Nama)->toBe('Kasir Lantai 2')->and($perangkat->Kode)->toEndWith('-K01');
    });

    it('perangkat tidak bisa ditambahkan di outlet yang diarsipkan', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $cabang = PerangkatUjiBuatOutlet($tenant->Id, 'CBG1');
        $cabang->update(['Status' => 'Diarsipkan']);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/perangkat', ['Nama' => 'Kasir', 'Outlet' => $cabang->Uuid, 'Jenis' => 'Kasir'])
            ->assertSessionHasErrors('Outlet');
    });
});

describe('BR-02.1: batas perangkat per outlet dari paket', function (): void {
    it('paket Gratis (1 perangkat/outlet) menolak perangkat kedua. Perangkat dicabut tidak dihitung', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant(kodePaket: 'GRATIS');
        $outlet = PerangkatUjiOutletUtama($tenant->Id);
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $masuk()->post('/kelola/perangkat', ['Nama' => 'Kasir 1', 'Outlet' => $outlet->Uuid, 'Jenis' => 'Kasir'])->assertSessionHasNoErrors();
        $masuk()->post('/kelola/perangkat', ['Nama' => 'Kasir 2', 'Outlet' => $outlet->Uuid, 'Jenis' => 'Kasir'])
            ->assertSessionHasErrors(['Umum' => 'Paket Gratis mencakup maksimal 1 perangkat per outlet dan semuanya sudah terpakai (1). Tingkatkan paket atau tambah add-on di menu Langganan untuk menambah perangkat per outlet.']);

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $pertama = Perangkat::query()->sole();
        $masuk()->post("/kelola/perangkat/{$pertama->Uuid}/cabut")->assertSessionHasNoErrors();
        $masuk()->post('/kelola/perangkat', ['Nama' => 'Kasir 2', 'Outlet' => $outlet->Uuid, 'Jenis' => 'Kasir'])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Perangkat::query()->count())->toBe(2)->and(Perangkat::query()->whereNull('DicabutPada')->count())->toBe(1);
    });

    it('batas dihitung per outlet, bukan per tenant', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant(kodePaket: 'STARTER');
        $utama = PerangkatUjiOutletUtama($tenant->Id);

        BantuanPerangkat::BuatPerangkat($tenant->Id, $utama);
        BantuanPerangkat::BuatPerangkat($tenant->Id, $utama);
        expect(fn () => BantuanPerangkat::BuatPerangkat($tenant->Id, $utama))->toThrow(PelanggaranAturanBisnis::class);

        $cabang = PerangkatUjiBuatOutlet($tenant->Id, 'CBG1');
        expect(BantuanPerangkat::BuatPerangkat($tenant->Id, $cabang)['Perangkat']->Kode)->toBe('CBG1-K01');
    });
});

describe('Izin & isolasi perangkat', function (): void {
    it('izin per peran: Kasir & Akuntan tanpa akses; Manajer Outlet & Admin bisa mengelola', function (PeranTenantBawaan $peran, bool $lihat, bool $kelola): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        $anggota = BantuanOrganisasi::TambahAnggota($tenant->Id, $peran);
        $outlet = PerangkatUjiOutletUtama($tenant->Id);

        $respons = BantuanOrganisasi::Masuk($this, $anggota, $tenant->Id)->get('/kelola/perangkat');
        $lihat ? $respons->assertOk() : $respons->assertForbidden();

        $respons = BantuanOrganisasi::Masuk($this, $anggota, $tenant->Id)->post('/kelola/perangkat', ['Nama' => 'Kasir', 'Outlet' => $outlet->Uuid, 'Jenis' => 'Kasir']);
        $kelola ? $respons->assertSessionHasNoErrors()->assertRedirect('/kelola/perangkat') : $respons->assertForbidden();
    })->with([
        'Admin' => [PeranTenantBawaan::Admin, true, true],
        'Manajer Outlet' => [PeranTenantBawaan::ManajerOutlet, true, true],
        'Kasir' => [PeranTenantBawaan::Kasir, false, false],
        'Akuntan' => [PeranTenantBawaan::Akuntan, false, false],
    ]);

    it('Manajer Outlet hanya melihat & mengelola perangkat di outlet yang ditugaskan', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        $utama = PerangkatUjiOutletUtama($tenant->Id);
        $cabang = PerangkatUjiBuatOutlet($tenant->Id, 'SOLO');
        $manajer = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $cabang->Id, 'IdPengguna' => $manajer->Id, 'IdPeran' => BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::ManajerOutlet)->Id]);
        $diUtama = BantuanPerangkat::BuatPerangkat($tenant->Id, $utama)['Perangkat'];
        BantuanPerangkat::BuatPerangkat($tenant->Id, $cabang);
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $manajer, $tenant->Id);

        $masuk()->get('/kelola/perangkat')->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->has('Perangkat', 1)
            ->where('Perangkat.0.Kode', 'SOLO-K01')
            ->has('Outlet', 1));
        $masuk()->post("/kelola/perangkat/{$diUtama->Uuid}/cabut")->assertNotFound();
        $masuk()->post('/kelola/perangkat', ['Nama' => 'Susupan', 'Outlet' => $utama->Uuid, 'Jenis' => 'Kasir'])->assertSessionHasErrors('Outlet');
        expect($diUtama->refresh()->DicabutPada)->toBeNull();
    });

    it('isolasi tenant: perangkat & outlet tenant B lewat Uuid tebakan diperlakukan sebagai tidak ada', function (): void {
        ['Tenant' => $tenantA, 'Pemilik' => $pemilikA] = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        ['Tenant' => $tenantB] = BantuanOrganisasi::BuatTenant('Toko Budi');
        $perangkatB = BantuanPerangkat::BuatPerangkat($tenantB->Id)['Perangkat'];
        $outletB = PerangkatUjiOutletUtama($tenantB->Id);
        $masukA = fn () => BantuanOrganisasi::Masuk($this, $pemilikA, $tenantA->Id);

        $masukA()->put("/kelola/perangkat/{$perangkatB->Uuid}", ['Nama' => 'Diambil'])->assertNotFound();
        $masukA()->post("/kelola/perangkat/{$perangkatB->Uuid}/kode-aktivasi")->assertNotFound();
        $masukA()->post("/kelola/perangkat/{$perangkatB->Uuid}/cabut")->assertNotFound();
        $masukA()->post('/kelola/perangkat', ['Nama' => 'Susupan', 'Outlet' => $outletB->Uuid, 'Jenis' => 'Kasir'])->assertSessionHasErrors('Outlet');
        $masukA()->get('/kelola/perangkat')->assertInertia(fn (AssertableInertia $halaman) => $halaman->has('Perangkat', 0));

        BantuanOrganisasi::AturKonteks($tenantB->Id);
        expect($perangkatB->refresh()->Nama)->toBe('Kasir Depan')->and($perangkatB->DicabutPada)->toBeNull()
            ->and(KodeAktivasi::query()->where('IdTenant', $tenantB->Id)->count())->toBe(1);
    });
    it('menampilkan tautan unduh aplikasi kasir beserta QR sebelum kode aktivasi dipakai (v7.35)', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        config(['aplikasi.Pos.Android.TautanUnduh' => 'https://play.google.com/store/apps/details?id=id.payoung.kasir']);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get('/kelola/perangkat')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/Perangkat/Daftar')
                ->has('UnduhanAplikasi', 1)
                ->where('UnduhanAplikasi.0.Platform', 'Android')
                ->where('UnduhanAplikasi.0.Label', 'Android')
                ->where('UnduhanAplikasi.0.Url', 'https://play.google.com/store/apps/details?id=id.payoung.kasir')
                ->where('UnduhanAplikasi.0.QrSvg', fn (string $svg) => str_contains($svg, '<svg')));
    });
});

describe('Log audit perangkat', function (): void {
    it('mencatat buat perangkat, buat kode, ubah nama, dan cabut tanpa menyimpan kode asli', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $outlet = PerangkatUjiOutletUtama($tenant->Id);
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $masuk()->post('/kelola/perangkat', ['Nama' => 'Kasir Depan', 'Outlet' => $outlet->Uuid, 'Jenis' => 'Kasir']);
        $kode = session('KodeAktivasiBaru')['Kode'];
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $perangkat = Perangkat::query()->sole();
        $masuk()->put("/kelola/perangkat/{$perangkat->Uuid}", ['Nama' => 'Kasir Utama']);
        $masuk()->post("/kelola/perangkat/{$perangkat->Uuid}/cabut");

        $log = LogAudit::query()->where('IdTenant', $tenant->Id)->where('JenisObjek', 'Perangkat')->orderBy('Id')->get();
        expect($log->pluck('Peristiwa')->all())->toBe(['perangkat.buat', 'perangkat.kode-aktivasi.buat', 'perangkat.ubah', 'perangkat.cabut'])
            ->and($log->pluck('IdPengguna')->unique()->all())->toBe([$pemilik->Id])
            ->and(json_encode($log->pluck('NilaiBaru')->all()))->not->toContain($kode);
    });
});
