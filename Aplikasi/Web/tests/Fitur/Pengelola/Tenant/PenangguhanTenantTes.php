<?php

declare(strict_types=1);

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Pengelola\Tenant\Aksi\AktifkanKembaliTenant;
use App\Domain\Pengelola\Tenant\Surel\LanggananDiaktifkanKembali;
use App\Domain\Pengelola\Tenant\Surel\LanggananDitangguhkan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\PersetujuanDokumenLegal;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

function TangguhkanUji(TestCase $tes, Tenant $tenant, string $kategori = 'Penipuan', string $catatan = 'Laporan chargeback QRIS berulang dari 3 bank.'): TestResponse
{
    return $tes->post(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/tangguhkan"), ['Kategori' => $kategori, 'Catatan' => $catatan]);
}

function AktifkanUji(TestCase $tes, Tenant $tenant, string $alasan = 'Investigasi selesai, laporan tidak terbukti.'): TestResponse
{
    return $tes->post(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/aktifkan"), ['Alasan' => $alasan]);
}

function StatusLanggananUji(Tenant $tenant): StatusLangganan
{
    return Langganan::query()->where('IdTenant', $tenant->Id)->sole()->Status;
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-24 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

describe('P-07 tangguhkan manual (BR-P07.4)', function (): void {
    it('Super Admin menangguhkan dengan kategori & catatan; Owner diberi email tanpa catatan internal', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant('Kopi Nusantara', 'rina@kopinusantara.id');
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        TangguhkanUji($this, $tenant)->assertSessionHasNoErrors()->assertRedirect();

        $langganan = Langganan::query()->where('IdTenant', $tenant->Id)->sole();
        expect($langganan->Status)->toBe(StatusLangganan::Ditangguhkan)
            ->and($langganan->StatusSebelumDitangguhkan)->toBe(StatusLangganan::Trial);

        $log = LogAuditPengelola::query()->where('Aksi', 'tenant.tangguhkan')->sole();
        expect($log->IdTenant)->toBe($tenant->Id)
            ->and($log->NilaiBaru)->toMatchArray(['Status' => 'Ditangguhkan', 'Kategori' => 'Penipuan'])
            ->and($log->Alasan)->toContain('chargeback QRIS');

        Mail::assertQueued(LanggananDitangguhkan::class, function (LanggananDitangguhkan $surel): bool {
            $isi = $surel->render();

            return $surel->hasTo('rina@kopinusantara.id')
                && str_contains($isi, 'Dugaan penipuan')
                && ! str_contains($isi, 'chargeback');
        });
    });

    it('BR-P07.1: penangguhan tidak menghapus data tenant apa pun', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        app(KonteksTenant::class)->Atur($tenant->Id);
        $sebelum = [Outlet::query()->count(), Gudang::query()->count()];
        app(KonteksTenant::class)->Kosongkan();
        $anggota = TenantPengguna::query()->where('IdTenant', $tenant->Id)->count();
        $persetujuan = PersetujuanDokumenLegal::query()->where('IdTenant', $tenant->Id)->count();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        TangguhkanUji($this, $tenant)->assertSessionHasNoErrors();

        app(KonteksTenant::class)->Atur($tenant->Id);
        expect([Outlet::query()->count(), Gudang::query()->count()])->toBe($sebelum)
            ->and(Tenant::query()->whereKey($tenant->Id)->exists())->toBeTrue()
            ->and(TenantPengguna::query()->where('IdTenant', $tenant->Id)->count())->toBe($anggota)
            ->and(PersetujuanDokumenLegal::query()->where('IdTenant', $tenant->Id)->count())->toBe($persetujuan);
    });

    it('menolak tanpa kategori/catatan, kategori tak dikenal, atau tenant yang sudah ditangguhkan/berhenti', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        TangguhkanUji($this, $tenant, catatan: '')->assertSessionHasErrors('Catatan');
        TangguhkanUji($this, $tenant, kategori: 'Iseng')->assertSessionHasErrors('Kategori');
        expect(StatusLanggananUji($tenant))->toBe(StatusLangganan::Trial);

        TangguhkanUji($this, $tenant)->assertSessionHasNoErrors();
        TangguhkanUji($this, $tenant)->assertSessionHasErrors(['Umum' => 'Tenant ini sudah ditangguhkan.']);

        Langganan::query()->where('IdTenant', $tenant->Id)->sole()->update(['Status' => StatusLangganan::Berhenti]);
        TangguhkanUji($this, $tenant)->assertSessionHasErrors('Umum');
        Mail::assertQueuedCount(1);
    });

    it('§19.3: hanya Super Admin yang boleh menangguhkan', function (PeranPengelolaBawaan $peran): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, $peran);

        TangguhkanUji($this, $tenant)->assertForbidden();
        expect(StatusLanggananUji($tenant))->toBe(StatusLangganan::Trial);
    })->with([PeranPengelolaBawaan::Keuangan, PeranPengelolaBawaan::Dukungan, PeranPengelolaBawaan::Teknis]);
});

