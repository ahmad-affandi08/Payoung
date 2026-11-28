<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Kasir\Enum\StatusShift;
use App\Domain\Kasir\Model\Shift;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-06 BR-06.1 D-341: shift yang lupa ditutup kemarin tidak menahan shift hari ini', function (): void {
    $kemarin = fn (): array => ['DibukaPada' => now()->subDays(2)->utc()->toIso8601ZuluString()];

    it('perangkat yang sama: shift lama ditutup otomatis (kas aktual = seharusnya, perlu tinjauan) dan shift baru diterima', function () use ($kemarin): void {
        $k = BantuanKasir::Siapkan($this);
        $lama = BantuanKasir::ItemBukaShift($k['Kasir'], timpa: $kemarin());
        $baru = BantuanKasir::ItemBukaShift($k['Kasir']);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$lama]))->toBe([['Diterima', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$baru]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $shiftLama = Shift::query()->where('Uuid', $lama['Uuid'])->firstOrFail();
        $shiftBaru = Shift::query()->where('Uuid', $baru['Uuid'])->firstOrFail();

        expect($shiftLama->Status)->toBe(StatusShift::Tertutup)
            ->and($shiftLama->PerluTinjauan)->toBeTrue()
            ->and($shiftLama->AlasanTinjauan)->toContain('Ditutup otomatis')
            ->and($shiftLama->KasAktual)->toBe($shiftLama->KasSeharusnya)
            ->and($shiftLama->Selisih)->toBe('0.00')
            ->and($shiftLama->DitutupOleh)->toBe($k['Kasir']->Id)
            ->and($shiftBaru->Status)->toBe(StatusShift::Terbuka)
            ->and(LogAudit::query()->where('Peristiwa', 'shift.tutup-paksa')->count())->toBe(1);
    });

    it('perangkat lain: kasir yang sama membuka shift baru, shift kemarin di perangkat pertama ditutup otomatis', function () use ($kemarin): void {
        $k = BantuanKasir::Siapkan($this);
        $lama = BantuanKasir::ItemBukaShift($k['Kasir'], timpa: $kemarin());
        BantuanKasir::KirimRingkas($this, $k['Token'], [$lama]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $b = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $k['Outlet'], nama: 'Kasir Belakang');

        // Pemeriksaan sebelum buka shift (online) tidak lagi menghalangi: shift kemarin tidak dihitung.
        $this->withToken($b['Token'])->getJson('/api/pos/v1/shift/kasir-terbuka?UuidPengguna='.$k['Kasir']->Uuid)
            ->assertOk()->assertJson(['Shift' => []]);

        $baru = BantuanKasir::ItemBukaShift($k['Kasir']);
        expect(BantuanKasir::KirimRingkas($this, $b['Token'], [$baru]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Shift::query()->where('Uuid', $lama['Uuid'])->firstOrFail()->Status)->toBe(StatusShift::Tertutup)
            ->and(Shift::query()->where('Uuid', $baru['Uuid'])->firstOrFail()->PerluTinjauan)->toBeFalse();
    });

    it('shift hari yang sama tetap ditolak atau ditandai: aturan BR-06.1 untuk shift ganda sungguhan tidak berubah', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $pertama = BantuanKasir::ItemBukaShift($k['Kasir']);
        $kedua = BantuanKasir::ItemBukaShift($k['Kasir']);

        $hasil = BantuanKasir::Kirim($this, $k['Token'], [$pertama, $kedua])->assertOk()->json('Hasil');

        expect($hasil[0]['Status'])->toBe('Diterima')
            ->and($hasil[1]['Status'])->toBe('Ditolak')
            ->and($hasil[1]['Galat']['Kode'])->toBe('ShiftSudahTerbuka');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Shift::query()->where('Uuid', $pertama['Uuid'])->firstOrFail()->Status)->toBe(StatusShift::Terbuka);
    });

    it('shift bersama milik kasir lain di perangkat lain tidak disentuh', function () use ($kemarin): void {
        $k = BantuanKasir::Siapkan($this);
        $milikSupervisor = BantuanKasir::ItemBukaShift($k['Supervisor'], timpa: $kemarin());
        BantuanKasir::KirimRingkas($this, $k['Token'], [$milikSupervisor]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $b = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $k['Outlet'], nama: 'Kasir Belakang');
        $baru = BantuanKasir::ItemBukaShift($k['Kasir']);

        expect(BantuanKasir::KirimRingkas($this, $b['Token'], [$baru]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Shift::query()->where('Uuid', $milikSupervisor['Uuid'])->firstOrFail()->Status)->toBe(StatusShift::Terbuka);
    });
});
