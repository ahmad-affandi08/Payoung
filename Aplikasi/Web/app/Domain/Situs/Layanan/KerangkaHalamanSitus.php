<?php

declare(strict_types=1);

namespace App\Domain\Situs\Layanan;

use Illuminate\Support\Facades\Vite;
use Throwable;

/**
 * Kerangka HTML dari server untuk halaman situs pemasaran (D-21, tanpa SSR Node di hosting bersama).
 *
 * Halaman situs dirender React di peramban. Sebelum bundelnya selesai dimuat (puluhan modul kecil), atau bila
 * satu modul gagal diambil, `<div id="app">` kosong: pengunjung melihat layar putih, dan perayap/pratinjau tautan
 * yang tidak menjalankan JavaScript tidak menemukan isi apa pun. Kerangka ini mengisi `#app` dengan blok pertama
 * (judul, pengantar, tombol, gambar pertama) memakai kelas Tailwind yang sama dengan komponen React-nya, dan
 * `<noscript>` memuat ringkasan teks blok lainnya. React `createRoot` mengganti isi `#app` saat siap, jadi
 * kerangka tidak pernah ikut tampil berdampingan dengan halaman sebenarnya.
 *
 * Semua teks di-escape oleh Blade (`{{ }}`) atau {@see self::H()}; tidak ada HTML dari data konsol yang dilewatkan mentah.
 */
final class KerangkaHalamanSitus
{
    /**
     * Spesimen → berkas gambar di `resources/js/Aset/Situs` (tablet 1600 × 1000, ponsel 540 × 1080). Selaras
     * `Komponen/Situs/SpesimenSitus.tsx`. Berkasnya ikut build Vite (alamat bernama ber-hash dari manifest), bukan berkas
     * statis di `public/`, supaya terkirim bersama aset lain di hosting mana pun.
     */
    private const FOTO = [
        'Kasir' => ['KasirJual', 1600, 1000],
        'KasirBayar' => ['KasirBayar', 1600, 1000],
        'KasirBerhasil' => ['KasirBerhasil', 1600, 1000],
        'KasirShift' => ['KasirShift', 1600, 1000],
        'KasirRiwayat' => ['KasirRiwayat', 1600, 1000],
        'KasirKas' => ['KasirKas', 1600, 1000],
        'KasirSektorKafe' => ['KasirSektorKafe', 1600, 1000],
        'KasirSektorToko' => ['KasirSektorToko', 1600, 1000],
        'KasirSektorSalon' => ['KasirSektorSalon', 1600, 1000],
        'KasirSektorLaundry' => ['KasirSektorLaundry', 1600, 1000],
        'KasirSektorApotek' => ['KasirSektorApotek', 1600, 1000],
        'KasirSektorBengkel' => ['KasirSektorBengkel', 1600, 1000],
        'KasirHp' => ['KasirHpJual', 540, 1080],
        'Pemilik' => ['PemilikBeranda', 540, 1080],
        'PemilikDuo' => ['PemilikBeranda', 540, 1080],
        'PemilikLaporan' => ['PemilikLaporan', 540, 1080],
        'PemilikPersetujuan' => ['PemilikPersetujuan', 540, 1080],
        'PemilikKaryawan' => ['PemilikKaryawan', 540, 1080],
        'PemilikInsight' => ['PemilikInsight', 540, 1080],
    ];

    /**
     * Alamat gambar spesimen dari manifest Vite (`/build/assets/KasirJual-HASH.webp`), atau null bila manifest belum
     * memuatnya (mis. build belum dijalankan): kerangka lalu tampil tanpa gambar, bukan galat.
     */
    public static function UrlBerkas(?string $berkas): ?string
    {
        if ($berkas === null || $berkas === '') {
            return null;
        }

        try {
            return Vite::asset("resources/js/Aset/Situs/{$berkas}.webp");
        } catch (Throwable) {
            return null;
        }
    }

    private const KUNCI_JUDUL = ['Judul', 'Nama', 'Pertanyaan'];

    private const KUNCI_PARAGRAF = ['Subjudul', 'Teks', 'Isi', 'Jawaban', 'Keterangan', 'Catatan', 'Kutipan'];

    private const KUNCI_DAFTAR = ['Sorotan', 'Item', 'Kelompok', 'Paket', 'Poin', 'Fitur'];