describe('P-07 aktifkan kembali (BR-P07.5)', function (): void {
    it('Keuangan mengaktifkan kembali dengan keputusan tertulis. Status sebelum penangguhan dipulihkan', function (StatusLangganan $asal): void {
        $tenant = BantuanTenantPengelola::BuatTenant('Kopi Nusantara', 'rina@kopinusantara.id');
        Langganan::query()->where('IdTenant', $tenant->Id)->sole()->update(['Status' => $asal]);
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        TangguhkanUji($this, $tenant)->assertSessionHasNoErrors();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Keuangan);

        AktifkanUji($this, $tenant)->assertSessionHasNoErrors();

        $langganan = Langganan::query()->where('IdTenant', $tenant->Id)->sole();
        expect($langganan->Status)->toBe($asal)
            ->and($langganan->StatusSebelumDitangguhkan)->toBeNull()
            ->and(LogAuditPengelola::query()->where('Aksi', 'tenant.aktifkan')->sole()->Alasan)->toContain('tidak terbukti');
        Mail::assertQueued(LanggananDiaktifkanKembali::class, fn (LanggananDiaktifkanKembali $surel) => $surel->hasTo('rina@kopinusantara.id'));
    })->with([StatusLangganan::Trial, StatusLangganan::Aktif, StatusLangganan::Gratis]);

    it('trial yang habis selama ditangguhkan dipulihkan ke paket Gratis (BR-00.3)', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        TangguhkanUji($this, $tenant)->assertSessionHasNoErrors();
        $this->travel(20)->days();
        // Sesi pengelola kedaluwarsa karena menganggur; masuk ulang.
        $this->withSession(BantuanPengelola::SesiTerverifikasi());

        $this->get(BantuanPengelola::Url("/tenant/{$tenant->Uuid}"))
            ->assertInertia(fn ($halaman) => $halaman->where('Tenant.Langganan.StatusSetelahDiaktifkan', 'Gratis'));
        AktifkanUji($this, $tenant)->assertSessionHasNoErrors();

        $langganan = Langganan::query()->with('Paket')->where('IdTenant', $tenant->Id)->sole();
        expect($langganan->Status)->toBe(StatusLangganan::Gratis)
            ->and($langganan->Paket->Kode)->toBe('GRATIS');
    });

    it('penangguhan dari penagihan (tanpa status asal) dipulihkan ke Aktif', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        $langganan = Langganan::query()->where('IdTenant', $tenant->Id)->sole();
        $langganan->update(['Status' => StatusLangganan::Aktif]);
        $langganan->update(['Status' => StatusLangganan::Tertunggak]);
        $langganan->update(['Status' => StatusLangganan::Ditangguhkan]);
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Keuangan);

        AktifkanUji($this, $tenant)->assertSessionHasNoErrors();

        expect(StatusLanggananUji($tenant))->toBe(StatusLangganan::Aktif);
    });

    it('menolak tanpa keputusan tertulis atau bila tenant tidak sedang ditangguhkan', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        AktifkanUji($this, $tenant)->assertSessionHasErrors(['Umum' => 'Hanya tenant yang ditangguhkan yang bisa diaktifkan kembali.']);
        TangguhkanUji($this, $tenant)->assertSessionHasNoErrors();
        AktifkanUji($this, $tenant, '')->assertSessionHasErrors('Alasan');

        expect(StatusLanggananUji($tenant))->toBe(StatusLangganan::Ditangguhkan);
    });

    it('§19.3: Dukungan dan Mitra & Penjualan tidak boleh mengaktifkan kembali', function (PeranPengelolaBawaan $peran): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        TangguhkanUji($this, $tenant)->assertSessionHasNoErrors();
        BantuanTenantPengelola::Masuk($this, $peran);

        AktifkanUji($this, $tenant)->assertForbidden();
    })->with([PeranPengelolaBawaan::Dukungan, PeranPengelolaBawaan::MitraPenjualan]);
});

