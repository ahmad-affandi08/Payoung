<?php

declare(strict_types=1);

use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Pajak\Model\JenisPajak;
use App\Domain\Pajak\Model\TarifPajak;
use App\Domain\PanduanAwal\Enum\StatusTemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektorVersi;
use App\Domain\Pengelola\Katalog\Aksi\SiapkanKatalogBawaan;
use App\Domain\Pengelola\Referensi\Aksi\SiapkanPajakBawaan;
use App\Domain\Pengelola\TemplateSektor\Aksi\SiapkanTemplateSektorBawaan;
use App\Domain\Pengelola\TemplateSektor\Aksi\TerapkanTemplateBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Referensi\Model\SatuanStandar;
use Illuminate\Support\Carbon;

/*
 * D-88: `template:terapkan` menerapkan template sektor bawaan rilis ke server berjalan, langsung TERBIT tanpa draf
 * (BR-P03.3 tetap berlaku: gagal validasi = tidak terbit), aman diulang, dan tidak pernah mengubah versi yang sudah
 * dipakai tenant (BR-P03.1, BR-P03.4).
 */

const JUMLAH_TEMPLATE_RILIS = 19;

function TerbitkanPpnUjiRilis(): void
{
    TarifPajak::query()->create([
        'IdJenisPajak' => JenisPajak::query()->where('Kode', 'Ppn')->value('Id'),
        'Tarif' => '12',
        'PengaliDppPembilang' => 11,
        'PengaliDppPenyebut' => 12,
        'BerlakuMulai' => '2026-01-01',
        'Status' => StatusDataMaster::Terbit,
    ]);
}

/**
 * Berkas rilis tiruan: salinan berkas asli dengan perubahan pada satu template.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $ubah
 */
function BuatBerkasRilisUji(string $kode, callable $ubah): string
{
    $data = json_decode((string) file_get_contents(database_path('Data/TemplateSektorAwal.json')), true);

    foreach ($data['Template'] as &$baris) {
        if ($baris['Kode'] === $kode) {
            $baris['Isi'] = $ubah($baris['Isi']);
        }
    }

    $path = sys_get_temp_dir().'/TemplateRilisUji'.uniqid().'.json';
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE));

    return $path;
}

/**
 * @return array{Tindakan: string, Versi: int|null, Catatan: list<string>}
 */
function AmbilHasilTemplate(array $hasil, string $kode): array
{
    foreach ($hasil as $baris) {
        if ($baris['Kode'] === $kode) {
            return $baris;
        }
    }

    throw new RuntimeException("Template {$kode} tidak ada di hasil.");
}

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00', 'Asia/Jakarta'));
    app(SiapkanPajakBawaan::class)->Jalankan();
    app(SiapkanKatalogBawaan::class)->Jalankan();
});

afterEach(fn () => Carbon::setTestNow());

