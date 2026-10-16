import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { daftarPengaturan, SaringPengaturan } from '@/Pustaka/DaftarPengaturan';

import { daftarMenu } from './TataLetakAplikasi';

/*
 * Penjaga anggaran & peta navigasi back-office (D-27). File penjaga: kalau test ini gagal, perbaiki menunya,
 * bukan angkanya.
 *
 * Latar belakang: navigasi tumbuh satu flow demi satu flow tanpa ada yang memegang peta keseluruhannya, sampai
 * menu samping berisi 77 tautan dengan grup berisi 10 sub-menu, dan halaman seperti profil usaha malah tidak
 * punya entri menu sama sekali. Penjaga lama hanya mengawasi piksel (token, tabel, keadaan) — tidak ada satu pun
 * yang mengawasi struktur, jadi setiap penambahan selalu lolos.
 */

/*
 * 13 sejak grosir (PRD v2.78, penyesuaian D-27 atas mandat pemilik produk "berikan yang terbaik"): grosir adalah modul
 * penuh dengan tiga dokumen, peran akun, dan izinnya sendiri — cermin "Pembelian" di sisi beli, jadi tempatnya sejajar
 * dengannya. Alternatif yang diperiksa lebih dulu dan ditolak: menyelipkannya sebagai satu entri bertab di grup lain
 * mengharuskan satu halaman yang sudah ada kehilangan rumah menunya sekaligus entri Ctrl+K-nya (regresi), dan
 * memindahkan "Tutup harian" ke grup Akuntansi membuat pengguna berizin laporan penjualan saja ikut melihat grup
 * Akuntansi (kebocoran izin yang ditangkap `TataLetakAplikasiTes`).
 *
 * Angka ini tetap penjaga: menaikkannya lagi butuh alasan setingkat ini, bukan sekadar ada flow baru.
 */
const BATAS_LEVEL_SATU = 13;
/*
 * 8 sub-menu per grup sejak v6.49 (penyesuaian D-27 atas permintaan pemilik produk: "pengaturannya terlalu banyak").
 * Tiga halaman yang dibuka berkala pindah dari Pengaturan ke grup yang menjawab pertanyaannya: Perangkat kasir ke
 * Penjualan & kasir, Pusat Unduhan ke Laporan, Log audit ke Tim. Alternatif yang ditolak: menggabungkan entri mirip di
 * grup yang penuh, karena tanpa data pemakaian tidak ada yang aman digabung. Langganan & tagihan dan Keamanan akun
 * pindah ke menu akun (avatar), yang tidak memakai kuota ini. Grup lain tetap di 7; yang melewatinya harus
 * menjelaskan alasannya di sini, bukan sekadar menaikkan angkanya lagi.
 */
const BATAS_SUB_MENU = 8;

type Butir = { label: string; href: string; sub?: { label: string; href: string }[] };

const menu = daftarMenu as Butir[];
const grup = menu.filter(
    (butir): butir is Butir & { sub: { label: string; href: string }[] } => butir.sub !== undefined,
);

