<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\Peran;
use App\Domain\Organisasi\Model\PeranIzin;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

describe('Peran bawaan tenant (§19.1)', function (): void {
    it('pendaftaran membuat 8 peran bawaan dan Owner memegang peran Pemilik dengan akses semua outlet', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::AturKonteks($tenant->Id);

        expect(Peran::query()->where('Bawaan', true)->pluck('Kode')->sort()->values()->all())
            ->toBe(collect(PeranTenantBawaan::cases())->map->value->sort()->values()->all());

        $anggota = TenantPengguna::query()->where('IdTenant', $tenant->Id)->where('IdPengguna', $pemilik->Id)->sole();
        expect($anggota->Pemilik)->toBeTrue()
            ->and($anggota->SemuaOutlet)->toBeTrue()
            ->and($anggota->IdPeran)->toBe(BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Pemilik)->Id);
    });

    // v3.82: izin khusus Owner bertambah `integrasi.api.kelola` (token API publik), jadi Admin = semua − 2 izin.
    it('Admin memegang semua izin kecuali langganan & token API (khusus Owner); Kasir hanya berjualan', function (): void {
        $tenant = BantuanOrganisasi::BuatTenant()['Tenant'];

        $izinAdmin = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Admin)->AmbilKunciIzin();
        $izinKasir = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Kasir)->AmbilKunciIzin();

        expect($izinAdmin)->not->toContain(IzinTenant::LanggananKelola->value)
            ->and($izinAdmin)->not->toContain(IzinTenant::IntegrasiApiKelola->value)
            ->and(count($izinAdmin))->toBe(count(IzinTenant::cases()) - 2)
            ->and($izinKasir)->toBe([IzinTenant::PenjualanBuat->value, IzinTenant::ProdukLihat->value]);
    });

    it('perintah organisasi:siapkan-peran mengisi tenant lama dan idempoten', function (): void {
        $baru = BantuanOrganisasi::BuatTenant('Toko Baru')['Tenant'];
        // Tenant lama (sebelum F-02): tanpa peran, Owner tanpa IdPeran.
        $lama = Tenant::query()->create(['Nama' => 'Warung Lama', 'Slug' => 'warung-lama']);
        $pemilikLama = BantuanOrganisasi::BuatTenant('Pemilik Lain')['Pemilik'];
        TenantPengguna::query()->create(['IdTenant' => $lama->Id, 'IdPengguna' => $pemilikLama->Id, 'Pemilik' => true]);

        $this->artisan('organisasi:siapkan-peran')->assertSuccessful();
        $this->artisan('organisasi:siapkan-peran')->assertSuccessful();

        BantuanOrganisasi::AturKonteks($lama->Id);
        expect(Peran::query()->count())->toBe(count(PeranTenantBawaan::cases()));
        $anggotaLama = TenantPengguna::query()->where('IdTenant', $lama->Id)->sole();
        expect($anggotaLama->IdPeran)->toBe(BantuanOrganisasi::Peran($lama->Id, PeranTenantBawaan::Pemilik)->Id)
            ->and($anggotaLama->SemuaOutlet)->toBeTrue();

        BantuanOrganisasi::AturKonteks($baru->Id);
        expect(Peran::query()->count())->toBe(count(PeranTenantBawaan::cases()))
            ->and(PeranIzin::query()->where('IdPeran', BantuanOrganisasi::Peran($baru->Id, PeranTenantBawaan::Kasir)->Id)->count())->toBe(2);
    });

    it('F-01: Admin tenant lama menerima izin panduan-awal.kelola setelah organisasi:siapkan-peran; Kasir tidak', function (): void {
        $tenant = BantuanOrganisasi::BuatTenant()['Tenant'];
        $admin = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Admin);
        // Tenant yang terdaftar sebelum F-01 belum memegang izin baru ini.
        PeranIzin::query()->where('IdPeran', $admin->Id)->where('KunciIzin', IzinTenant::PanduanAwalKelola->value)->delete();
        expect($admin->AmbilKunciIzin())->not->toContain('panduan-awal.kelola');

        $this->artisan('organisasi:siapkan-peran')->assertSuccessful();

        expect(BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Admin)->AmbilKunciIzin())->toContain('panduan-awal.kelola')
            ->and(BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Kasir)->AmbilKunciIzin())->not->toContain('panduan-awal.kelola')
            ->and(IzinTenant::PanduanAwalKelola->AmbilKelompok())->toBe('Organisasi')
            ->and(IzinTenant::PanduanAwalKelola->CekKhususPemilik())->toBeFalse();
    });
});

