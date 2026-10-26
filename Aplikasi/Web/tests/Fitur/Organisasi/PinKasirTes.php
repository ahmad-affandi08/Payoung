<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Tenant\Enum\StatusLangganan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

function PinUjiHash(int $idTenant, int $idPengguna): ?string
{
    return TenantPengguna::query()->where('IdTenant', $idTenant)->where('IdPengguna', $idPengguna)->value('HashPin');
}

describe('F-02 langkah 4: pengguna mengatur PIN kasir miliknya', function (): void {
    it('menyimpan PIN 6 angka sebagai hash dan mencatat log audit tanpa PIN', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);

        BantuanOrganisasi::Masuk($this, $kasir, $tenant->Id)->get('/kelola/keamanan/pin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/Pin')->where('PinSayaDiatur', false)->where('Anggota', null));
        BantuanOrganisasi::Masuk($this, $kasir, $tenant->Id)
            ->put('/kelola/keamanan/pin', ['Pin' => '482915', 'KonfirmasiPin' => '482915'])
            ->assertSessionHasNoErrors();

        $hash = PinUjiHash($tenant->Id, $kasir->Id);
        expect($hash)->not->toBeNull()->not->toBe('482915')
            ->and(Hash::check('482915', (string) $hash))->toBeTrue();
        $log = LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'pengguna.pin.atur')->sole();
        expect($log->IdPengguna)->toBe($kasir->Id)->and(json_encode($log->NilaiBaru))->not->toContain('482915');
    });

    it('menolak PIN lemah, bukan 6 angka, atau konfirmasi berbeda', function (string $pin, string $konfirmasi, string $bidang): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->put('/kelola/keamanan/pin', ['Pin' => $pin, 'KonfirmasiPin' => $konfirmasi])
            ->assertSessionHasErrors($bidang);
        expect(PinUjiHash($tenant->Id, $pemilik->Id))->toBeNull();
    })->with([
        'angka sama semua' => ['777777', '777777', 'Pin'],
        'urut naik' => ['123456', '123456', 'Pin'],
        'urut turun' => ['654321', '654321', 'Pin'],
        'urut dari nol' => ['012345', '012345', 'Pin'],
        'lima angka' => ['48291', '48291', 'Pin'],
        'huruf' => ['48291a', '48291a', 'Pin'],
        'konfirmasi beda' => ['482915', '482916', 'KonfirmasiPin'],
    ]);
});

