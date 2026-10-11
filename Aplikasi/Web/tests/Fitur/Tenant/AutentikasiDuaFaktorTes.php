<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Aksi\VerifikasiDuaFaktorPengguna;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Tenant\Aksi\DaftarkanTenant;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Model\Tenant;
use App\Http\Perantara\IdentifikasiTenantSesi;
use App\Http\Perantara\SesiAutentikasiTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanAutentikasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @return array{Tenant: Tenant, Pengguna: Pengguna}
 */
function DaftarkanTenantDuaFaktorUji(?string $kodePaket = null): array
{
    return app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data(kodePaket: $kodePaket));
}

/** D-38: kewajiban 2FA ditunda selama trial; test kewajiban memakai tenant yang trial-nya sudah berakhir (Aktif). */
function AkhiriTrialDuaFaktorUji(int $idTenant): void
{
    DB::table('Langganan')->where('IdTenant', $idTenant)->update(['Status' => StatusLangganan::Aktif->value]);
}

describe('Aktivasi 2FA (§20.2, BR-00.8)', function (): void {
    it('Owner mengaktifkan 2FA lewat QR + kode pertama; kode pemulihan tampil sekali dan tersimpan terenkripsi', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pengguna] = DaftarkanTenantDuaFaktorUji();
        $this->actingAs($pengguna, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $tenant->Id]);

        $this->get('/kelola/keamanan')->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Autentikasi/KeamananAkun')
            ->where('DuaFaktor.Aktif', false)
            ->where('DuaFaktor.Wajib', false)
            ->where('Aktivasi.QrSvg', fn (string $svg) => str_contains($svg, '<svg'))
            ->where('KodePemulihanBaru', null));
        $rahasia = session(SesiAutentikasiTenant::RAHASIA_2FA_SEMENTARA);
        expect($rahasia)->toBeString();

        $this->post('/kelola/keamanan/dua-faktor', ['Kode' => BantuanAutentikasi::KodeSalah($rahasia)])->assertSessionHasErrors('Kode');
        expect($pengguna->refresh()->CekDuaFaktorAktif())->toBeFalse();

        $this->post('/kelola/keamanan/dua-faktor', ['Kode' => BantuanAutentikasi::KodeSaatIni($rahasia)])
            ->assertRedirect(route('kelola.keamanan'))
            ->assertSessionMissing('Kilat');

        $pengguna->refresh();
        expect($pengguna->CekDuaFaktorAktif())->toBeTrue()
            ->and($pengguna->Rahasia2fa)->toBe($rahasia)
            ->and($pengguna->KodePemulihan2fa)->toHaveCount(8);

        $baris = DB::table('Pengguna')->where('Id', $pengguna->Id)->first();
        expect($baris?->KodePemulihan2fa)->not->toContain($pengguna->KodePemulihan2fa[0])
            ->and($baris?->Rahasia2fa)->not->toBe($rahasia);

        $this->get('/kelola/keamanan')->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->where('DuaFaktor.Aktif', true)
            ->where('Aktivasi', null)
            ->where('KodePemulihanBaru', $pengguna->KodePemulihan2fa));
        $this->get('/kelola/keamanan')->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('KodePemulihanBaru', null));
    });

    it('menonaktifkan 2FA wajib konfirmasi kata sandi', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pengguna] = DaftarkanTenantDuaFaktorUji();
        BantuanAutentikasi::AktifkanDuaFaktor($pengguna);
        $this->actingAs($pengguna, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $tenant->Id]);

        $this->delete('/kelola/keamanan/dua-faktor', ['KataSandi' => 'salah-sekali-1'])->assertSessionHasErrors('KataSandi');
        expect($pengguna->refresh()->CekDuaFaktorAktif())->toBeTrue();

        $this->delete('/kelola/keamanan/dua-faktor', ['KataSandi' => BantuanAutentikasi::KATA_SANDI])->assertSessionHasNoErrors();
        $pengguna->refresh();
        expect($pengguna->CekDuaFaktorAktif())->toBeFalse()
            ->and($pengguna->Rahasia2fa)->toBeNull()
            ->and($pengguna->KodePemulihan2fa)->toBeNull();
    });
});

