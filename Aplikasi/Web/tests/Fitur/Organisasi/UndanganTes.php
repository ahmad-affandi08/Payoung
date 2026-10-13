<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Organisasi\Model\UndanganPengguna;
use App\Domain\Organisasi\Surel\UndanganAnggota;
use App\Http\Perantara\IdentifikasiTenantSesi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/**
 * Mengundang lewat HTTP sebagai `$pengundang` lalu mengembalikan path tautan undangan dari email. Sesi dikosongkan
 * setelahnya agar test bisa bertindak sebagai penerima.
 *
 * @param  array<string, mixed>  $akses
 */
function UndangAnggotaUji(TestCase $tes, Pengguna $pengundang, int $idTenant, string $email, array $akses = []): string
{
    BantuanOrganisasi::AturKonteks($idTenant);
    $akses = [
        'Peran' => BantuanOrganisasi::Peran($idTenant, PeranTenantBawaan::Kasir)->Uuid,
        'SemuaOutlet' => false,
        'Outlet' => [Outlet::query()->orderBy('Id')->value('Uuid')],
        ...$akses,
    ];

    BantuanOrganisasi::Masuk($tes, $pengundang, $idTenant)
        ->post('/kelola/pengguna/undangan', ['Email' => $email, ...$akses])
        ->assertSessionHasNoErrors();

    $tautan = null;
    Mail::assertQueued(UndanganAnggota::class, function (UndanganAnggota $surel) use ($email, &$tautan): bool {
        if (! $surel->hasTo(strtolower($email))) {
            return false;
        }

        $tautan = $surel->Tautan();

        return true;
    });

    $tes->post('/keluar')->assertRedirect(route('masuk'));
    $tes->flushSession();

    return (string) parse_url((string) $tautan, PHP_URL_PATH);
}

/**
 * @return array<string, string>
 */
function IsianAkunBaruUji(): array
{
    return ['Nama' => 'Dewi Lestari', 'NoHp' => '081377778888', 'KataSandi' => 'kasir12345', 'KonfirmasiKataSandi' => 'kasir12345'];
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-24 09:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

describe('Mengundang pengguna (F-02 langkah 3)', function (): void {
    it('undangan berlaku 72 jam, token hanya tersimpan sebagai hash, peran & outlet tercatat, masuk log audit', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();

        $path = UndangAnggotaUji($this, $pemilik, $tenant->Id, 'Dewi@Contoh.id');
        $token = basename($path);

        $undangan = UndanganPengguna::query()->sole();
        expect($undangan->Email)->toBe('dewi@contoh.id')
            ->and($undangan->HashToken)->toBe(hash('sha256', $token))
            ->and($undangan->BerlakuSampai->diffInHours(now(), true))->toEqualWithDelta(72, 0.01)
            ->and($undangan->SemuaOutlet)->toBeFalse()
            ->and($undangan->DaftarIdOutlet)->toHaveCount(1);
        $this->assertDatabaseHas('LogAudit', ['IdTenant' => $tenant->Id, 'Peristiwa' => 'pengguna.undang', 'IdPengguna' => $pemilik->Id]);
    });

    it('mengundang ulang email yang sama membatalkan undangan lama', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();

        $lama = UndangAnggotaUji($this, $pemilik, $tenant->Id, 'dewi@contoh.id');
        UndangAnggotaUji($this, $pemilik, $tenant->Id, 'dewi@contoh.id');

        expect(UndanganPengguna::query()->whereNull('DibatalkanPada')->count())->toBe(1);
        $this->get($lama)->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Undangan/Terima')->where('Berlaku', false));
    });

    it('menolak email yang sudah menjadi anggota aktif atau nonaktif tenant ini', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        $mantan = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        TenantPengguna::query()->where('IdPengguna', $mantan->Id)->update(['Status' => StatusKeanggotaan::Nonaktif->value]);
        $peranKasir = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Kasir)->Uuid;

        foreach ([$kasir->Email, $mantan->Email, $pemilik->Email] as $email) {
            BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
                ->post('/kelola/pengguna/undangan', ['Email' => $email, 'Peran' => $peranKasir, 'SemuaOutlet' => true])
                ->assertSessionHasErrors('Email');
        }
        expect(UndanganPengguna::query()->count())->toBe(0);
    });

    it('tanpa semua outlet wajib memilih minimal satu outlet aktif', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/pengguna/undangan', ['Email' => 'dewi@contoh.id', 'Peran' => BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Kasir)->Uuid, 'SemuaOutlet' => false, 'Outlet' => []])
            ->assertSessionHasErrors('Outlet');
    });

    it('Admin tidak bisa mengundang dengan peran Pemilik (hanya Pemilik yang bisa menunjuk Pemilik)', function (): void {
        $tenant = BantuanOrganisasi::BuatTenant()['Tenant'];
        $admin = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Admin);

        BantuanOrganisasi::Masuk($this, $admin, $tenant->Id)
            ->post('/kelola/pengguna/undangan', ['Email' => 'calon.owner@contoh.id', 'Peran' => BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Pemilik)->Uuid, 'SemuaOutlet' => true])
            ->assertSessionHasErrors('Peran');
    });

    it('undangan yang dibatalkan tidak bisa dipakai', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $path = UndangAnggotaUji($this, $pemilik, $tenant->Id, 'dewi@contoh.id');
        $undangan = UndanganPengguna::query()->sole();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post("/kelola/pengguna/undangan/{$undangan->Uuid}/batalkan")->assertSessionHasNoErrors();
        $this->post('/keluar');

        $this->post($path, IsianAkunBaruUji())->assertSessionHasErrors('Umum');
        expect(Pengguna::query()->where('Email', 'dewi@contoh.id')->exists())->toBeFalse();
    });
});

