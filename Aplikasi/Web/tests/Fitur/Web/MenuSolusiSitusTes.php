<?php

declare(strict_types=1);

use App\Domain\PanduanAwal\Enum\StatusTemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektor;
use App\Domain\Situs\Kueri\PengaturanSitusBerlaku;
use App\Domain\Situs\Layanan\SektorSitus;
use App\Domain\Situs\Model\HalamanSitus;
use App\Domain\Situs\Model\PengaturanSitus;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/*
 * D-91: menu Solusi di kepala situs pemasaran = dropdown sektor per kelompok, satu halaman per sektor usaha.
 * Daftarnya mengikuti template sektor yang masih terbit (D-47), bukan tulisan tangan di menu.
 */

function BuatTemplateTerbitUji(string $kode, string $nama, bool $aktif = true): TemplateSektor
{
    $template = TemplateSektor::query()->create(['Kode' => $kode, 'Nama' => $nama, 'Keterangan' => null]);
    $template->Versi()->create([
        'Versi' => 1,
        'Status' => StatusTemplateSektor::Terbit,
        'Isi' => [],
        'DiterbitkanPada' => now(),
    ]);

    if (! $aktif) {
        $template->update(['DinonaktifkanPada' => now()]);
    }

    return $template;
}

/** @return list<array<string, mixed>> */
function AmbilMenuSitusUji(TestCase $tes): array
{
    $menu = [];
    $tes->get('/')->assertOk()->assertInertia(function (AssertableInertia $h) use (&$menu): void {
        $menu = $h->toArray()['props']['Situs']['Menu'];
    });

    return $menu;
}

describe('D-91 halaman per sektor', function (): void {
    it('setiap template sektor bawaan punya tepat satu halaman sektor, dengan slug unik dan kelompok yang dikenal', function (): void {
        $data = json_decode((string) file_get_contents(database_path('Data/TemplateSektorAwal.json')), true);
        $kodeTemplate = array_column($data['Template'], 'Kode');
        $daftar = SektorSitus::AmbilDaftar();

        expect(array_column($daftar, 'Kode'))->toEqualCanonicalizing($kodeTemplate)
            ->and(array_unique(array_column($daftar, 'Slug')))->toHaveCount(count($daftar));

        foreach ($daftar as $sektor) {
            expect(array_keys(SektorSitus::KELOMPOK))->toContain($sektor['Kelompok']);
        }
    });

    it('halaman sektor tampil, memakai kelompoknya sebagai label hero, dan masuk peta situs', function (): void {
        $this->get('/solusi/toko-sayur-sembako')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Halaman.Judul', 'Toko sayur, buah & sembako')
            ->where('Halaman.Bagian.0.Label', 'Toko & Retail')
            ->where('Halaman.Seo.Judul', 'Aplikasi Kasir Toko Sayur, Buah & Sembako | Payoung'));

        expect($this->get('/peta-situs')->getContent())->toContain('/solusi/toko-sayur-sembako</loc>');
    });
});

describe('D-91 menu Solusi', function (): void {
    it('menu bawaan memuat dropdown Solusi berisi sektor dari template aktif, dikelompokkan per kolom', function (): void {
        BuatTemplateTerbitUji('FNB-CAF', 'Kafe / kedai kopi');
        BuatTemplateTerbitUji('RTL-FRS', 'Sayur, buah & sembako');
        BuatTemplateTerbitUji('RTL-GEN', 'Retail umum / kelontong');

        $solusi = collect(AmbilMenuSitusUji($this))->firstWhere('Tautan', '@solusi');

        expect($solusi['Label'])->toBe('Solusi')
            ->and(array_column($solusi['Solusi'], 'Judul'))->toBe(['Kafe & Resto', 'Toko & Retail'])
            ->and($solusi['Solusi'][0]['Tautan'])->toBe('/solusi/kafe-resto')
            ->and(array_column($solusi['Solusi'][1]['Sektor'], 'Tautan'))
            ->toBe(['/solusi/toko-kelontong-minimarket', '/solusi/toko-sayur-sembako']);
    });

    it('template dinonaktifkan tidak muncul, dan kolom tanpa sektor hilang; tanpa sektor sama sekali butir Solusi dihilangkan', function (): void {
        BuatTemplateTerbitUji('FNB-CAF', 'Kafe / kedai kopi');
        BuatTemplateTerbitUji('RTL-FRS', 'Sayur, buah & sembako', aktif: false);

        $solusi = collect(AmbilMenuSitusUji($this))->firstWhere('Tautan', '@solusi');
        expect(array_column($solusi['Solusi'], 'Judul'))->toBe(['Kafe & Resto']);

        TemplateSektor::query()->update(['DinonaktifkanPada' => now()]);
        $menu = AmbilMenuSitusUji($this);

        expect(array_column($menu, 'Label'))->toBe(['Fitur', 'Harga', 'Kontak']);
    });

    it('halaman sektor yang dimatikan di konsol tidak muncul di menu', function (): void {
        BuatTemplateTerbitUji('RTL-FRS', 'Sayur, buah & sembako');
        BuatTemplateTerbitUji('RTL-GEN', 'Retail umum / kelontong');
        HalamanSitus::query()->create([
            'Slug' => 'solusi/toko-sayur-sembako',
            'Judul' => 'Toko sayur',
            'BagianTerbit' => [['Jenis' => 'Cta', 'Judul' => 'X']],
            'BagianDraf' => [['Jenis' => 'Cta', 'Judul' => 'X']],
            'DiterbitkanPada' => now(),
            'Aktif' => false,
        ]);

        $solusi = collect(AmbilMenuSitusUji($this))->firstWhere('Tautan', '@solusi');

        expect(array_column($solusi['Solusi'][0]['Sektor'], 'Tautan'))->toBe(['/solusi/toko-kelontong-minimarket']);
    });

    it('menu lama yang menyimpan tiga butir kelompok datar dilipat menjadi satu dropdown Solusi', function (): void {
        BuatTemplateTerbitUji('SVC-LDR', 'Laundry');
        PengaturanSitus::query()->create([
            'Kunci' => PengaturanSitus::KUNCI_UMUM,
            'Nilai' => [
                ...PengaturanSitusBerlaku::AmbilBawaan(),
                'Menu' => [
                    ['Label' => 'Fitur', 'Tautan' => '/fitur'],
                    ['Label' => 'Kafe & Resto', 'Tautan' => '/solusi/kafe-resto'],
                    ['Label' => 'Toko & Retail', 'Tautan' => '/solusi/toko-retail'],
                    ['Label' => 'Jasa', 'Tautan' => '/solusi/jasa'],
                    ['Label' => 'Harga', 'Tautan' => '/harga'],
                ],
            ],
        ]);

        $menu = AmbilMenuSitusUji($this);

        expect(array_column($menu, 'Label'))->toBe(['Fitur', 'Solusi', 'Harga'])
            ->and($menu[1]['Solusi'][0]['Judul'])->toBe('Salon, Laundry & Jasa');
    });

    it('satu butir kelompok yang sengaja dibiarkan sendirian tetap menjadi tautan biasa', function (): void {
        PengaturanSitus::query()->create([
            'Kunci' => PengaturanSitus::KUNCI_UMUM,
            'Nilai' => [
                ...PengaturanSitusBerlaku::AmbilBawaan(),
                'Menu' => [['Label' => 'Resto', 'Tautan' => '/solusi/kafe-resto']],
            ],
        ]);

        expect(AmbilMenuSitusUji($this))->toBe([['Label' => 'Resto', 'Tautan' => '/solusi/kafe-resto']]);
    });
});