describe('Masuk dua langkah (BR-00.8)', function (): void {
    it('kata sandi benar belum membuat pengguna masuk; /kelola tertutup sampai kode 2FA terverifikasi', function (): void {
        ['Pengguna' => $pengguna] = DaftarkanTenantDuaFaktorUji();
        $rahasia = BantuanAutentikasi::AktifkanDuaFaktor($pengguna);

        $this->post('/masuk', ['Email' => 'rina@kopinusantara.id', 'KataSandi' => BantuanAutentikasi::KATA_SANDI, 'Ingat' => true])
            ->assertRedirect(route('masuk.dua-faktor'));
        $this->assertGuest('web');
        $this->get('/pilih-tenant')->assertRedirect(route('masuk'));
        $this->get('/kelola')->assertRedirect(route('masuk'));
        $this->get('/masuk/dua-faktor')->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Autentikasi/VerifikasiDuaFaktor'));

        $this->post('/masuk/dua-faktor', ['Kode' => BantuanAutentikasi::KodeSalah($rahasia)])->assertSessionHasErrors('Kode');
        $this->assertGuest('web');

        $this->post('/masuk/dua-faktor', ['Kode' => BantuanAutentikasi::KodeSaatIni($rahasia)])->assertRedirect(route('kelola.beranda'));
        $this->assertAuthenticatedAs($pengguna, 'web');
        $this->get('/kelola')->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/Beranda'));
    });

    it('kode TOTP yang sudah dipakai tidak bisa dipakai ulang (replay)', function (): void {
        ['Pengguna' => $pengguna] = DaftarkanTenantDuaFaktorUji();
        $rahasia = BantuanAutentikasi::AktifkanDuaFaktor($pengguna);
        $kode = BantuanAutentikasi::KodeSaatIni($rahasia);

        $this->post('/masuk', ['Email' => 'rina@kopinusantara.id', 'KataSandi' => BantuanAutentikasi::KATA_SANDI]);
        $this->post('/masuk/dua-faktor', ['Kode' => $kode])->assertRedirect(route('kelola.beranda'));
        $this->post('/keluar');

        $this->post('/masuk', ['Email' => 'rina@kopinusantara.id', 'KataSandi' => BantuanAutentikasi::KATA_SANDI]);
        $this->post('/masuk/dua-faktor', ['Kode' => $kode])->assertSessionHasErrors('Kode');
        $this->assertGuest('web');
    });

    it('kode pemulihan bisa dipakai sekali saja', function (): void {
        ['Pengguna' => $pengguna] = DaftarkanTenantDuaFaktorUji();
        BantuanAutentikasi::AktifkanDuaFaktor($pengguna);

        $this->post('/masuk', ['Email' => 'rina@kopinusantara.id', 'KataSandi' => BantuanAutentikasi::KATA_SANDI]);
        $this->post('/masuk/dua-faktor', ['Kode' => 'aaaaa-bbbbb'])->assertRedirect(route('kelola.beranda'));
        expect($pengguna->refresh()->KodePemulihan2fa)->toBe(['CCCCC-DDDDD']);
        $this->post('/keluar');

        $this->post('/masuk', ['Email' => 'rina@kopinusantara.id', 'KataSandi' => BantuanAutentikasi::KATA_SANDI]);
        $this->post('/masuk/dua-faktor', ['Kode' => 'AAAAA-BBBBB'])->assertSessionHasErrors('Kode');
        $this->assertGuest('web');
    });

    it('kode pemulihan dibaca ulang di bawah kunci baris: dua permintaan dengan data pengguna yang sama tidak bisa memakai satu kode dua kali', function (): void {
        ['Pengguna' => $pengguna] = DaftarkanTenantDuaFaktorUji();
        BantuanAutentikasi::AktifkanDuaFaktor($pengguna);
        // Dua permintaan bersamaan memuat baris pengguna yang sama sebelum salah satunya menyimpan.
        $permintaanA = Pengguna::query()->findOrFail($pengguna->Id);
        $permintaanB = Pengguna::query()->findOrFail($pengguna->Id);
        $verifikasi = app(VerifikasiDuaFaktorPengguna::class);

        $verifikasi->Jalankan($permintaanA, 'AAAAA-BBBBB');

        expect(fn () => $verifikasi->Jalankan($permintaanB, 'AAAAA-BBBBB'))
            ->toThrow(PelanggaranAturanBisnis::class, 'Kode tidak cocok');
        expect($pengguna->refresh()->KodePemulihan2fa)->toBe(['CCCCC-DDDDD']);
    });

    it('percobaan kode dibatasi 5 kali', function (): void {
        ['Pengguna' => $pengguna] = DaftarkanTenantDuaFaktorUji();
        $rahasia = BantuanAutentikasi::AktifkanDuaFaktor($pengguna);
        $this->post('/masuk', ['Email' => 'rina@kopinusantara.id', 'KataSandi' => BantuanAutentikasi::KATA_SANDI]);

        foreach (range(1, 5) as $_) {
            $this->post('/masuk/dua-faktor', ['Kode' => BantuanAutentikasi::KodeSalah($rahasia)])->assertSessionHasErrors('Kode');
        }

        $this->post('/masuk/dua-faktor', ['Kode' => BantuanAutentikasi::KodeSaatIni($rahasia)])->assertSessionHasErrors('Kode');
        expect(session('errors')->first('Kode'))->toContain('Terlalu banyak percobaan');
        $this->assertGuest('web');
    });

    it('langkah kedua kedaluwarsa setelah 10 menit; tanpa langkah pertama halaman 2FA tidak bisa dibuka', function (): void {
        ['Pengguna' => $pengguna] = DaftarkanTenantDuaFaktorUji();
        $rahasia = BantuanAutentikasi::AktifkanDuaFaktor($pengguna);

        $this->get('/masuk/dua-faktor')->assertRedirect(route('masuk'));

        $this->post('/masuk', ['Email' => 'rina@kopinusantara.id', 'KataSandi' => BantuanAutentikasi::KATA_SANDI]);
        $this->travel(11)->minutes();
        $this->post('/masuk/dua-faktor', ['Kode' => BantuanAutentikasi::KodeSaatIni($rahasia)])->assertRedirect(route('masuk'));
        $this->assertGuest('web');
    });

    it('akun tanpa 2FA tetap masuk satu langkah', function (): void {
        DaftarkanTenantDuaFaktorUji();

        $this->post('/masuk', ['Email' => 'rina@kopinusantara.id', 'KataSandi' => BantuanAutentikasi::KATA_SANDI])
            ->assertRedirect(route('kelola.beranda'));
        $this->assertAuthenticated('web');
    });
});