describe('Atur ulang PIN anggota (Pemilik/Admin/Manajer, izin pengguna.pin.atur)', function (): void {
    it('Pemilik mengatur ulang PIN kasir; halaman hanya menampilkan status, bukan PIN', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        BantuanPerangkat::AturPin($tenant->Id, $kasir->Id, '482915');

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get('/kelola/keamanan/pin')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->has('Anggota', 1)
                ->where('Anggota.0.Uuid', $kasir->Uuid)
                ->where('Anggota.0.PinDiatur', true)
                ->missing('Anggota.0.HashPin'));

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->put("/kelola/pengguna/{$kasir->Uuid}/pin", ['Pin' => '703518', 'KonfirmasiPin' => '703518'])
            ->assertSessionHasNoErrors();

        expect(Hash::check('703518', (string) PinUjiHash($tenant->Id, $kasir->Id)))->toBeTrue()
            ->and(LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'pengguna.pin.atur-ulang')->sole()->IdPengguna)->toBe($pemilik->Id);
    });

    it('Kasir tidak punya izin mengatur ulang PIN anggota lain', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);

        BantuanOrganisasi::Masuk($this, $kasir, $tenant->Id)
            ->put("/kelola/pengguna/{$pemilik->Uuid}/pin", ['Pin' => '703518', 'KonfirmasiPin' => '703518'])
            ->assertForbidden();
        expect(PinUjiHash($tenant->Id, $pemilik->Id))->toBeNull();
    });

    it('Admin tidak bisa mengatur ulang PIN Pemilik, dan tidak ada yang mengatur ulang PIN sendiri lewat jalur ini', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $admin = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Admin);

        BantuanOrganisasi::Masuk($this, $admin, $tenant->Id)
            ->put("/kelola/pengguna/{$pemilik->Uuid}/pin", ['Pin' => '703518', 'KonfirmasiPin' => '703518'])
            ->assertSessionHasErrors(['Pin' => 'Hanya Pemilik yang bisa mengatur ulang PIN Pemilik lain.']);
        BantuanOrganisasi::Masuk($this, $admin, $tenant->Id)
            ->put("/kelola/pengguna/{$admin->Uuid}/pin", ['Pin' => '703518', 'KonfirmasiPin' => '703518'])
            ->assertSessionHasErrors('Pin');
        expect(PinUjiHash($tenant->Id, $pemilik->Id))->toBeNull()->and(PinUjiHash($tenant->Id, $admin->Id))->toBeNull();
    });

    it('Manajer Outlet hanya mengatur ulang PIN anggota di outlet yang ia kelola', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $utama = Outlet::query()->orderBy('Id')->firstOrFail();
        $solo = Outlet::query()->create(['IdMerek' => Merek::query()->value('Id'), 'Kode' => 'SOLO', 'Nama' => 'Cabang Solo']);
        $peranManajer = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::ManajerOutlet);
        $peranKasir = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Kasir);
        $manajer = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
        $kasirSolo = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir, semuaOutlet: false);
        $kasirUtama = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $solo->Id, 'IdPengguna' => $manajer->Id, 'IdPeran' => $peranManajer->Id]);
        OutletPengguna::query()->create(['IdOutlet' => $solo->Id, 'IdPengguna' => $kasirSolo->Id, 'IdPeran' => $peranKasir->Id]);
        OutletPengguna::query()->create(['IdOutlet' => $utama->Id, 'IdPengguna' => $kasirUtama->Id, 'IdPeran' => $peranKasir->Id]);
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $manajer, $tenant->Id);

        $masuk()->get('/kelola/keamanan/pin')->assertInertia(fn (AssertableInertia $halaman) => $halaman->has('Anggota', 1)->where('Anggota.0.Uuid', $kasirSolo->Uuid));
        $masuk()->put("/kelola/pengguna/{$kasirSolo->Uuid}/pin", ['Pin' => '703518', 'KonfirmasiPin' => '703518'])->assertSessionHasNoErrors();
        $masuk()->put("/kelola/pengguna/{$kasirUtama->Uuid}/pin", ['Pin' => '703518', 'KonfirmasiPin' => '703518'])->assertSessionHasErrors('Pin');

        expect(PinUjiHash($tenant->Id, $kasirSolo->Id))->not->toBeNull()->and(PinUjiHash($tenant->Id, $kasirUtama->Id))->toBeNull();
    });

    it('isolasi tenant: anggota tenant B lewat Uuid tebakan tidak ditemukan', function (): void {
        ['Tenant' => $tenantA, 'Pemilik' => $pemilikA] = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        ['Tenant' => $tenantB] = BantuanOrganisasi::BuatTenant('Toko Budi');
        $kasirB = BantuanOrganisasi::TambahAnggota($tenantB->Id, PeranTenantBawaan::Kasir);

        BantuanOrganisasi::Masuk($this, $pemilikA, $tenantA->Id)
            ->put("/kelola/pengguna/{$kasirB->Uuid}/pin", ['Pin' => '703518', 'KonfirmasiPin' => '703518'])
            ->assertNotFound();
        expect(PinUjiHash($tenantB->Id, $kasirB->Id))->toBeNull();
    });
});