describe('P-07 × P-08: aktifkan kembali memeriksa tunggakan (BR-P07.5, BR-P08.10)', function (): void {
    /** Langganan berbayar yang periodenya berakhir `$hariLalu` hari lalu, lalu ditangguhkan (manual atau otomatis). */
    $siapkan = function (Tenant $tenant, int $hariLalu, ?StatusLangganan $asalManual): void {
        DB::table('Langganan')->where('IdTenant', $tenant->Id)->update([
            'Status' => StatusLangganan::Ditangguhkan->value,
            'StatusSebelumDitangguhkan' => $asalManual?->value,
            'PeriodeMulai' => now()->subDays($hariLalu + 30),
            'PeriodeSelesai' => now()->subDays($hariLalu),
        ]);
    };

    it('penangguhan karena tunggakan lewat masa tenggang tidak bisa diaktifkan kembali lewat tombol. Jalannya pembayaran', function () use ($siapkan): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        $siapkan($tenant, 10, null);
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Keuangan);

        $this->get(BantuanPengelola::Url("/tenant/{$tenant->Uuid}"))
            ->assertInertia(fn ($halaman) => $halaman->where('Tenant.Langganan.BisaDiaktifkan', false)->where('Tenant.Langganan.StatusSetelahDiaktifkan', null));
        AktifkanUji($this, $tenant)->assertSessionHasErrors(['Umum']);

        expect(StatusLanggananUji($tenant))->toBe(StatusLangganan::Ditangguhkan);
        $this->artisan('tagihan:proses-tunggakan')->assertSuccessful();
        expect(StatusLanggananUji($tenant))->toBe(StatusLangganan::Ditangguhkan);
    });

    it('periode habis tetapi masih dalam masa tenggang dipulihkan ke Tertunggak, bukan Aktif, dan tidak langsung tertangguh lagi', function () use ($siapkan): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        $siapkan($tenant, 2, StatusLangganan::Aktif);
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        AktifkanUji($this, $tenant)->assertSessionHasNoErrors();
        $this->artisan('tagihan:proses-tunggakan')->assertSuccessful();

        expect(StatusLanggananUji($tenant))->toBe(StatusLangganan::Tertunggak);
    });

    it('penangguhan manual yang periodenya lewat masa tenggang dicabut menjadi penangguhan karena tunggakan; Owner tidak diberi email "aktif kembali"', function () use ($siapkan): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        $siapkan($tenant, 30, StatusLangganan::Tertunggak);
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        $this->get(BantuanPengelola::Url("/tenant/{$tenant->Uuid}"))
            ->assertInertia(fn ($halaman) => $halaman->where('Tenant.Langganan.StatusSetelahDiaktifkan', 'Ditangguhkan'));
        AktifkanUji($this, $tenant)->assertSessionHasNoErrors();

        $langganan = Langganan::query()->where('IdTenant', $tenant->Id)->sole();
        expect($langganan->Status)->toBe(StatusLangganan::Ditangguhkan)
            ->and($langganan->StatusSebelumDitangguhkan)->toBeNull()
            ->and($langganan->CekDitangguhkanManual())->toBeFalse();
        Mail::assertNotQueued(LanggananDiaktifkanKembali::class);
    });

    it('status asal penangguhan manual dikosongkan begitu langganan keluar dari Ditangguhkan lewat jalur mana pun', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        $langganan = Langganan::query()->where('IdTenant', $tenant->Id)->sole();
        $langganan->update(['Status' => StatusLangganan::Ditangguhkan, 'StatusSebelumDitangguhkan' => StatusLangganan::Trial]);

        $langganan->update(['Status' => StatusLangganan::Aktif]);
        $langganan->update(['Status' => StatusLangganan::Tertunggak]);
        $langganan->update(['Status' => StatusLangganan::Ditangguhkan]);

        expect($langganan->refresh()->StatusSebelumDitangguhkan)->toBeNull()
            ->and(AktifkanKembaliTenant::TentukanTujuan($langganan))->toBe(StatusLangganan::Aktif);
    });
});
