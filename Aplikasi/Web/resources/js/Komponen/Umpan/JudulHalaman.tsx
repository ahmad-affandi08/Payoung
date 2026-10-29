import type { ReactNode } from 'react';

import { cn } from '@/Komponen/Ui/utils';

type PropsJudulHalaman = {
    /**
     * Skala judul halaman:
     * - `halaman` (bawaan): back-office, konsol, dan halaman publik transaksional — `text-judul`.
     * - `situs`: halaman pemasaran, yang memang memakai skala tampilan lebih besar (§17.5).
     * - `sorotan`: judul besar pembuka beranda (carousel hero), `text-sorotan` di layar lebar.
     * - `toko`: nama toko di kepala toko online, ukuran terbesar token (`sorotan-besar`) dan tebal supaya terbaca dari jauh.
     * - `ringkas`: judul di dalam kartu sempit (struk digital, keadaan kosong) yang tidak boleh selebar halaman.
     */
    skala?: 'halaman' | 'situs' | 'sorotan' | 'toko' | 'ringkas';
    className?: string;
    children: ReactNode;
};

const kelasSkala = {
    halaman: 'text-judul font-bold text-teks-utama',
    situs: 'text-judul-bagian-hp font-bold text-teks-utama sm:text-judul-bagian',
    sorotan: 'text-sorotan-hp font-bold text-teks-utama sm:text-sorotan',
    toko: 'text-sorotan-besar-hp font-extrabold text-teks-utama sm:text-sorotan-besar',
    ringkas: 'text-subjudul font-semibold text-teks-utama',
} as const;

/**
 * Judul halaman (`<h1>`) — satu-satunya tempat `<h1>` didefinisikan (D-28, §17.4.11).
 *
 * Masalah yang diperbaiki: kelima tata letak sudah memakai `text-judul font-bold text-teks-utama`, tetapi halaman
 * yang berdiri sendiri (QR meja, cetak pesanan, reservasi publik, struk digital) menulis kombinasinya sendiri —
 * ada yang `font-semibold`, ada yang lupa `text-teks-utama` sehingga warnanya ikut warisan. Judul halaman adalah
 * hal pertama yang dibaca pengguna dan pembaca layar, jadi ukurannya tidak boleh berbeda-beda per modul.
 *
 * Skala ditulis eksplisit lewat prop, bukan ditimpa lewat `className`: menumpuk dua kelas ukuran font membuat
 * hasilnya bergantung urutan CSS, bukan pada niat penulisnya.
 */
export default function JudulHalaman({ skala = 'halaman', className, children }: PropsJudulHalaman) {
    return <h1 className={cn(kelasSkala[skala], className)}>{children}</h1>;
}