describe('POST /api/pos/v1/kasir/masuk-pin (§20.2)', function (): void {
    it('PIN benar mengembalikan pengguna & izinnya', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        BantuanPerangkat::AturPin($tenant->Id, $kasir->Id, '482915');
        ['Perangkat' => $perangkat, 'Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);

        $this->withToken($token)->postJson('/api/pos/v1/kasir/masuk-pin', ['UuidPengguna' => $kasir->Uuid, 'Pin' => '482915'])
            ->assertOk()
            ->assertExactJson([
                'Pengguna' => ['Uuid' => $kasir->Uuid, 'Nama' => $kasir->Nama],
                'Pemilik' => false,
                'Izin' => ['penjualan.buat', 'produk.lihat'],
            ]);

        $log = LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'kasir.masuk-pin')->sole();
        expect($log->IdPengguna)->toBe($kasir->Id)->and($log->IdPerangkat)->toBe($perangkat->Id);
    });

    it('5 kali PIN salah mengunci 5 menit per perangkat + pengguna; PIN benar pun ditolak selama terkunci', function (): void {
        // Waktu dibekukan: tanpa itu sisa kunci bisa 299 detik bila jam melewati batas detik di tengah tes.
        $this->freezeTime();

        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        BantuanPerangkat::AturPin($tenant->Id, $kasir->Id, '482915');
        ['Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);
        ['Token' => $tokenLain] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id, nama: 'Kasir Belakang');
        $kirim = fn (string $tokenPerangkat, string $pin) => $this->withToken($tokenPerangkat)->postJson('/api/pos/v1/kasir/masuk-pin', ['UuidPengguna' => $kasir->Uuid, 'Pin' => $pin]);

        foreach ([4, 3, 2, 1] as $sisa) {
            $kirim($token, '000001')->assertStatus(422)->assertJsonPath('Galat.Kode', 'PinSalah')->assertJsonPath('Galat.Detail.SisaPercobaan', $sisa);
        }

        $kirim($token, '000001')->assertStatus(429)->assertJsonPath('Galat.Kode', 'PinTerkunci')->assertJsonPath('Galat.Detail.DetikTersisa', 300);
        $kirim($token, '482915')->assertStatus(429)->assertJsonPath('Galat.Kode', 'PinTerkunci');
        // Kunci per perangkat: perangkat lain tetap bisa dipakai.
        $kirim($tokenLain, '482915')->assertOk();
        expect(LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'kasir.pin.terkunci')->count())->toBe(1);

        $this->travel(301)->seconds();
        $kirim($token, '482915')->assertOk();
        // Setelah berhasil, hitungan gagal mulai dari awal.
        $kirim($token, '000001')->assertJsonPath('Galat.Detail.SisaPercobaan', 4);
    });

    it('pengguna tenant lain, anggota nonaktif, atau di luar outlet perangkat → 404 KasirTidakDitemukan', function (): void {
        ['Tenant' => $tenantA] = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        ['Tenant' => $tenantB] = BantuanOrganisasi::BuatTenant('Toko Budi');
        $kasirB = BantuanOrganisasi::TambahAnggota($tenantB->Id, PeranTenantBawaan::Kasir);
        BantuanPerangkat::AturPin($tenantB->Id, $kasirB->Id, '482915');
        $nonaktif = BantuanOrganisasi::TambahAnggota($tenantA->Id, PeranTenantBawaan::Kasir);
        BantuanPerangkat::AturPin($tenantA->Id, $nonaktif->Id, '482915');
        TenantPengguna::query()->where('IdTenant', $tenantA->Id)->where('IdPengguna', $nonaktif->Id)->update(['Status' => 'Nonaktif']);
        $tanpaOutlet = BantuanOrganisasi::TambahAnggota($tenantA->Id, PeranTenantBawaan::Kasir, semuaOutlet: false);
        BantuanPerangkat::AturPin($tenantA->Id, $tanpaOutlet->Id, '482915');
        ['Token' => $tokenA] = BantuanPerangkat::BuatDanAktifkan($this, $tenantA->Id);

        foreach ([$kasirB, $nonaktif, $tanpaOutlet] as $pengguna) {
            $this->withToken($tokenA)->postJson('/api/pos/v1/kasir/masuk-pin', ['UuidPengguna' => $pengguna->Uuid, 'Pin' => '482915'])
                ->assertNotFound()
                ->assertJsonPath('Galat.Kode', 'KasirTidakDitemukan');
        }
    });

    it('PIN belum diatur ditolak dengan petunjuk', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        ['Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);

        $this->withToken($token)->postJson('/api/pos/v1/kasir/masuk-pin', ['UuidPengguna' => $kasir->Uuid, 'Pin' => '482915'])
            ->assertStatus(422)
            ->assertJsonPath('Galat.Kode', 'PinBelumDiatur');
    });

    it('POS terkunci saat langganan ditangguhkan, kecuali tenant turun ke paket Gratis', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        BantuanPerangkat::AturPin($tenant->Id, $kasir->Id, '482915');
        ['Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);
        $kirim = fn () => $this->withToken($token)->postJson('/api/pos/v1/kasir/masuk-pin', ['UuidPengguna' => $kasir->Uuid, 'Pin' => '482915']);

        BantuanPerangkat::AturStatusLangganan($tenant->Id, StatusLangganan::Ditangguhkan);
        $kirim()->assertForbidden()->assertJsonPath('Galat.Kode', 'LanggananTidakAktif')->assertJsonPath('Galat.Detail.StatusLangganan', 'Ditangguhkan');
        BantuanPerangkat::AturStatusLangganan($tenant->Id, StatusLangganan::Berhenti);
        $kirim()->assertForbidden();
        BantuanPerangkat::AturStatusLangganan($tenant->Id, StatusLangganan::Gratis);
        $kirim()->assertOk();
    });
});
