<?php

declare(strict_types=1);

use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Pajak\Model\TarifPajak;
use App\Domain\PanduanAwal\Enum\StatusTemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektorVersi;
use Database\Seeders\DataDemoLokal;

afterEach(function (): void {
    putenv('DEMO_KATA_SANDI');
});

describe('Seeder DataDemoLokal (lingkungan non-produksi)', function (): void {
    it('menerbitkan tarif pajak draf (four-eyes) dan semua template sektor (12 sejak v3.80, 14 dengan Apotek RTL-PHR & Bengkel SVC-WRK, K25, 19 dengan 5 sektor D-88), sehingga panduan awal punya pilihan; idempoten', function (): void {
        putenv('DEMO_KATA_SANDI=DemoLokalTes123');
        $this->seed();

        expect(TemplateSektor::query()->count())->toBe(19)
            ->and(TemplateSektorVersi::query()->where('Status', StatusTemplateSektor::Terbit->value)->exists())->toBeFalse();

        $this->seed(DataDemoLokal::class);

        expect(TarifPajak::query()->whereIn('Status', [StatusDataMaster::Draf->value, StatusDataMaster::MenungguTinjauan->value])->count())->toBe(0)
            ->and(TarifPajak::query()->where('Status', StatusDataMaster::Terbit->value)->count())->toBeGreaterThan(0)
            ->and(TemplateSektorVersi::query()->where('Status', StatusTemplateSektor::Terbit->value)->count())->toBe(19);

        // Dijalankan ulang: tidak ada yang berubah atau gagal.
        $this->seed(DataDemoLokal::class);

        expect(TemplateSektorVersi::query()->where('Status', StatusTemplateSektor::Terbit->value)->count())->toBe(19);
    });
});
