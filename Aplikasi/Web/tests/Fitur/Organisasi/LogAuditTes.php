<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Tenant\Aksi\AkhiriTrialKedaluwarsa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-24 09:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

describe('LogAudit tenant (§13.2, §25 no. 17)', function (): void {
    it('pendaftaran tenant tercatat dengan pelaku Owner dan IP pendaftar', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant(kodePaket: 'PRO');

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $log = LogAudit::query()->where('Peristiwa', 'tenant.daftar')->sole();
        expect($log->IdPengguna)->toBe($pemilik->Id)
            ->and($log->Ip)->toBe('203.0.113.9')
            ->and($log->NilaiBaru)->toEqual(['Nama' => 'Kopi Nusantara', 'Paket' => 'PRO']);
    });

    it('masuk (satu tenant), pilih tenant, dan keluar tercatat di tenant yang dipakai', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 13) Chrome/120')
            ->post('/masuk', ['Email' => $pemilik->Email, 'KataSandi' => 'kata-sandi-kuat-123'])
            ->assertRedirect(route('kelola.beranda'));
        $this->post('/pilih-tenant', ['Tenant' => $tenant->Uuid])->assertRedirect(route('kelola.beranda'));
        $this->post('/keluar')->assertRedirect(route('masuk'));

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(LogAudit::query()->where('Peristiwa', 'like', 'sesi.%')->orderBy('Id')->pluck('Peristiwa')->all())
            ->toBe(['sesi.masuk', 'sesi.pilih-tenant', 'sesi.keluar']);
        $masuk = LogAudit::query()->where('Peristiwa', 'sesi.masuk')->sole();
        expect($masuk->IdPengguna)->toBe($pemilik->Id)->and($masuk->AgenPengguna)->toContain('Android 13');
    });

    it('pengguna banyak tenant: masuk tidak dicatat sampai tenant dipilih, lalu tercatat hanya di tenant terpilih', function (): void {
        ['Tenant' => $tenantA, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Kopi Nusantara');
        $tenantB = BantuanOrganisasi::BuatTenant('Toko Budi')['Tenant'];
        TenantPengguna::query()->create([
            'IdTenant' => $tenantB->Id,
            'IdPengguna' => $pemilik->Id,
            'IdPeran' => BantuanOrganisasi::Peran($tenantB->Id, PeranTenantBawaan::Akuntan)->Id,
            'SemuaOutlet' => true,
        ]);

        $this->post('/masuk', ['Email' => $pemilik->Email, 'KataSandi' => 'kata-sandi-kuat-123'])->assertRedirect(route('pilih-tenant'));
        $this->post('/pilih-tenant', ['Tenant' => $tenantB->Uuid]);

        BantuanOrganisasi::AturKonteks($tenantA->Id);
        expect(LogAudit::query()->where('Peristiwa', 'like', 'sesi.%')->count())->toBe(0);
        BantuanOrganisasi::AturKonteks($tenantB->Id);
        expect(LogAudit::query()->where('Peristiwa', 'like', 'sesi.%')->pluck('Peristiwa')->all())->toBe(['sesi.pilih-tenant']);
    });

    it('trial yang berakhir tercatat sebagai aksi sistem (tanpa pelaku)', function (): void {
        $tenant = BantuanOrganisasi::BuatTenant(kodePaket: 'PRO')['Tenant'];
        $this->travel(15)->days();

        expect(app(AkhiriTrialKedaluwarsa::class)->Jalankan())->toBe(1);

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $log = LogAudit::query()->where('Peristiwa', 'langganan.trial-berakhir')->sole();
        expect($log->IdPengguna)->toBeNull()->and($log->JenisObjek)->toBe('Langganan')->and($log->NilaiBaru)->toEqual(['Status' => 'Gratis', 'Paket' => 'GRATIS']);
    });

    it('append-only: baris log tidak bisa diubah atau dihapus', function (): void {
        $tenant = BantuanOrganisasi::BuatTenant()['Tenant'];
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $log = LogAudit::query()->firstOrFail();

        expect(fn () => $log->update(['Peristiwa' => 'dipalsukan']))->toThrow(LogicException::class)
            ->and(fn () => $log->delete())->toThrow(LogicException::class);
        expect($log->refresh()->Peristiwa)->toBe('tenant.daftar');
    });

    it('halaman log audit untuk yang berizin: pelaku ditampilkan dengan nama dan bisa difilter', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/merek', ['Nama' => 'Roti Nusantara'])->assertSessionHasNoErrors();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get('/kelola/log-audit?cari=merek')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/LogAudit/Daftar')
                ->has('Log.Data', 1)
                ->where('Log.Data.0.Peristiwa', 'merek.buat')
                ->where('Log.Data.0.Kalimat', 'Merek dibuat')
                ->where('Log.Data.0.Perubahan', [['Bidang' => 'Nama', 'Lama' => null, 'Baru' => 'Roti Nusantara']])
                ->where('Log.Data.0.Pelaku', $pemilik->Nama)
                ->where('Log.Data.0.NilaiBaru', ['Nama' => 'Roti Nusantara'])
                ->where('Log.Meta.Total', 1));

        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $kasir, $tenant->Id)->get('/kelola/log-audit')->assertForbidden();
    });

    it('cari log audit memakai kalimat sehari-hari, bukan hanya kunci peristiwa', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/merek', ['Nama' => 'Roti Nusantara'])->assertSessionHasNoErrors();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get('/kelola/log-audit?cari='.urlencode('Merek dibuat'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->has('Log.Data', 1)
                ->where('Log.Data.0.Peristiwa', 'merek.buat'));
    });

    it('pelaku tanpa akun tenant ditulis apa adanya: tim dukungan, perangkat, atau sistem', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $log = LogAudit::query()->where('Peristiwa', 'tenant.daftar')->sole();
        $log->getConnection()->table('LogAudit')->where('Id', $log->Id)->update(['IdPengguna' => null]);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get('/kelola/log-audit?cari=tenant.daftar')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Log.Data.0.Pelaku', 'Sistem'));
    });

    it('keluar tanpa tenant aktif di sesi tidak mencatat apa pun dan tetap berhasil', function (): void {
        $pemilik = BantuanOrganisasi::BuatTenant()['Pemilik'];

        $this->actingAs($pemilik->fresh() ?? $pemilik, 'web')->post('/keluar')->assertRedirect(route('masuk'));
        $this->assertDatabaseMissing('LogAudit', ['Peristiwa' => 'sesi.keluar']);
    });
});
