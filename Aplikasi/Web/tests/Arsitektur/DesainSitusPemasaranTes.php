<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Penjaga pengecualian desain D-25 (PRD §17.5, §17.6.3, §17.6.4). File penjaga: kalau test ini gagal,
 * perbaiki kodenya, bukan test-nya.
 *
 * D-25 melonggarkan tiga aturan §17 HANYA untuk situs pemasaran `payoung.id`. Test ini memastikan
 * kelonggaran itu tidak merembes ke back-office, Platform Pengelola, atau web publik tenant:
 *
 * - token warna `Aksen` (kuning `#f4a261`) — bukan penanda status, hanya aksen grafis pemasaran;
 * - token ukuran judul `sorotan-besar` / `sorotan-besar-hp` — hanya hero pemasaran;
 * - utilitas `muncul-saat-gulir` — animasi masuk 320 ms, di luar batas 100–200 ms §17.6.4.
 *
 * Pemakaian di aplikasi Flutter dijaga terpisah oleh `Paket/SistemDesain/test/Token/SumberWarna_test.dart`
 * (nilai token web & Flutter wajib sama) dan oleh larangan warna lepas di luar `TokenWarna.dart`.
 */

/**
 * Berkas sumber frontend yang diperiksa (TSX/TS/CSS di `resources/js`).
 *
 * Berkas penjaga (`*Tes.ts`/`*Tes.tsx`) dikecualikan: isinya justru **menyebut** pola terlarang sebagai bahan uji
 * atau di dalam penjelasan regresinya, dan berkas penjaga tidak pernah ikut dirender ke antarmuka. Tanpa
 * pengecualian ini, menulis penjelasan yang jelas di sebuah penjaga akan menggagalkan penjaga yang lain.
 */
function BerkasFrontend(): Finder
{
    return Finder::create()
        ->files()
        ->in(resource_path('js'))
        ->name(['*.tsx', '*.ts', '*.css'])
        ->notName(['*Tes.ts', '*Tes.tsx']);
}

/**
 * Jalur (relatif terhadap `resources/js`) yang boleh memakai kelonggaran D-25:
 * definisi tokennya sendiri, plus seluruh berkas situs pemasaran.
 *
 * @return list<string>
 */
function JalurBolehD25(): array
{
    return [
        'Gaya/Aplikasi.css',
        'Gaya/UtilitasKomponen.css',
        // Registry tailwind-merge `cn` (D-28): wajib memuat SELURUH nama token `--text-*`, termasuk skala hero
        // pemasaran. Tanpa terdaftar di sini, tailwind-merge menganggap token itu warna teks dan membuang
        // ukurannya — jadi daftar ini bagian dari definisi tokennya, bukan pemakaian di antarmuka.
        'Komponen/Ui/utils.ts',
        'Komponen/Situs/',
        'Halaman/Situs/',
        'TataLetak/TataLetakSitus.tsx',
        'Tipe/Situs.ts',
        // D-61: Aksen Apricot juga warna merek di tanda muat (D-58), halaman absensi HP (D-54, jam besar), dan toko
        // online per tenant (D-55). Tetap bukan penanda status. Sebelumnya tiga jalur ini memakainya tanpa daftar
        // pengecualian, sehingga penjaga ini merah sejak D-54/D-55/D-58.
        'Gaya/Muat.css',
        'Halaman/Publik/Absensi.tsx',
        'Halaman/Publik/TokoOnline.tsx',
        // D-238: halaman toko online publik dipecah ke `Komponen/TokoOnline/` (kepala, kartu sorotan, kartu produk, keranjang)
        // dan status pesanan memakai gaya yang sama, jadi ruangnya tetap yang sama dengan D-55 (Aksen = warna merek
        // toko, bukan penanda status). `JudulHalaman` memuat skala `toko` (nama toko besar di kepala) sebagai definisi
        // skala, bukan pemakaian di antarmuka back-office.
        'Komponen/TokoOnline/',
        'Halaman/Publik/StatusPesananOnline.tsx',
        'Komponen/Umpan/JudulHalaman.tsx',
        // D-80: kios pesan sendiri dan layar antrian adalah papan yang dibaca dari jarak jauh (nomor antrian, judul layar
        // sambutan), jadi memakai skala besar yang sama dengan hero; tetap bukan bagian back-office atau halaman tenant biasa.
        'Halaman/Publik/Kios.tsx',
        'Halaman/Publik/AntrianKios.tsx',
        // D-87: panel iklan halaman masuk & daftar back-office adalah ruang pemasaran (harga peluncuran dari katalog)
        // di panel merek gelap; Aksen Apricot di situ penanda merek (lencana, penunjuk slide), bukan penanda status.
        'Komponen/Autentikasi/PanelIklanAuth.tsx',
        'TataLetak/TataLetakAutentikasi.tsx',
        // D-180/D-198: komponen halaman Absen HP (jam besar, durasi bekerja) pindah dari `Halaman/Publik/Absensi.tsx` ke
        // `Komponen/Absensi/` supaya dipakai bersama halaman berlogin dan tautan pribadi; ruangnya tetap yang sama.
        'Komponen/Absensi/',
        // D-193: bilah status gelap memakai Aksen hanya sebagai warna tautan di atas `brand-gelap` (penanda nada
        // dipegang token status). D-178: kartu pilih jenis usaha memakai Aksen untuk tanda pilihan dan titik kelompok;
        // keduanya penanda merek/pilihan, bukan status.
        'Komponen/Umpan/Pemberitahuan.tsx',
        'Komponen/PanduanAwal/KartuSektor.tsx',
        'Pustaka/TampilanSektor.ts',
    ];
}