describe('2FA wajib paket Bisnis ke atas (§20.2, fitur keamanan.2fa-wajib)', function (): void {
    it('Owner tenant Bisnis tanpa 2FA diarahkan ke halaman keamanan dan tidak bisa membuka menu lain', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pengguna] = DaftarkanTenantDuaFaktorUji('BISNIS');
        AkhiriTrialDuaFaktorUji($tenant->Id);
        $this->actingAs($pengguna, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $tenant->Id]);

        $this->get('/kelola')->assertRedirect(route('kelola.keamanan'));
        $this->get('/kelola/panduan-awal')->assertRedirect(route('kelola.keamanan'));
        $this->get('/kelola/keamanan')->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('DuaFaktor.Wajib', true));

        $rahasia = session(SesiAutentikasiTenant::RAHASIA_2FA_SEMENTARA);
        $this->post('/kelola/keamanan/dua-faktor', ['Kode' => BantuanAutentikasi::KodeSaatIni($rahasia)])->assertSessionHasNoErrors();
        $this->get('/kelola')->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/Beranda'));

        // Selama paket mewajibkan, 2FA tidak bisa dinonaktifkan.
        $this->delete('/kelola/keamanan/dua-faktor', ['KataSandi' => BantuanAutentikasi::KATA_SANDI])->assertSessionHasErrors('Umum');
        expect($pengguna->refresh()->CekDuaFaktorAktif())->toBeTrue();
    });

    it('D-38: selama trial paket Bisnis, Owner tanpa 2FA tetap membuka menu dan hanya melihat banner pengingat', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pengguna] = DaftarkanTenantDuaFaktorUji('BISNIS');
        $this->actingAs($pengguna, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $tenant->Id]);

        $this->get('/kelola/panduan-awal')->assertOk()->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('PengingatDuaFaktor', true));
        $this->get('/kelola/keamanan')->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('DuaFaktor.Wajib', true));

        $rahasia = session(SesiAutentikasiTenant::RAHASIA_2FA_SEMENTARA);
        $this->post('/kelola/keamanan/dua-faktor', ['Kode' => BantuanAutentikasi::KodeSaatIni($rahasia)])->assertSessionHasNoErrors();
        $this->get('/kelola/keamanan')->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('PengingatDuaFaktor', false));

        // Trial berakhir tanpa 2FA (pengguna lain): pengalihan berlaku lagi.
        AkhiriTrialDuaFaktorUji($tenant->Id);
        $anggota = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Admin);
        BantuanOrganisasi::Masuk($this, $anggota, $tenant->Id)->get('/kelola')->assertRedirect(route('kelola.keamanan'));
    });

    it('paket tanpa fitur 2FA wajib (Pro) dan anggota bukan Owner tidak dipaksa', function (): void {
        ['Tenant' => $pro, 'Pengguna' => $pemilikPro] = DaftarkanTenantDuaFaktorUji('PRO');
        $this->actingAs($pemilikPro, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $pro->Id])
            ->get('/kelola')->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/Beranda'));

        $bisnis = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('budi@toko.id', '081200000077', 'BISNIS', 'Toko Budi'))['Tenant'];
        $anggota = Pengguna::factory()->create();
        TenantPengguna::query()->create(['IdTenant' => $bisnis->Id, 'IdPengguna' => $anggota->Id]);

        $this->flushSession();
        $this->actingAs($anggota, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $bisnis->Id])
            ->get('/kelola')->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/Beranda'));
    });

    it('isolasi tenant: kewajiban dihitung dari tenant aktif, Owner Pro yang juga anggota tenant Bisnis tidak dipaksa di tenant Pro', function (): void {
        ['Tenant' => $pro, 'Pengguna' => $pemilik] = DaftarkanTenantDuaFaktorUji('PRO');
        $bisnis = app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data('budi@toko.id', '081200000077', 'BISNIS', 'Toko Budi'))['Tenant'];
        AkhiriTrialDuaFaktorUji($bisnis->Id);
        TenantPengguna::query()->create(['IdTenant' => $bisnis->Id, 'IdPengguna' => $pemilik->Id, 'Pemilik' => true]);

        $this->actingAs($pemilik, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $pro->Id])
            ->get('/kelola')->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/Beranda'));
        $this->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $bisnis->Id])
            ->get('/kelola')->assertRedirect(route('kelola.keamanan'));
    });
});

