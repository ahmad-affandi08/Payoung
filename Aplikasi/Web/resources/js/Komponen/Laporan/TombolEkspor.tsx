import {
    ChevronDownIcon,
    DownloadIcon,
    FileSpreadsheetIcon,
    FileTextIcon,
    HourglassIcon,
    PrinterIcon,
} from 'lucide-react';

import { Button } from '@/Komponen/Ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Komponen/Ui/dropdown-menu';

export type FormatEkspor = 'xlsx' | 'csv' | 'cetak';

type PropsTombolEkspor = {
    /** Alamat ekspor tanpa query, mis. `/kelola/laporan/penjualan/ekspor`. */
    alamat: string;
    /** Query saringan halaman (tanpa `format`). */
    query?: string;
    label?: string;
    nonaktif?: boolean;
    /** Format yang ditawarkan; bawaan ketiganya (D-43). */
    format?: readonly FormatEkspor[];
    /**
     * D-124: tawarkan "Ekspor besar" (Excel, disiapkan di latar belakang lalu diunduh dari Pusat Unduhan). Hanya untuk
     * laporan yang rutenya memasang `EksporDiLatarBelakang`; bawaan mati.
     */
    eksporBesar?: boolean;
    className?: string;
};

const DAFTAR_FORMAT: Record<FormatEkspor, { judul: string; keterangan: string; ikon: typeof DownloadIcon }> = {
    xlsx: {
        judul: 'Excel (.xlsx)',
        keterangan: 'Lengkap dengan kop, filter, dan ringkasan',
        ikon: FileSpreadsheetIcon,
    },
    csv: { judul: 'CSV (data mentah)', keterangan: 'Tabel saja, untuk diolah sistem lain', ikon: FileTextIcon },
    cetak: { judul: 'Cetak atau simpan PDF', keterangan: 'Halaman siap cetak', ikon: PrinterIcon },
};

/** Alamat ekspor besar (D-124): Excel yang disiapkan di latar belakang, hasilnya di Pusat Unduhan. */
export function BuatAlamatEksporBesar(alamat: string, query: string): string {
    const parameter = new URLSearchParams(query);
    parameter.set('format', 'xlsx');
    parameter.set('latarbelakang', '1');

    return `${alamat}?${parameter.toString()}`;
}

/** Alamat unduhan satu format: menambahkan `format` ke query saringan halaman. */
export function BuatAlamatEkspor(alamat: string, query: string, format: FormatEkspor): string {
    const parameter = new URLSearchParams(query);
    parameter.set('format', format);

    return `${alamat}?${parameter.toString()}`;
}

/**
 * Tombol ekspor seragam semua laporan (D-43): satu tombol "Ekspor" dengan pilihan Excel, CSV, dan cetak/PDF.
 * Semua pilihan memakai saringan halaman yang sedang aktif. Pilihan cetak dibuka di tab baru.
 */
export default function TombolEkspor({
    alamat,
    query = '',
    label = 'Ekspor',
    nonaktif = false,
    format = ['xlsx', 'csv', 'cetak'],
    eksporBesar = false,
    className,
}: PropsTombolEkspor) {
    if (nonaktif) {
        return (
            <Button type="button" variant="outline" size="sm" disabled className={className}>
                <DownloadIcon aria-hidden="true" />
                {label}
            </Button>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline" size="sm" className={className}>
                    <DownloadIcon aria-hidden="true" />
                    {label}
                    <ChevronDownIcon aria-hidden="true" className="size-3.5 text-teks-sekunder" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-72">
                {format.map((jenis) => {
                    const { judul, keterangan, ikon: Ikon } = DAFTAR_FORMAT[jenis];

                    return (
                        <DropdownMenuItem key={jenis} asChild className="cursor-pointer items-start gap-3 py-2">
                            <a
                                href={BuatAlamatEkspor(alamat, query, jenis)}
                                {...(jenis === 'cetak' ? { target: '_blank', rel: 'noopener' } : {})}
                            >
                                <Ikon aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-teks-sekunder" />
                                <span className="flex flex-col">
                                    <span className="text-label font-semibold text-teks-utama">{judul}</span>
                                    <span className="text-keterangan text-teks-sekunder">{keterangan}</span>
                                </span>
                            </a>
                        </DropdownMenuItem>
                    );
                })}
                {eksporBesar && format.includes('xlsx') ? (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild className="cursor-pointer items-start gap-3 py-2">
                            <a href={BuatAlamatEksporBesar(alamat, query)}>
                                <HourglassIcon
                                    aria-hidden="true"
                                    className="mt-0.5 size-4 shrink-0 text-teks-sekunder"
                                />
                                <span className="flex flex-col">
                                    <span className="text-label font-semibold text-teks-utama">
                                        Ekspor besar (Excel)
                                    </span>
                                    <span className="text-keterangan text-teks-sekunder">
                                        Untuk data sangat banyak: disiapkan di latar belakang, unduh dari Pusat Unduhan
                                    </span>
                                </span>
                            </a>
                        </DropdownMenuItem>
                    </>
                ) : null}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
