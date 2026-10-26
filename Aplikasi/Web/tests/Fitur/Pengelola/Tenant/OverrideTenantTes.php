<?php

declare(strict_types=1);

use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Kueri\SumberFiturTenant;
use App\Domain\Tenant\Layanan\EvaluatorFitur;
use App\Domain\Tenant\Model\Fitur;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/**
 * @param  array<string, mixed>  $ubah
 */
function BuatOverrideUji(TestCase $tes, Tenant $tenant, array $ubah = []): TestResponse
{
    return $tes->post(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/override"), [
        'Jenis' => 'Batas',
        'Kunci' => 'BatasOutlet',
        'Nilai' => 5,
        'BerakhirPada' => '2026-10-10',
        'Alasan' => 'Pembukaan 3 cabang baru sambil menunggu upgrade paket.',
        ...$ubah,
    ]);
}

function BatasEfektifUji(Tenant $tenant): array
{
    return app(EvaluatorFitur::class)->HitungBatasEfektif(app(SumberFiturTenant::class)->Ambil($tenant->Id));
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-24 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('P-07 override batas/fitur sementara (BR-P07.7)', function (): void {
    it('override batas diperhitungkan EvaluatorFitur sampai akhir tanggal berakhir (WIB), lalu berakhir otomatis', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        $batasPaket = Paket::query()->where('Kode', 'PRO')->sole()->BatasOutlet;
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        BuatOverrideUji($this, $tenant)->assertSessionHasNoErrors()->assertRedirect();

        expect(BatasEfektifUji($tenant)['BatasOutlet'])->toBe(5)
            ->and(OverrideTenant::query()->sole()->BerakhirPada->equalTo(Carbon::parse('2026-10-10 23:59:59', 'Asia/Jakarta')))->toBeTrue();

        $log = LogAuditPengelola::query()->where('Aksi', 'tenant.override.buat')->sole();
        expect($log->IdTenant)->toBe($tenant->Id)->and($log->Alasan)->toContain('3 cabang');

        $this->travelTo(Carbon::parse('2026-10-11 00:00:01', 'Asia/Jakarta'));
        expect(BatasEfektifUji($tenant)['BatasOutlet'])->toBe($batasPaket);
    });

    it('override fitur membuka fitur di luar paket hanya untuk tenant itu', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        $lain = BantuanTenantPengelola::BuatTenant('Bakso Pak Kumis');
        Fitur::query()->create(['Kunci' => 'uji.fitur-khusus', 'Nama' => 'Fitur khusus uji', 'Modul' => 'Uji']);
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        $evaluator = app(EvaluatorFitur::class);

        BuatOverrideUji($this, $tenant, ['Jenis' => 'Fitur', 'Kunci' => 'uji.fitur-khusus', 'Nilai' => null])->assertSessionHasNoErrors();

        expect($evaluator->CekFiturAktif(app(SumberFiturTenant::class)->Ambil($tenant->Id), 'uji.fitur-khusus'))->toBeTrue()
            ->and($evaluator->CekFiturAktif(app(SumberFiturTenant::class)->Ambil($lain->Id), 'uji.fitur-khusus'))->toBeFalse();
    });

    it('wajib tanggal berakhir di masa depan paling lama 90 hari, alasan, dan kunci yang dikenal', function (array $ubah, string $bidang): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        BuatOverrideUji($this, $tenant, $ubah)->assertSessionHasErrors($bidang);

        expect(OverrideTenant::query()->count())->toBe(0);
    })->with([
        'tanpa tanggal' => [['BerakhirPada' => ''], 'BerakhirPada'],
        'tanggal lewat' => [['BerakhirPada' => '2026-09-23'], 'BerakhirPada'],
        'lebih 90 hari' => [['BerakhirPada' => '2027-01-15'], 'BerakhirPada'],
        'tanpa alasan' => [['Alasan' => ''], 'Alasan'],
        'batas tidak dikenal' => [['Kunci' => 'BatasGalaksi'], 'Kunci'],
        'batas tanpa angka' => [['Nilai' => null], 'Nilai'],
        'batas negatif' => [['Nilai' => -1], 'Nilai'],
        'fitur tidak ada' => [['Jenis' => 'Fitur', 'Kunci' => 'fitur.fiktif'], 'Kunci'],
        'jenis trial lewat override' => [['Jenis' => 'Trial'], 'Jenis'],
    ]);

    it('satu kunci hanya satu override aktif. Setelah dicabut boleh dibuat lagi', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        BuatOverrideUji($this, $tenant)->assertSessionHasNoErrors();
        BuatOverrideUji($this, $tenant, ['Nilai' => 8])->assertSessionHasErrors('Kunci');

        $override = OverrideTenant::query()->sole();
        $this->post(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/override/{$override->Uuid}/cabut"), ['Alasan' => 'Tenant sudah upgrade ke paket Bisnis.'])
            ->assertSessionHasNoErrors();

        expect($override->refresh()->CekAktif())->toBeFalse()
            ->and(BatasEfektifUji($tenant)['BatasOutlet'])->toBe(Paket::query()->where('Kode', 'PRO')->sole()->BatasOutlet)
            ->and(LogAuditPengelola::query()->where('Aksi', 'tenant.override.cabut')->sole()->IdTenant)->toBe($tenant->Id);

        BuatOverrideUji($this, $tenant, ['Nilai' => 8])->assertSessionHasNoErrors();
        expect(BatasEfektifUji($tenant)['BatasOutlet'])->toBe(8)
            ->and(OverrideTenant::query()->count())->toBe(2);
    });

    it('override yang sudah berakhir atau jejak perpanjangan trial tidak bisa dicabut', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);
        BuatOverrideUji($this, $tenant, ['BerakhirPada' => '2026-09-25'])->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/trial/perpanjang"), ['Hari' => 3, 'Alasan' => 'Menunggu printer struk tiba.'])
            ->assertSessionHasNoErrors();
        [$batas, $trial] = OverrideTenant::query()->orderBy('Id')->get()->all();

        $this->post(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/override/{$trial->Uuid}/cabut"), ['Alasan' => 'Coba cabut perpanjangan.'])
            ->assertSessionHasErrors('Umum');

        $this->travel(3)->days();
        // Sesi pengelola kedaluwarsa karena menganggur; masuk ulang.
        $this->withSession(BantuanPengelola::SesiTerverifikasi());
        $this->post(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/override/{$batas->Uuid}/cabut"), ['Alasan' => 'Sudah tidak diperlukan lagi.'])
            ->assertSessionHasErrors(['Umum' => 'Override ini sudah berakhir.']);
    });

    it('isolasi tenant: override tenant lain tidak bisa dicabut lewat URL tenant ini', function (): void {
        $a = BantuanTenantPengelola::BuatTenant();
        $b = BantuanTenantPengelola::BuatTenant('Bakso Pak Kumis');
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);
        BuatOverrideUji($this, $b)->assertSessionHasNoErrors();
        $overrideB = OverrideTenant::query()->sole();

        $this->post(BantuanPengelola::Url("/tenant/{$a->Uuid}/override/{$overrideB->Uuid}/cabut"), ['Alasan' => 'Salah tenant, uji isolasi.'])
            ->assertNotFound();

        expect($overrideB->refresh()->CekAktif())->toBeTrue();
    });

    it('§19.3: Keuangan, Mitra & Penjualan, dan Teknis tidak boleh membuat override', function (PeranPengelolaBawaan $peran): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, $peran);

        BuatOverrideUji($this, $tenant)->assertForbidden();
    })->with([PeranPengelolaBawaan::Keuangan, PeranPengelolaBawaan::MitraPenjualan, PeranPengelolaBawaan::Teknis]);
});