describe('Anggaran navigasi back-office (D-27)', () => {
    it(`menu utama maksimal ${String(BATAS_LEVEL_SATU)} entri`, () => {
        // Lebih dari ini tidak bisa dipindai sekali lihat. Yang jarang dipakai pindah ke Pengaturan.
        expect(menu.map((butir) => butir.label).length).toBeLessThanOrEqual(BATAS_LEVEL_SATU);
    });

    it(`setiap grup maksimal ${String(BATAS_SUB_MENU)} sub-menu`, () => {
        const kelebihan = grup
            .filter((butir) => butir.sub.length > BATAS_SUB_MENU)
            .map((butir) => `${butir.label}: ${String(butir.sub.length)}`);

        expect(kelebihan).toEqual([]);
    });

    it('grup berisi minimal dua sub-menu, kalau tidak jadikan item biasa', () => {
        const terlaluTipis = grup.filter((butir) => butir.sub.length < 2).map((butir) => butir.label);

        expect(terlaluTipis).toEqual([]);
    });

    it('satu halaman satu rumah: tidak ada tautan menu samping yang juga ada di Pengaturan', () => {
        // Kalau halaman muncul di dua tempat, pengguna tidak bisa membangun peta mental yang stabil.
        const alamatPengaturan = new Set(daftarPengaturan.flatMap(({ butir }) => butir.map((item) => item.href)));
        const bentrok = menu
            .flatMap((butir) => [butir, ...(butir.sub ?? [])])
            .filter((butir) => butir.href !== '/kelola/pengaturan' && alamatPengaturan.has(butir.href))
            .map((butir) => `${butir.label} (${butir.href})`);

        expect(bentrok).toEqual([]);
    });

    it('tidak ada tautan kembar di dalam menu samping', () => {
        const alamat = menu.flatMap((butir) => (butir.sub === undefined ? [butir.href] : butir.sub.map((s) => s.href)));
        const kembar = alamat.filter((href, i) => alamat.indexOf(href) !== i);

        expect(kembar).toEqual([]);
    });

    it('kotak cari Pengaturan menyaring nama grup, label, dan keterangan', () => {
        const BolehSemua = () => true;

        // Kata kosong = seluruh butir yang boleh dilihat.
        expect(SaringPengaturan('', BolehSemua)).toEqual(daftarPengaturan);

        // Cocok dari label.
        const struk = SaringPengaturan('struk', BolehSemua).flatMap(({ butir }) => butir.map((b) => b.label));
        expect(struk).toContain('Pengaturan struk');

        // Cocok dari keterangan saja: "NPWP" hanya muncul di keterangan Profil usaha.
        const npwp = SaringPengaturan('npwp', BolehSemua).flatMap(({ butir }) => butir.map((b) => b.label));
        expect(npwp).toEqual(['Profil usaha']);

        // Cocok dari nama grup: seluruh butir grup itu ikut.
        const katalog = SaringPengaturan('katalog', BolehSemua);
        expect(katalog).toHaveLength(1);
        expect(katalog[0]?.judul).toBe('Katalog & harga');

        // Tanpa huruf besar/kecil, dan tanpa hasil = daftar kosong (bukan grup kosong).
        expect(SaringPengaturan('  STRUK  ', BolehSemua).flatMap(({ butir }) => butir).length).toBeGreaterThan(0);
        expect(SaringPengaturan('zzz tidak ada', BolehSemua)).toEqual([]);
    });

    it('kotak cari tidak pernah menembus penyaringan izin', () => {
        // Regresi: pencarian tidak boleh memunculkan halaman yang pengguna tidak berhak melihatnya.
        const hanyaTanpaIzin = SaringPengaturan('', (butir) => butir.izin === null);
        const label = hanyaTanpaIzin.flatMap(({ butir }) => butir.map((b) => b.label));

        // Keamanan akun saya pindah ke menu akun (v6.49), jadi tidak ada lagi butir tanpa izin di Pengaturan.
        expect(label).toEqual([]);
        expect(SaringPengaturan('outlet', (butir) => butir.izin === null)).toEqual([]);
    });

    it('setiap butir Pengaturan punya label & keterangan yang terisi', () => {
        const kosong = daftarPengaturan
            .flatMap(({ judul, butir }) => butir.map((item) => ({ judul, ...item })))
            .filter((item) => item.label.trim() === '' || item.keterangan.trim() === '')
            .map((item) => `${item.judul}: ${item.href}`);

        expect(kosong).toEqual([]);
    });
});

describe('Alat impor tetap punya pintu masuk di halaman subjeknya (D-27)', () => {
    // Impor massal tinggal di Pengaturan, bukan di menu samping. Kalau tombol di halaman subjeknya hilang,
    // satu-satunya jalan tinggal Pengaturan & Ctrl+K — jadi tombol itu bagian dari keputusan ini, bukan hiasan.
    it.each([
        ['resources/js/Halaman/Kelola/Produk/Daftar.tsx', '/impor'],
        ['resources/js/Halaman/Kelola/Persediaan/StokAwal/Daftar.tsx', '/impor'],
    ])('%s menautkan halaman impornya', (berkas, jalur) => {
        const isi = readFileSync(join(process.cwd(), berkas), 'utf8');

        expect(isi).toContain(`${jalur}`);
        expect(isi).toContain('Impor dari Excel');
    });
});