describe('Izin per peran di rute back-office (WajibIzinTenant)', function (): void {
    it('membuka atau menolak halaman sesuai izin peran', function (PeranTenantBawaan $peran, string $alamat, bool $boleh): void {
        $tenant = BantuanOrganisasi::BuatTenant()['Tenant'];
        $anggota = BantuanOrganisasi::TambahAnggota($tenant->Id, $peran);

        $respons = BantuanOrganisasi::Masuk($this, $anggota, $tenant->Id)->get($alamat);

        $boleh ? $respons->assertOk() : $respons->assertForbidden()->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/TanpaIzin'));
    })->with([
        'Admin buka tim' => [PeranTenantBawaan::Admin, '/kelola/tim', true],
        'Admin buka log audit' => [PeranTenantBawaan::Admin, '/kelola/log-audit', true],
        'Manajer buka outlet' => [PeranTenantBawaan::ManajerOutlet, '/kelola/outlet', true],
        'Manajer buka tim' => [PeranTenantBawaan::ManajerOutlet, '/kelola/tim', true],
        'Manajer buka log audit' => [PeranTenantBawaan::ManajerOutlet, '/kelola/log-audit', false],
        'Akuntan buka outlet' => [PeranTenantBawaan::Akuntan, '/kelola/outlet', true],
        // Akuntan punya karyawan.lihat (rekap gaji) tetapi bukan pengguna.lihat: Tim terbuka, isinya hanya data karyawan (lihat TimTes).
        'Akuntan buka tim' => [PeranTenantBawaan::Akuntan, '/kelola/tim', true],
        'Kasir buka outlet' => [PeranTenantBawaan::Kasir, '/kelola/outlet', false],
        'Kasir buka peran' => [PeranTenantBawaan::Kasir, '/kelola/peran', false],
        'Staf gudang buka log audit' => [PeranTenantBawaan::StafGudang, '/kelola/log-audit', false],
    ]);

    it('menolak aksi ubah tanpa izin walau halaman lihatnya boleh dibuka', function (): void {
        $tenant = BantuanOrganisasi::BuatTenant()['Tenant'];
        $manajer = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::ManajerOutlet);

        BantuanOrganisasi::Masuk($this, $manajer, $tenant->Id)
            ->post('/kelola/outlet', ['Nama' => 'Cabang Solo', 'Kode' => 'SOLO'])
            ->assertForbidden();
        BantuanOrganisasi::Masuk($this, $manajer, $tenant->Id)
            ->post('/kelola/pengguna/undangan', ['Email' => 'kasir@contoh.id'])
            ->assertForbidden();
    });

    it('Pemilik selalu lolos walau izin peran Pemilik di tabel terhapus', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        PeranIzin::query()->where('IdPeran', BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Pemilik)->Id)->delete();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get('/kelola/log-audit')->assertOk();
    });

    it('izin & status Pemilik dibagikan ke Inertia untuk menu', function (): void {
        $tenant = BantuanOrganisasi::BuatTenant()['Tenant'];
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);

        BantuanOrganisasi::Masuk($this, $kasir, $tenant->Id)->get('/kelola')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('Akses.Pemilik', false)
                ->where('Akses.Izin', [IzinTenant::PenjualanBuat->value, IzinTenant::ProdukLihat->value]));
    });
});