describe('2FA wajib per peran: Owner, Admin, Akuntan (§20.2, BR-00.8)', function (): void {
    it('Admin dan Akuntan tenant Bisnis wajib 2FA; Kasir dan Manajer Outlet tidak', function (PeranTenantBawaan $peran, bool $wajib): void {
        $bisnis = BantuanOrganisasi::BuatTenant('Toko Bisnis', 'BISNIS')['Tenant'];
        AkhiriTrialDuaFaktorUji($bisnis->Id);
        $anggota = BantuanOrganisasi::TambahAnggota($bisnis->Id, $peran);

        $respons = BantuanOrganisasi::Masuk($this, $anggota, $bisnis->Id)->get('/kelola');

        $wajib
            ? $respons->assertRedirect(route('kelola.keamanan'))
            : $respons->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/Beranda'));
    })->with([
        'Admin' => [PeranTenantBawaan::Admin, true],
        'Akuntan' => [PeranTenantBawaan::Akuntan, true],
        'Kasir' => [PeranTenantBawaan::Kasir, false],
        'Manajer Outlet' => [PeranTenantBawaan::ManajerOutlet, false],
    ]);

    it('Admin tenant Pro (tanpa fitur 2FA wajib) tidak dipaksa', function (): void {
        $pro = BantuanOrganisasi::BuatTenant('Toko Pro', 'PRO')['Tenant'];
        $admin = BantuanOrganisasi::TambahAnggota($pro->Id, PeranTenantBawaan::Admin);

        BantuanOrganisasi::Masuk($this, $admin, $pro->Id)->get('/kelola')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/Beranda'));
    });

    it('menonaktifkan 2FA ditolak bila tenant lain (bukan tenant aktif) mewajibkannya untuk peran pengguna', function (): void {
        ['Tenant' => $pro, 'Pengguna' => $pengguna] = DaftarkanTenantDuaFaktorUji('PRO');
        $bisnis = BantuanOrganisasi::BuatTenant('Toko Bisnis', 'BISNIS')['Tenant'];
        TenantPengguna::query()->create([
            'IdTenant' => $bisnis->Id,
            'IdPengguna' => $pengguna->Id,
            'IdPeran' => BantuanOrganisasi::Peran($bisnis->Id, PeranTenantBawaan::Akuntan)->Id,
        ]);
        BantuanAutentikasi::AktifkanDuaFaktor($pengguna);

        $tes = BantuanOrganisasi::Masuk($this, $pengguna, $pro->Id);
        $tes->get('/kelola/keamanan')->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('DuaFaktor.Wajib', true));
        $tes->delete('/kelola/keamanan/dua-faktor', ['KataSandi' => BantuanAutentikasi::KATA_SANDI])->assertSessionHasErrors('Umum');
        expect($pengguna->refresh()->CekDuaFaktorAktif())->toBeTrue();

        // Setelah keanggotaan di tenant Bisnis dinonaktifkan, 2FA boleh dimatikan.
        TenantPengguna::query()->where('IdTenant', $bisnis->Id)->where('IdPengguna', $pengguna->Id)->update(['Status' => 'Nonaktif']);
        $tes->delete('/kelola/keamanan/dua-faktor', ['KataSandi' => BantuanAutentikasi::KATA_SANDI])->assertSessionHasNoErrors();
        expect($pengguna->refresh()->CekDuaFaktorAktif())->toBeFalse();
    });
});

