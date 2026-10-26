<?php

declare(strict_types=1);

use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

function PerpanjangTrialUji(TestCase $tes, Tenant $tenant, int $hari = 7, string $alasan = 'Owner menunggu printer struk tiba minggu depan.'): TestResponse
{
    return $tes->post(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/trial/perpanjang"), ['Hari' => $hari, 'Alasan' => $alasan]);
}

function LanggananUji(Tenant $tenant): Langganan
{
    return Langganan::query()->where('IdTenant', $tenant->Id)->sole();
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-24 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('P-07 perpanjang trial (BR-P07.6)', function (): void {
    it('Dukungan memperpanjang trial dari tanggal akhir trial. Tercatat di audit dengan IdTenant (BR-P07.3)', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        $akhirLama = LanggananUji($tenant)->TrialBerakhirPada;
        $anggota = BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        PerpanjangTrialUji($this, $tenant, 7)->assertSessionHasNoErrors()->assertRedirect();

        $langganan = LanggananUji($tenant);
        expect($langganan->TrialBerakhirPada->equalTo($akhirLama->copy()->addDays(7)))->toBeTrue()
            ->and($langganan->Status)->toBe(StatusLangganan::Trial);

        $log = LogAuditPengelola::query()->where('Aksi', 'tenant.trial.perpanjang')->sole();
        expect($log->IdTenant)->toBe($tenant->Id)
            ->and($log->IdPenggunaPengelola)->toBe($anggota->Id)
            ->and($log->Alasan)->toBe('Owner menunggu printer struk tiba minggu depan.')
            ->and($log->NilaiBaru['PerpanjanganKe'])->toBe(1);

        $jejak = OverrideTenant::query()->where('IdTenant', $tenant->Id)->sole();
        expect($jejak->Jenis)->toBe(JenisOverride::Trial)->and($jejak->Nilai)->toBe('7');
    });

    it('maksimal 2 kali per tenant', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::MitraPenjualan);

        PerpanjangTrialUji($this, $tenant, 3)->assertSessionHasNoErrors();
        PerpanjangTrialUji($this, $tenant, 3)->assertSessionHasNoErrors();
        PerpanjangTrialUji($this, $tenant, 3)->assertSessionHasErrors(['Umum' => 'Trial tenant ini sudah diperpanjang 2 kali. Batas tercapai.']);

        expect(OverrideTenant::query()->where('IdTenant', $tenant->Id)->count())->toBe(2)
            ->and(LogAuditPengelola::query()->where('Aksi', 'tenant.trial.perpanjang')->count())->toBe(2);
    });

    it('menolak lebih dari 14 hari, kurang dari 1 hari, atau tanpa alasan', function (int $hari, string $alasan, string $bidang): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);
        $akhirLama = LanggananUji($tenant)->TrialBerakhirPada;

        PerpanjangTrialUji($this, $tenant, $hari, $alasan)->assertSessionHasErrors($bidang);

        expect(LanggananUji($tenant)->TrialBerakhirPada->equalTo($akhirLama))->toBeTrue();
    })->with([
        '15 hari' => [15, 'Alasan yang cukup panjang.', 'Hari'],
        '0 hari' => [0, 'Alasan yang cukup panjang.', 'Hari'],
        'alasan kosong' => [7, '', 'Alasan'],
        'alasan terlalu pendek' => [7, 'minta', 'Alasan'],
    ]);

    it('hanya saat status Trial. Tenant yang sudah turun ke Gratis atau sudah Aktif ditolak', function (StatusLangganan $status): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        LanggananUji($tenant)->update(['Status' => $status]);
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        PerpanjangTrialUji($this, $tenant)->assertSessionHasErrors('Umum');

        expect(OverrideTenant::query()->count())->toBe(0)
            ->and(LogAuditPengelola::query()->where('Aksi', 'tenant.trial.perpanjang')->exists())->toBeFalse();
    })->with([StatusLangganan::Gratis, StatusLangganan::Aktif]);

    it('trial yang sudah lewat tetapi belum diproses perintah akhir trial diperpanjang dari sekarang', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        $this->travel(14)->days();
        $this->travel(30)->minutes();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        PerpanjangTrialUji($this, $tenant, 5)->assertSessionHasNoErrors();

        expect(LanggananUji($tenant)->TrialBerakhirPada->equalTo(now()->addDays(5)))->toBeTrue();
        $this->artisan('tenant:akhiri-trial')->assertSuccessful();
        expect(LanggananUji($tenant)->Status)->toBe(StatusLangganan::Trial);
    });

    it('§19.3: Keuangan, Teknis, dan Analis tidak boleh memperpanjang trial', function (PeranPengelolaBawaan $peran): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, $peran);

        PerpanjangTrialUji($this, $tenant)->assertForbidden();
    })->with([PeranPengelolaBawaan::Keuangan, PeranPengelolaBawaan::Teknis, PeranPengelolaBawaan::Analis]);
});