describe('Peran kustom', function (): void {
    it('Pemilik membuat & mengubah peran kustom; perubahan tercatat di log audit', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/peran', ['Nama' => 'Kasir Senior', 'Izin' => ['penjualan.buat', 'penjualan.diskon.manual']])
            ->assertSessionHasNoErrors();
        $peran = Peran::query()->where('Nama', 'Kasir Senior')->sole();
        expect($peran->Bawaan)->toBeFalse()->and($peran->AmbilKunciIzin())->toBe(['penjualan.buat', 'penjualan.diskon.manual']);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->put("/kelola/peran/{$peran->Uuid}", ['Nama' => 'Kasir Senior', 'Izin' => ['penjualan.buat', 'penjualan.void']])
            ->assertSessionHasNoErrors();
        expect($peran->refresh()->AmbilKunciIzin())->toBe(['penjualan.buat', 'penjualan.void']);

        $this->assertDatabaseHas('LogAudit', ['IdTenant' => $tenant->Id, 'Peristiwa' => 'peran.buat', 'IdPengguna' => $pemilik->Id]);
        $this->assertDatabaseHas('LogAudit', ['IdTenant' => $tenant->Id, 'Peristiwa' => 'peran.ubah', 'IdObjek' => $peran->Id]);
    });

    it('halaman buat peran (halaman penuh): daftar izin; tanpa peran.kelola 403; simpan kembali ke daftar peran', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get('/kelola/peran/buat')->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/Peran/Buat')
                ->has('DaftarIzin', count(IzinTenant::cases()))
                ->has('DaftarIzin.0', fn (AssertableInertia $izin) => $izin->hasAll(['Kunci', 'Label', 'Kelompok', 'KhususPemilik'])));

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/peran', ['Nama' => 'Barista', 'Izin' => ['penjualan.buat']])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/kelola/peran');

        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $kasir, $tenant->Id)->get('/kelola/peran/buat')->assertForbidden();
    });

    it('menolak izin khusus Pemilik, izin tak dikenal, dan pengubahan peran bawaan', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        $kasir = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Kasir);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/peran', ['Nama' => 'Bendahara', 'Izin' => ['langganan.kelola']])
            ->assertSessionHasErrors('Izin');
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/peran', ['Nama' => 'Aneh', 'Izin' => ['sistem.hapus-semua']])
            ->assertSessionHasErrors('Izin');
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->put("/kelola/peran/{$kasir->Uuid}", ['Nama' => 'Kasir', 'Izin' => ['penjualan.buat', 'penjualan.void']])
            ->assertSessionHasErrors('Umum');

        expect($kasir->refresh()->AmbilKunciIzin())->toBe(['penjualan.buat', 'produk.lihat']);
    });

    it('anti-eskalasi: Admin tidak bisa membuat peran dengan izin yang tidak ia miliki (Manajer tanpa peran.kelola ditolak)', function (): void {
        $tenant = BantuanOrganisasi::BuatTenant()['Tenant'];
        $admin = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Admin);
        $manajer = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::ManajerOutlet);

        BantuanOrganisasi::Masuk($this, $admin, $tenant->Id)
            ->post('/kelola/peran', ['Nama' => 'Supervisor Plus', 'Izin' => ['penjualan.void', 'audit.lihat']])
            ->assertSessionHasNoErrors();
        BantuanOrganisasi::Masuk($this, $manajer, $tenant->Id)
            ->post('/kelola/peran', ['Nama' => 'Coba', 'Izin' => ['penjualan.void']])
            ->assertForbidden();

        // Kustom: peran berizin terbatas yang boleh kelola peran tidak bisa memberi izin di luar miliknya.
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $terbatas = Peran::query()->create(['Nama' => 'Pengatur Peran']);
        foreach (['peran.kelola', 'penjualan.buat'] as $kunci) {
            PeranIzin::query()->create(['IdPeran' => $terbatas->Id, 'KunciIzin' => $kunci]);
        }
        $pengatur = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        TenantPengguna::query()->where('IdPengguna', $pengatur->Id)->update(['IdPeran' => $terbatas->Id]);

        BantuanOrganisasi::Masuk($this, $pengatur, $tenant->Id)
            ->post('/kelola/peran', ['Nama' => 'Naik Pangkat', 'Izin' => ['penjualan.buat', 'audit.lihat']])
            ->assertSessionHasErrors('Izin');
        expect(Peran::query()->where('Nama', 'Naik Pangkat')->exists())->toBeFalse();
    });

    it('peran yang dipakai anggota nonaktif bisa dilepas dengan mengganti peran anggota itu, tanpa mengaktifkannya kembali', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/peran', ['Nama' => 'Barista Lama', 'Izin' => ['penjualan.buat']]);
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $lama = Peran::query()->where('Nama', 'Barista Lama')->sole();
        $kasirPeran = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Kasir);
        $anggota = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        TenantPengguna::query()->where('IdPengguna', $anggota->Id)->update(['IdPeran' => $lama->Id, 'Status' => StatusKeanggotaan::Nonaktif->value]);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->delete("/kelola/peran/{$lama->Uuid}")->assertSessionHasErrors('Umum');

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->put("/kelola/pengguna/{$anggota->Uuid}/akses", ['Peran' => $kasirPeran->Uuid, 'SemuaOutlet' => true])
            ->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(TenantPengguna::query()->where('IdPengguna', $anggota->Id)->sole()->Status)->toBe(StatusKeanggotaan::Nonaktif);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->delete("/kelola/peran/{$lama->Uuid}")->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Peran::query()->whereKey($lama->Id)->exists())->toBeFalse();
    });

    it('peran kustom yang masih dipakai tidak bisa dihapus; yang tidak dipakai bisa', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/peran', ['Nama' => 'Barista', 'Izin' => ['penjualan.buat']]);
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/peran', ['Nama' => 'Kurir', 'Izin' => ['penjualan.buat']]);
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $barista = Peran::query()->where('Nama', 'Barista')->sole();
        $kurir = Peran::query()->where('Nama', 'Kurir')->sole();
        $anggota = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        TenantPengguna::query()->where('IdPengguna', $anggota->Id)->update(['IdPeran' => $barista->Id]);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->delete("/kelola/peran/{$barista->Uuid}")->assertSessionHasErrors('Umum');
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->delete("/kelola/peran/{$kurir->Uuid}")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Peran::query()->whereKey($barista->Id)->exists())->toBeTrue()
            ->and(Peran::query()->whereKey($kurir->Id)->exists())->toBeFalse();
    });
});