describe('template:terapkan (D-88)', function (): void {
    it('menerbitkan semua template rilis langsung terbit tanpa draf, menyiapkan satuan, dan aman diulang', function (): void {
        TerbitkanPpnUjiRilis();

        $hasil = app(TerapkanTemplateBawaan::class)->Jalankan();

        expect($hasil)->toHaveCount(JUMLAH_TEMPLATE_RILIS)
            ->and(collect($hasil)->pluck('Tindakan')->unique()->all())->toBe(['Template baru terbit'])
            ->and(TemplateSektorVersi::query()->where('Status', StatusTemplateSektor::Terbit->value)->where('Versi', 1)->count())->toBe(JUMLAH_TEMPLATE_RILIS)
            ->and(TemplateSektorVersi::query()->where('Status', StatusTemplateSektor::Draf->value)->count())->toBe(0)
            ->and(SatuanStandar::query()->whereIn('Kode', ['IKAT', 'KARUNG', 'SESI', 'TANGKAI'])->count())->toBe(4)
            ->and(LogAuditPengelola::query()->where('Aksi', 'template.terbitkan-rilis')->count())->toBe(JUMLAH_TEMPLATE_RILIS);

        $ulang = app(TerapkanTemplateBawaan::class)->Jalankan();

        expect(collect($ulang)->pluck('Tindakan')->unique()->all())->toBe(['Tanpa perubahan'])
            ->and(TemplateSektorVersi::query()->count())->toBe(JUMLAH_TEMPLATE_RILIS)
            ->and(LogAuditPengelola::query()->where('Aksi', 'template.terbitkan-rilis')->count())->toBe(JUMLAH_TEMPLATE_RILIS);
    });

    it('template sayur, buah & sembako, gym, cuci kendaraan, pet shop, dan florist ada dan terbit', function (): void {
        TerbitkanPpnUjiRilis();
        app(TerapkanTemplateBawaan::class)->Jalankan();

        foreach (['RTL-FRS', 'SVC-FIT', 'SVC-CWS', 'RTL-PET', 'RTL-FLR'] as $kode) {
            $versi = TemplateSektor::query()->where('Kode', $kode)->sole()->Versi()->sole();

            expect($versi->Status)->toBe(StatusTemplateSektor::Terbit)
                ->and($versi->HasilValidasi['Lolos'] ?? null)->toBeTrue()
                ->and($versi->Isi['ProdukContoh'])->not->toBeEmpty();
        }

        $sayur = TemplateSektor::query()->where('Kode', 'RTL-FRS')->sole()->Versi()->sole()->Isi;

        expect($sayur['Kategori'])->toContain('Sayur', 'Buah')
            ->and($sayur['KodeSatuan'])->toContain('KG', 'IKAT', 'KARUNG')
            ->and($sayur['KelompokPajak'])->toBe([]);
    });

    it('draf v1 dari seeder lama yang belum pernah terbit langsung diterbitkan tanpa membuat versi baru', function (): void {
        TerbitkanPpnUjiRilis();
        app(SiapkanTemplateSektorBawaan::class)->Jalankan();

        expect(TemplateSektorVersi::query()->where('Status', StatusTemplateSektor::Draf->value)->count())->toBe(JUMLAH_TEMPLATE_RILIS);

        $hasil = app(TerapkanTemplateBawaan::class)->Jalankan();

        expect(collect($hasil)->pluck('Tindakan')->unique()->all())->toBe(['Draf bawaan terbit'])
            ->and(TemplateSektorVersi::query()->count())->toBe(JUMLAH_TEMPLATE_RILIS)
            ->and(TemplateSektorVersi::query()->where('Status', StatusTemplateSektor::Draf->value)->count())->toBe(0);
    });

    it('isi rilis yang berubah menerbitkan versi baru, versi lama menjadi Usang dan isinya tidak berubah (BR-P03.1, BR-P03.4)', function (): void {
        TerbitkanPpnUjiRilis();
        app(TerapkanTemplateBawaan::class)->Jalankan();
        $v1 = TemplateSektor::query()->where('Kode', 'RTL-GEN')->sole()->Versi()->where('Versi', 1)->sole();
        $isiV1 = $v1->Isi;

        $path = BuatBerkasRilisUji('RTL-GEN', function (array $isi): array {
            $isi['Kategori'][] = 'Sayur & buah';

            return $isi;
        });

        $hasil = app(TerapkanTemplateBawaan::class)->Jalankan(true, $path);
        $v1->refresh();
        $v2 = TemplateSektor::query()->where('Kode', 'RTL-GEN')->sole()->Versi()->where('Versi', 2)->sole();

        expect(AmbilHasilTemplate($hasil, 'RTL-GEN'))->toMatchArray(['Tindakan' => 'Isi rilis berubah, versi baru terbit', 'Versi' => 2])
            ->and($v2->Status)->toBe(StatusTemplateSektor::Terbit)
            ->and($v2->IdVersiAsal)->toBe($v1->Id)
            ->and($v2->Isi['Kategori'])->toContain('Sayur & buah')
            ->and($v1->Status)->toBe(StatusTemplateSektor::Usang)
            ->and($v1->Isi)->toBe($isiV1)
            ->and(AmbilHasilTemplate($hasil, 'FNB-CAF')['Tindakan'])->toBe('Tanpa perubahan');

        unlink($path);
    });

    it('template yang sedang punya draf di konsol dilewati dan drafnya tidak ditimpa (BR-P03.4)', function (): void {
        TerbitkanPpnUjiRilis();
        app(TerapkanTemplateBawaan::class)->Jalankan();
        $template = TemplateSektor::query()->where('Kode', 'FNB-RST')->sole();
        $isiDraf = $template->Versi()->sole()->Isi;
        $isiDraf['Kategori'][] = 'Kategori dari konsol';
        $draf = $template->Versi()->create(['Versi' => 2, 'Status' => StatusTemplateSektor::Draf, 'Isi' => $isiDraf]);

        $path = BuatBerkasRilisUji('FNB-RST', function (array $isi): array {
            $isi['Kategori'][] = 'Kategori rilis';

            return $isi;
        });

        $hasil = app(TerapkanTemplateBawaan::class)->Jalankan(true, $path);
        $draf->refresh();

        expect(AmbilHasilTemplate($hasil, 'FNB-RST')['Tindakan'])->toBe('Dilewati')
            ->and($draf->Status)->toBe(StatusTemplateSektor::Draf)
            ->and($draf->Isi['Kategori'])->toContain('Kategori dari konsol')
            ->and($template->Versi()->where('Status', StatusTemplateSektor::Terbit->value)->sole()->Versi)->toBe(1);

        unlink($path);
    });

    it('template yang gagal validasi tidak terbit (BR-P03.3): versi terbit lama tetap, hasil disimpan sebagai draf', function (): void {
        TerbitkanPpnUjiRilis();
        app(TerapkanTemplateBawaan::class)->Jalankan();

        $path = BuatBerkasRilisUji('SVC-GEN', function (array $isi): array {
            $isi['KodeSatuan'][] = 'TIDAKADA';

            return $isi;
        });

        $hasil = app(TerapkanTemplateBawaan::class)->Jalankan(true, $path);
        $template = TemplateSektor::query()->where('Kode', 'SVC-GEN')->sole();
        $draf = $template->Versi()->where('Status', StatusTemplateSektor::Draf->value)->sole();

        expect(AmbilHasilTemplate($hasil, 'SVC-GEN')['Tindakan'])->toBe('Gagal validasi')
            ->and(AmbilHasilTemplate($hasil, 'SVC-GEN')['Catatan'][0])->toContain('TIDAKADA')
            ->and($template->Versi()->where('Status', StatusTemplateSektor::Terbit->value)->sole()->Versi)->toBe(1)
            ->and($draf->HasilValidasi['Lolos'])->toBeFalse();

        unlink($path);
    });

    it('perintah gagal (kode keluar bukan 0) bila ada template tidak lolos validasi, tanpa menghalangi yang lolos', function (): void {
        // Tarif PPN belum terbit: semua template berpajak PPN/PBJT nasional gagal, template bebas pajak (RTL-FRS) tetap terbit.
        $this->artisan('template:terapkan')->assertFailed();

        $sayur = TemplateSektor::query()->where('Kode', 'RTL-FRS')->sole()->Versi()->sole();
        $umum = TemplateSektor::query()->where('Kode', 'RTL-GEN')->sole()->Versi()->sole();

        expect($sayur->Status)->toBe(StatusTemplateSektor::Terbit)
            ->and($umum->Status)->toBe(StatusTemplateSektor::Draf)
            ->and($umum->HasilValidasi['Lolos'])->toBeFalse();
    });

    it('--kering hanya menampilkan rencana dan tidak mengubah data', function (): void {
        TerbitkanPpnUjiRilis();

        $this->artisan('template:terapkan --kering')
            ->expectsOutputToContain('[kering] template RTL-FRS: Template baru terbit (versi 1)')
            ->assertSuccessful();

        expect(TemplateSektor::query()->count())->toBe(0)
            ->and(TemplateSektorVersi::query()->count())->toBe(0)
            ->and(SatuanStandar::query()->where('Kode', 'IKAT')->exists())->toBeFalse();
    });
});
