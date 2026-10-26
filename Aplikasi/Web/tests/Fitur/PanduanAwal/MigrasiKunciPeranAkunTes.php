<?php

declare(strict_types=1);

use App\Domain\PanduanAwal\Enum\StatusTemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektorVersi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Migrasi data 2026_09_26_000108 (§25 no. 16a): kunci peran lama di versi Draf diganti; Terbit/Usang tidak disentuh
 * (BR-P03.4). Urutan kunci JSON tidak dijamin MySQL, jadi dibandingkan dengan toEqual.
 */
describe('Migrasi UbahKunciPeranAkunDrafTemplate', function (): void {
    it('mengganti PiutangSettlement/Waste hanya di versi Draf (kunci baru menang bila keduanya ada). Versi Terbit utuh', function (): void {
        $template = TemplateSektor::query()->create(['Kode' => 'UJI-MIG', 'Nama' => 'Uji migrasi']);
        $isiLama = ['PemetaanAkun' => ['KasOutlet' => '1-1100', 'PiutangSettlement' => '1-1300', 'Waste' => '5-1300'], 'Kategori' => ['Kopi']];
        // Disisipkan lewat query builder: model menolak perubahan isi versi terbit (BR-P03.4).
        $idTerbit = DB::table('TemplateSektorVersi')->insertGetId(['Uuid' => strtolower((string) Str::ulid()), 'IdTemplateSektor' => $template->Id, 'Versi' => 1, 'Status' => StatusTemplateSektor::Terbit->value, 'Isi' => json_encode($isiLama)]);
        $idDraf = DB::table('TemplateSektorVersi')->insertGetId(['Uuid' => strtolower((string) Str::ulid()), 'IdTemplateSektor' => $template->Id, 'Versi' => 2, 'Status' => StatusTemplateSektor::Draf->value, 'Isi' => json_encode($isiLama)]);
        $idDrafGanda = DB::table('TemplateSektorVersi')->insertGetId(['Uuid' => strtolower((string) Str::ulid()), 'IdTemplateSektor' => TemplateSektor::query()->create(['Kode' => 'UJI-MIG2', 'Nama' => 'Uji 2'])->Id, 'Versi' => 1, 'Status' => StatusTemplateSektor::Draf->value, 'Isi' => json_encode(['PemetaanAkun' => ['PiutangSettlement' => '1-1300', 'PiutangPencairan' => '1-1310']])]);

        $migrasi = require database_path('migrations/2026_09_26_000108_UbahKunciPeranAkunDrafTemplate.php');
        $migrasi->up();

        expect(TemplateSektorVersi::query()->findOrFail($idDraf)->Isi)->toEqual(['PemetaanAkun' => ['KasOutlet' => '1-1100', 'PiutangPencairan' => '1-1300', 'SusutPersediaan' => '5-1300'], 'Kategori' => ['Kopi']])
            ->and(TemplateSektorVersi::query()->findOrFail($idTerbit)->Isi)->toEqual($isiLama)
            ->and(TemplateSektorVersi::query()->findOrFail($idDrafGanda)->Isi['PemetaanAkun'])->toBe(['PiutangPencairan' => '1-1310']);
    });
});