describe('Log audit keamanan akun (§25 no. 17)', function (): void {
    it('aktivasi & penonaktifan 2FA tercatat di setiap tenant tempat pengguna menjadi anggota aktif', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pengguna] = DaftarkanTenantDuaFaktorUji('PRO');
        $lain = BantuanOrganisasi::BuatTenant('Toko Budi', 'PRO')['Tenant'];
        TenantPengguna::query()->create([
            'IdTenant' => $lain->Id,
            'IdPengguna' => $pengguna->Id,
            'IdPeran' => BantuanOrganisasi::Peran($lain->Id, PeranTenantBawaan::Kasir)->Id,
        ]);

        $tes = BantuanOrganisasi::Masuk($this, $pengguna, $tenant->Id);
        $tes->get('/kelola/keamanan');
        $rahasia = session(SesiAutentikasiTenant::RAHASIA_2FA_SEMENTARA);
        $tes->post('/kelola/keamanan/dua-faktor', ['Kode' => BantuanAutentikasi::KodeSaatIni($rahasia)])->assertSessionHasNoErrors();
        $tes->delete('/kelola/keamanan/dua-faktor', ['KataSandi' => BantuanAutentikasi::KATA_SANDI])->assertSessionHasNoErrors();

        foreach ([$tenant->Id, $lain->Id] as $idTenant) {
            $log = LogAudit::query()->withoutGlobalScopes()->where('IdTenant', $idTenant)->where('Peristiwa', 'like', 'akun.%')->orderBy('Id')->get();
            expect($log->pluck('Peristiwa')->all())->toBe(['akun.dua-faktor-aktif', 'akun.dua-faktor-nonaktif'])
                ->and($log->pluck('IdPengguna')->unique()->all())->toBe([$pengguna->Id])
                ->and($log[0]->Ip)->toBe('127.0.0.1')
                ->and($log[0]->JenisObjek)->toBe('Pengguna');
        }
    });
});