/**
 * Berkas yang memakai salah satu pola terlarang di luar jalur yang diizinkan.
 *
 * @param  list<string>  $pola
 * @return list<string>
 */
function CariPemakaianDiLuarSitus(array $pola): array
{
    $akar = resource_path('js').DIRECTORY_SEPARATOR;
    $pelanggaran = [];

    foreach (BerkasFrontend() as $berkas) {
        $relatif = str_replace(DIRECTORY_SEPARATOR, '/', substr($berkas->getRealPath(), strlen($akar)));

        foreach (JalurBolehD25() as $boleh) {
            if (str_starts_with($relatif, $boleh)) {
                continue 2;
            }
        }

        $isi = $berkas->getContents();

        foreach ($pola as $satu) {
            if (preg_match($satu, $isi) === 1) {
                $pelanggaran[] = $relatif;

                continue 2;
            }
        }
    }

    return $pelanggaran;
}

test('token warna Aksen hanya dipakai di situs pemasaran (D-25)', function (): void {
    // Menangkap utilitas Tailwind (bg-aksen, text-aksen, border-aksen, bg-aksen-lembut) dan var CSS
    // (--color-aksen), tanpa ikut menangkap kata "aksen" di dalam prosa komentar Bahasa Indonesia.
    $pelanggaran = CariPemakaianDiLuarSitus(['/[a-z]+-aksen(-lembut)?\b/']);

    expect($pelanggaran)->toBe([], implode("\n", [
        'Aksen (#f4a261) terbatas pada situs pemasaran payoung.id (D-25) dan tidak pernah menandai status.',
        'Pakai token status (sukses/peringatan/bahaya/info) di luar situs pemasaran. Berkas:',
        ...$pelanggaran,
    ]));
});

test('token judul sorotan-besar hanya dipakai di situs pemasaran (D-25)', function (): void {
    $pelanggaran = CariPemakaianDiLuarSitus(['/\bsorotan-besar\b/']);

    expect($pelanggaran)->toBe([], implode("\n", [
        'sorotan-besar (64px) / sorotan-besar-hp (40px) hanya untuk hero payoung.id (D-25).',
        'Di luar itu pakai enam token skala §17.5. Berkas:',
        ...$pelanggaran,
    ]));
});

test('animasi muncul-saat-gulir hanya dipakai di situs pemasaran (D-25)', function (): void {
    $pelanggaran = CariPemakaianDiLuarSitus(['/\bmuncul-saat-gulir\b/']);

    expect($pelanggaran)->toBe([], implode("\n", [
        'muncul-saat-gulir berdurasi 320 ms, di luar batas 100–200 ms §17.6.4. Hanya payoung.id (D-25).',
        'Berkas:',
        ...$pelanggaran,
    ]));
});

test('tidak ada teks putih di atas Aksen (kontras 2,1:1)', function (): void {
    $pelanggaran = [];

    foreach (BerkasFrontend() as $berkas) {
        foreach (preg_split('/\R/', $berkas->getContents()) ?: [] as $nomor => $baris) {
            // Kelas Tailwind biasanya berdampingan dalam satu atribut className, jadi pasangan
            // berbahaya (bg-aksen + text-permukaan) terdeteksi pada baris yang sama.
            if (preg_match('/\bbg-aksen\b/', $baris) !== 1) {
                continue;
            }

            if (preg_match('/\btext-(permukaan|brand-teks|bahaya-teks)\b/', $baris) === 1) {
                $pelanggaran[] = $berkas->getFilename().':'.($nomor + 1).': '.trim($baris);
            }
        }
    }

    expect($pelanggaran)->toBe([], implode("\n", [
        'Teks putih di atas Aksen hanya 2,1:1 (gagal WCAG AA). Pakai text-teks-utama (6,4:1). Baris:',
        ...$pelanggaran,
    ]));
});