    /**
     * Isi pembuka dari blok pertama halaman, atau null bila halaman tanpa blok.
     *
     * @param  list<array<string, mixed>>  $bagian
     * @return array{Label: ?string, Judul: string, Teks: ?string, TombolUtama: ?array<string, string>, TombolKedua: ?array<string, string>, Gambar: ?array{Url: ?string, Berkas: ?string, Lebar: ?int, Tinggi: ?int, Alt: string}}|null
     */
    public static function AmbilPembuka(array $bagian): ?array
    {
        $blok = $bagian[0] ?? null;

        if (! is_array($blok)) {
            return null;
        }

        $sumber = ($blok['Jenis'] ?? null) === 'HeroGeser' ? ($blok['Sorotan'][0] ?? null) : $blok;

        if (! is_array($sumber) || ! is_string($sumber['Judul'] ?? null)) {
            return null;
        }

        $gambar = null;

        if (is_array($sumber['Gambar'] ?? null) && is_string($sumber['Gambar']['Url'] ?? null)) {
            $gambar = [
                'Url' => $sumber['Gambar']['Url'],
                'Berkas' => null,
                'Lebar' => is_int($sumber['Gambar']['Lebar'] ?? null) ? $sumber['Gambar']['Lebar'] : null,
                'Tinggi' => is_int($sumber['Gambar']['Tinggi'] ?? null) ? $sumber['Gambar']['Tinggi'] : null,
                'Alt' => (string) ($sumber['Gambar']['Alt'] ?? ''),
            ];
        } elseif (is_string($sumber['Spesimen'] ?? null) && isset(self::FOTO[$sumber['Spesimen']])) {
            [$berkas, $lebar, $tinggi] = self::FOTO[$sumber['Spesimen']];
            $gambar = ['Url' => null, 'Berkas' => $berkas, 'Lebar' => $lebar, 'Tinggi' => $tinggi, 'Alt' => 'Tangkapan layar aplikasi Payoung'];
        }

        $teks = $sumber['Teks'] ?? $sumber['Subjudul'] ?? null;

        return [
            'Label' => is_string($sumber['Label'] ?? null) ? $sumber['Label'] : null,
            'Judul' => $sumber['Judul'],
            'Teks' => is_string($teks) ? $teks : null,
            'TombolUtama' => self::AmbilTombol($sumber['TombolUtama'] ?? null),
            'TombolKedua' => self::AmbilTombol($sumber['TombolKedua'] ?? null),
            'Gambar' => $gambar,
        ];
    }

    /**
     * Ringkasan teks blok setelah blok pertama sebagai HTML sederhana (untuk `<noscript>`).
     *
     * @param  list<array<string, mixed>>  $bagian
     */
    public static function SusunRingkasan(array $bagian): string
    {
        $html = '';

        foreach (array_slice($bagian, 1) as $blok) {
            if (is_array($blok)) {
                $html .= '<section>'.self::SusunNode($blok, 2).'</section>';
            }
        }

        return $html;
    }

    /** @return array{Label: string, Tautan: string}|null */
    private static function AmbilTombol(mixed $tombol): ?array
    {
        if (is_array($tombol) && is_string($tombol['Label'] ?? null) && is_string($tombol['Tautan'] ?? null) && $tombol['Label'] !== '') {
            return ['Label' => $tombol['Label'], 'Tautan' => $tombol['Tautan']];
        }

        return null;
    }

    /** @param  array<string, mixed>  $node */
    private static function SusunNode(array $node, int $tingkat): string
    {
        $html = '';

        foreach (self::KUNCI_JUDUL as $kunci) {
            if (is_string($node[$kunci] ?? null) && $node[$kunci] !== '') {
                $html .= self::H($node[$kunci], 'h'.min($tingkat, 4));
                break;
            }
        }

        foreach (self::KUNCI_PARAGRAF as $kunci) {
            if (is_string($node[$kunci] ?? null) && $node[$kunci] !== '') {
                $html .= '<p>'.e($node[$kunci]).'</p>';
            }
        }

        foreach (['Tombol', 'TombolUtama', 'TombolKedua'] as $kunci) {
            $tombol = self::AmbilTombol($node[$kunci] ?? null);

            if ($tombol !== null) {
                $html .= '<p><a href="'.e($tombol['Tautan']).'">'.e($tombol['Label']).'</a></p>';
            }
        }

        foreach (self::KUNCI_DAFTAR as $kunci) {
            if (! is_array($node[$kunci] ?? null)) {
                continue;
            }

            $butir = '';

            foreach ($node[$kunci] as $anak) {
                if (is_string($anak) && $anak !== '') {
                    $butir .= '<li>'.e($anak).'</li>';
                } elseif (is_array($anak)) {
                    $isi = self::SusunNode($anak, $tingkat + 1);
                    $butir .= $isi === '' ? '' : '<li>'.$isi.'</li>';
                }
            }

            $html .= $butir === '' ? '' : '<ul>'.$butir.'</ul>';
        }

        return $html;
    }

    private static function H(string $teks, string $tag): string
    {
        return "<{$tag}>".e($teks)."</{$tag}>";
    }
}
