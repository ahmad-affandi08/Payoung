<?php

declare(strict_types=1);

use App\Domain\Situs\Layanan\KerangkaHalamanSitus;
use App\Domain\Situs\Layanan\KontenSitusBawaan;
use App\Domain\Situs\Layanan\SkemaBagianSitus;

/*
 * Kerangka HTML dari server mengisi #app sebelum bundel React siap (dan menjadi satu-satunya isi bagi perayap tanpa
 * JavaScript). Test ini menjaga isinya: sorotan pertama carousel, escape HTML, dan ringkasan teks blok lainnya.
 */

it('mengambil sorotan pertama dari blok HeroGeser beserta gambar spesimennya', function (): void {
    $bagian = KontenSitusBawaan::AmbilHalaman()['beranda']['Bagian'];
    $pembuka = KerangkaHalamanSitus::AmbilPembuka($bagian);

    expect($bagian[0]['Jenis'])->toBe('HeroGeser')
        ->and($pembuka)->not->toBeNull()
        ->and($pembuka['Judul'])->toBe($bagian[0]['Sorotan'][0]['Judul'])
        ->and($pembuka['TombolUtama']['Label'])->toBe('Coba gratis')
        ->and($pembuka['Gambar']['Url'])->toBeNull()
        ->and($pembuka['Gambar']['Berkas'])->toBe('KasirSektorKafe')
        ->and($pembuka['Gambar']['Lebar'])->toBe(1600);

    expect(resource_path('js/Aset/Situs/KasirSektorKafe.webp'))->toBeFile();
});

it('mengambil blok Hero biasa dan memakai gambar unggahan bila ada', function (): void {
    $pembuka = KerangkaHalamanSitus::AmbilPembuka([[
        'Jenis' => 'Hero',
        'Judul' => 'Judul hero',
        'Subjudul' => 'Pengantar',
        'Spesimen' => 'Kasir',
        'Gambar' => ['Url' => '/storage/g.webp', 'Lebar' => 800, 'Tinggi' => 600, 'Alt' => 'Foto'],
    ]]);

    expect($pembuka['Teks'])->toBe('Pengantar')
        ->and($pembuka['Gambar']['Url'])->toBe('/storage/g.webp');
    expect(KerangkaHalamanSitus::AmbilPembuka([]))->toBeNull();
});

it('semua berkas spesimen yang dirujuk skema ada di resources/js/Aset/Situs dan terdaftar di manifest build (kecuali struk dan jurnal)', function (): void {
    $tanpaBerkas = ['Struk', 'Jurnal'];
    $ref = new ReflectionClassConstant(KerangkaHalamanSitus::class, 'FOTO');
    $peta = $ref->getValue();
    // Gambar ikut build Vite: tanpa entri di manifest, alamatnya tidak bisa dibuat (hosting tanpa Node memakai build ter-commit).
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    foreach (SkemaBagianSitus::SPESIMEN as $nama) {
        if (in_array($nama, $tanpaBerkas, true)) {
            continue;
        }

        expect($peta)->toHaveKey($nama);
        expect(resource_path("js/Aset/Situs/{$peta[$nama][0]}.webp"))->toBeFile();
        expect($manifest)->toHaveKey("resources/js/Aset/Situs/{$peta[$nama][0]}.webp");
    }
});

it('ringkasan meng-escape teks dan melewatkan blok pertama', function (): void {
    $html = KerangkaHalamanSitus::SusunRingkasan([
        ['Jenis' => 'Hero', 'Judul' => 'Sudah di kerangka'],
        ['Jenis' => 'Keunggulan', 'Judul' => 'Fitur <script>x</script>', 'Item' => [
            ['Judul' => 'Satu', 'Teks' => 'Isi "satu"'],
        ]],
        ['Jenis' => 'Cta', 'Judul' => 'Daftar', 'TombolUtama' => ['Label' => 'Coba', 'Tautan' => '/daftar']],
    ]);

    expect($html)->not->toContain('Sudah di kerangka')
        ->and($html)->not->toContain('<script>')
        ->and($html)->toContain('<h2>Fitur &lt;script&gt;x&lt;/script&gt;</h2>')
        ->and($html)->toContain('<h3>Satu</h3>')
        ->and($html)->toContain('<a href="/daftar">Coba</a>');
});
