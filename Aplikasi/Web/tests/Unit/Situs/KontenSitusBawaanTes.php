<?php

declare(strict_types=1);

use App\Domain\Situs\Layanan\KontenSitusBawaan;
use App\Domain\Situs\Layanan\SkemaBagianSitus;
use App\Domain\Situs\Layanan\ValidatorBagianSitus;

/*
 * Isi bawaan situs pemasaran (D-21) langsung dirender tanpa melewati editor konsol, jadi tidak ada yang
 * memvalidasinya saat dipasang. Test ini memastikan copy bawaan tetap lolos skema blok yang sama dengan
 * yang dipakai konsol, dan tetap memenuhi aturan susunan D-25.
 *
 * Semua halaman bawaan tidak memakai gambar, sehingga validator tidak menyentuh database.
 */

it('semua halaman bawaan lolos skema blok konsol', function (): void {
    $validator = new ValidatorBagianSitus;

    foreach (KontenSitusBawaan::AmbilHalaman() as $slug => $isi) {
        $hasil = $validator->Periksa($isi['Bagian']);

        expect($hasil)->toHaveCount(count($isi['Bagian']), "Blok halaman {$slug} berkurang setelah divalidasi.");
    }
});

it('setiap halaman bawaan punya judul dan deskripsi SEO', function (): void {
    foreach (KontenSitusBawaan::AmbilHalaman() as $slug => $isi) {
        expect($isi['JudulSeo'])->not->toBe('', "Halaman {$slug} tanpa judul SEO.");
        expect(mb_strlen($isi['DeskripsiSeo']))->toBeGreaterThan(50)->toBeLessThanOrEqual(320);
    }
});

it('tidak ada dua blok sejenis berurutan dengan bentuk sama (D-25)', function (): void {
    // Pelanggaran dikumpulkan dulu, lalu diasersi sekali. Versi sebelumnya hanya memanggil `expect()` di dalam
    // `if`, sehingga begitu tidak ada blok sejenis yang berurutan test ini tidak melakukan asersi apa pun —
    // Pest menandainya "risky" dan job CI gagal walau tidak ada test yang benar-benar salah. Aturan yang
    // dijaga tetap sama; yang berubah hanya cara melaporkannya supaya tidak bergantung pada isi data.
    $pelanggar = [];

    foreach (KontenSitusBawaan::AmbilHalaman() as $slug => $isi) {
        $sebelumnya = null;

        foreach ($isi['Bagian'] as $i => $blok) {
            $jenis = $blok['Jenis'];

            // Dua blok sejenis hanya boleh berdampingan bila bentuknya memang berbeda.
            if ($sebelumnya !== null
                && $sebelumnya['Jenis'] === $jenis
                && ($blok['TataLetak'] ?? null) === ($sebelumnya['TataLetak'] ?? null)) {
                $pelanggar[] = "Halaman {$slug} blok {$i}: dua blok {$jenis} berurutan dengan bentuk sama.";
            }

            $sebelumnya = $blok;
        }
    }

    expect($pelanggar)->toBe([]);
});

it('halaman fitur tidak lagi berisi tiga grid keunggulan berturut-turut (D-25)', function (): void {
    $bagian = KontenSitusBawaan::AmbilHalaman()['fitur']['Bagian'];
    $jenis = array_column($bagian, 'Jenis');

    // Bentuk lama: Hero, Keunggulan, Keunggulan, Keunggulan, Cta.
    expect($jenis)->toBe(['Hero', 'Keunggulan', 'GambarTeks', 'Keunggulan', 'GambarTeks', 'Cta']);

    $tataLetak = array_values(array_filter(array_map(
        fn (array $b): ?string => $b['Jenis'] === 'Keunggulan' ? ($b['TataLetak'] ?? null) : null,
        $bagian,
    )));

    expect($tataLetak)->toBe(['Sorot', 'Daftar']);
});

it('copy bawaan tidak memakai frasa pemasaran kosong (D-25)', function (): void {
    // Frasa yang membuat halaman terasa dibuat mesin: klaim tanpa isi. Judul harus menyebut hal konkret.
    $terlarang = ['tanpa ribet', 'fitur lengkap', 'semua yang dibutuhkan', 'solusi terbaik', 'solusi lengkap'];
    $ditemukan = [];

    foreach (KontenSitusBawaan::AmbilHalaman() as $slug => $isi) {
        $teks = mb_strtolower(json_encode($isi, JSON_UNESCAPED_UNICODE) ?: '');

        foreach ($terlarang as $frasa) {
            if (str_contains($teks, $frasa)) {
                $ditemukan[] = "{$slug}: \"{$frasa}\"";
            }
        }
    }

    expect($ditemukan)->toBe([]);
});

it('ikon yang dipakai copy bawaan ada di daftar ikon yang diizinkan', function (): void {
    $tidakDikenal = [];

    foreach (KontenSitusBawaan::AmbilHalaman() as $slug => $isi) {
        foreach ($isi['Bagian'] as $blok) {
            foreach ($blok['Item'] ?? [] as $item) {
                $ikon = $item['Ikon'] ?? null;

                if (is_string($ikon) && ! in_array($ikon, SkemaBagianSitus::IKON, true)) {
                    $tidakDikenal[] = "{$slug}: {$ikon}";
                }
            }
        }
    }

    expect($tidakDikenal)->toBe([]);
});

it('beranda bawaan dibuka carousel delapan sorotan (kasir tiap sektor usaha, pembayaran, Aplikasi Pemilik) berisi tangkapan layar asli, tanpa klaim sosial karangan (D-39)', function (): void {
    $bagian = KontenSitusBawaan::AmbilHalaman()['beranda']['Bagian'];
    $jenis = array_column($bagian, 'Jenis');

    expect($jenis[0])->toBe('HeroGeser')
        ->and($bagian[0]['Sorotan'])->toHaveCount(8)
        ->and($jenis)->toContain('TabUsaha', 'Langkah', 'Integrasi', 'Harga', 'Faq', 'Cta')
        // Bukti sosial hanya boleh diisi pemilik dari konsol: tidak ada testimoni atau logo klien karangan.
        ->and($jenis)->not->toContain('Testimoni')
        ->and($jenis)->not->toContain('LogoMitra');

    foreach ($bagian[0]['Sorotan'] as $sorotan) {
        expect(SkemaBagianSitus::SPESIMEN)->toContain($sorotan['Spesimen']);
        expect($sorotan['TombolUtama'])->not->toBeNull();
    }

    // D-212: enam sorotan pertama memperlihatkan kasir tiap sektor, dan semua berkas gambarnya ada di build.
    $spesimen = array_column($bagian[0]['Sorotan'], 'Spesimen');
    expect(array_slice($spesimen, 0, 6))->toBe(['KasirSektorKafe', 'KasirSektorToko', 'KasirSektorSalon', 'KasirSektorLaundry', 'KasirSektorApotek', 'KasirSektorBengkel']);

    foreach (array_slice($spesimen, 0, 6) as $nama) {
        expect(resource_path("js/Aset/Situs/{$nama}.webp"))->toBeFile();
    }
});