describe('Menerima undangan (BR-00.1)', function (): void {
    it('penerima tanpa akun membuat akun baru (email terverifikasi), langsung masuk ke tenant, dan mendapat outlet yang ditugaskan', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $path = UndangAnggotaUji($this, $pemilik, $tenant->Id, 'dewi@contoh.id');

        $this->get($path)->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Undangan/Terima')
            ->where('Berlaku', true)
            ->where('Email', 'dewi@contoh.id')
            ->where('NamaTenant', 'Kopi Nusantara')
            ->where('AkunAda', false));

        $this->post($path, IsianAkunBaruUji())->assertRedirect(route('kelola.beranda'));

        $dewi = Pengguna::query()->where('Email', 'dewi@contoh.id')->sole();
        $this->assertAuthenticatedAs($dewi, 'web');
        $this->assertEquals($tenant->Id, session(IdentifikasiTenantSesi::KUNCI_SESI));
        expect($dewi->EmailDiverifikasiPada)->not->toBeNull()->and($dewi->NoHp)->toBe('081377778888');

        $anggota = TenantPengguna::query()->where('IdTenant', $tenant->Id)->where('IdPengguna', $dewi->Id)->sole();
        expect($anggota->IdPeran)->toBe(BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Kasir)->Id)
            ->and($anggota->Pemilik)->toBeFalse()
            ->and($anggota->SemuaOutlet)->toBeFalse();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(OutletPengguna::query()->where('IdPengguna', $dewi->Id)->count())->toBe(1);
        expect(UndanganPengguna::query()->sole()->IdPenggunaPenerima)->toBe($dewi->Id);
        $this->assertDatabaseHas('LogAudit', ['IdTenant' => $tenant->Id, 'Peristiwa' => 'pengguna.terima-undangan', 'IdPengguna' => $dewi->Id]);
    });

    it('undangan yang sudah dipakai tidak bisa dipakai dua kali', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $path = UndangAnggotaUji($this, $pemilik, $tenant->Id, 'dewi@contoh.id');

        $this->post($path, IsianAkunBaruUji())->assertSessionHasNoErrors();
        $this->post('/keluar');

        $this->post($path, [...IsianAkunBaruUji(), 'NoHp' => '081399990000'])->assertSessionHasErrors('Umum');
        $this->get($path)->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Berlaku', false));
        expect(Pengguna::query()->where('Email', 'dewi@contoh.id')->count())->toBe(1);
    });

    it('undangan kedaluwarsa setelah 72 jam', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $path = UndangAnggotaUji($this, $pemilik, $tenant->Id, 'dewi@contoh.id');

        $this->travel(72)->hours();
        $this->travel(1)->minutes();

        $this->get($path)->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Berlaku', false));
        $this->post($path, IsianAkunBaruUji())->assertSessionHasErrors('Umum');
        expect(Pengguna::query()->where('Email', 'dewi@contoh.id')->exists())->toBeFalse();
    });

    it('email yang sudah terdaftar di tenant lain wajib masuk dulu, lalu akunnya ditautkan (satu pengguna banyak tenant)', function (): void {
        ['Tenant' => $tokoBudi, 'Pemilik' => $budi] = BantuanOrganisasi::BuatTenant('Toko Budi');
        ['Tenant' => $kopi, 'Pemilik' => $rina] = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        $path = UndangAnggotaUji($this, $rina, $kopi->Id, strtoupper($budi->Email), [
            'Peran' => BantuanOrganisasi::Peran($kopi->Id, PeranTenantBawaan::Akuntan)->Uuid,
            'SemuaOutlet' => true,
        ]);

        $this->get($path)->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('AkunAda', true)->where('EmailMasuk', null));
        $this->post($path, IsianAkunBaruUji())->assertSessionHasErrors('Umum');

        // Masuk lalu kembali ke tautan undangan (redirect intended).
        $this->post('/masuk', ['Email' => $budi->Email, 'KataSandi' => 'kata-sandi-kuat-123'])->assertRedirect($path);
        $this->post($path)->assertRedirect(route('kelola.beranda'));

        $this->assertEquals($kopi->Id, session(IdentifikasiTenantSesi::KUNCI_SESI));
        expect(TenantPengguna::query()->where('IdPengguna', $budi->Id)->where('Status', 'Aktif')->pluck('IdTenant')->sort()->values()->all())
            ->toBe(collect([$tokoBudi->Id, $kopi->Id])->sort()->values()->all());
        expect(Pengguna::query()->where('Email', $budi->Email)->count())->toBe(1);

        // Pemilih tenant kini menampilkan dua usaha.
        $this->get('/pilih-tenant')->assertInertia(fn (AssertableInertia $halaman) => $halaman->has('Tenant', 2));
    });

    it('pengguna yang masuk dengan email lain tidak bisa memakai undangan orang lain', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $orangLain = BantuanOrganisasi::BuatTenant('Toko Lain')['Pemilik'];
        $path = UndangAnggotaUji($this, $pemilik, $tenant->Id, 'dewi@contoh.id');

        $this->actingAs($orangLain, 'web')->get($path)
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('EmailMasuk', $orangLain->Email));
        $this->actingAs($orangLain, 'web')->post($path)->assertSessionHasErrors('Umum');
        expect(TenantPengguna::query()->where('IdTenant', $tenant->Id)->where('IdPengguna', $orangLain->Id)->exists())->toBeFalse();
    });

    it('memvalidasi isian akun baru', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $path = UndangAnggotaUji($this, $pemilik, $tenant->Id, 'dewi@contoh.id');

        $this->post($path, [...IsianAkunBaruUji(), 'KataSandi' => 'pendek', 'KonfirmasiKataSandi' => 'pendek'])->assertSessionHasErrors('KataSandi');
        $this->post($path, [...IsianAkunBaruUji(), 'NoHp' => $pemilik->NoHp])->assertSessionHasErrors('NoHp');
        expect(Pengguna::query()->where('Email', 'dewi@contoh.id')->exists())->toBeFalse();
    });
});
