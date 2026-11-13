import type { ReactNode } from 'react';

import { Table } from '@/Komponen/Ui/table';
import { cn } from '@/Komponen/Ui/utils';

/**
 * Lebar minimum tabel. Angkanya tinggal di satu tempat, bukan tersebar sebagai `min-w-[...]` di halaman domain.
 * Nilainya dibulatkan **ke atas** dari lebar yang dipakai sebelumnya, jadi tidak ada tabel yang jadi lebih sempit.
 */
const kelasLebar = {
    /** Dua–tiga kolom pendek (harga bertingkat). */
    sempit: 'min-w-[480px]',
    /** Tabel isian biasa: bahan resep, komponen, pemetaan impor, batas stok. */
    sedang: 'min-w-[640px]',
    /** Tabel isian berkolom banyak: baris jurnal, resep lengkap, template sektor. */
    lebar: 'min-w-[768px]',
    /** Dokumen: baris pembelian, stok awal — kolom harga, diskon, pajak, subtotal sekaligus. */
    dokumen: 'min-w-[896px]',
} as const;

type PropsTabelForm = {
    /** Nama region bagi pembaca layar, mis. "Bahan resep". Wajib: region tanpa nama tidak berguna. */
    label: string;
    lebar?: keyof typeof kelasLebar;
    /** Kelas tambahan untuk elemen `<table>`, mis. `text-label` pada tabel ringkas. */
    kelasTabel?: string;
    /**
     * Di bawah 1024px tiap baris tampil sebagai kartu kecil, bukan tabel yang digulir ke samping. Sel memberi
     * `data-label` (judul kolom di kartu), `data-penuh` (selebar kartu), `data-ringkas` (label dan nilai sebaris, untuk
     * subtotal), atau `data-aksi` (tombol di ujung kanan). Hanya untuk tabel dokumen berkolom banyak.
     */
    tumpuk?: boolean;
    className?: string;
    children: ReactNode;
};

/**
 * Tabel isian/rincian yang tidak cocok memakai `TabelData` (§17.4.3 pengecualian: baris berisi bidang yang
 * diedit, dan rincian dokumen kecil seperti baris jurnal atau rincian HPP).
 *
 * Dua hal yang diperbaiki di D-28:
 * 1. **Lebar minimum tersebar sebagai nilai arbitrer.** Ada 11 nilai berbeda di 13 berkas (420px sampai 880px,
 *    dua di antaranya dalam `rem`), jadi tabel dengan kolom sejenis punya titik gulir yang berbeda-beda.
 *    Sekarang empat preset, dan angkanya hanya ada di berkas ini.
 * 2. **Area gulirnya tidak bisa difokus keyboard.** Container gulir bawaan `Table` shadcn tidak punya `tabIndex`,
 *    jadi pengguna keyboard tidak bisa menggeser tabel yang lebih lebar dari layar (WCAG 2.1.1). Wadah luar di
 *    sini yang menggulir dan bisa difokus, sementara container bawaan dimatikan lewat aturan `.tabel-form` di
 *    `Gaya/Aplikasi.css` — `Komponen/Ui/` tidak disentuh, jadi komponennya tetap boleh dipasang ulang lewat CLI.
 */
export default function TabelForm({
    label,
    lebar = 'sedang',
    kelasTabel,
    tumpuk = false,
    className,
    children,
}: PropsTabelForm) {
    return (
        <div
            role="region"
            aria-label={label}
            data-tumpuk={tumpuk ? '' : undefined}
            tabIndex={0}
            className={cn('tabel-form overflow-x-auto focus-visible:ring-2 focus-visible:ring-brand', className)}
        >
            <Table className={cn(kelasLebar[lebar], 'text-left text-isi', kelasTabel)}>{children}</Table>
        </div>
    );
}
