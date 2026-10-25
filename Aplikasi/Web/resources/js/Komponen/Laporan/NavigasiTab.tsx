import { DownloadIcon } from 'lucide-react';

import TabTautan from '@/Komponen/Navigasi/TabTautan';
import TombolEkspor, { type FormatEkspor } from '@/Komponen/Laporan/TombolEkspor';
import { Button } from '@/Komponen/Ui/button';
import { BuatQueryLaporan } from '@/Pustaka/Laporan';

type PropsNavigasiTab = {
    label: string;
    alamat: string;
    /** Saring halaman saat ini (tanpa `tab`). */
    query: Record<string, string>;
    tabAktif: string;
    tab: { nilai: string; label: string }[];
};

/**
 * Navigasi tab laporan sebagai tautan (keadaan di URL, bisa dibagikan): membawa saring halaman, membuang keadaan
 * tabel tab sebelumnya. Gaya tab bersama `TabTautan`.
 */
export default function NavigasiTab({ label, alamat, query, tabAktif, tab }: PropsNavigasiTab) {
    return (
        <TabTautan
            label={label}
            pertahankanGulir
            tab={tab.map((t) => ({
                label: t.label,
                href: `${alamat}?${BuatQueryLaporan({ ...query, tab: t.nilai })}`,
                aktif: t.nilai === tabAktif,
            }))}
        />
    );
}

/** Tombol ekspor laporan sesuai saring halaman: Excel, CSV (data mentah), dan cetak/PDF (D-43). */
export function TautanEkspor({
    alamat,
    query,
    label = 'Ekspor',
    format,
    eksporBesar = false,
}: {
    alamat: string;
    query: Record<string, string>;
    label?: string;
    format?: readonly FormatEkspor[];
    /** D-124: tawarkan "Ekspor besar" (hanya rute yang memasang `EksporDiLatarBelakang`). */
    eksporBesar?: boolean;
}) {
    return (
        <TombolEkspor
            alamat={alamat}
            query={BuatQueryLaporan(query)}
            label={label}
            eksporBesar={eksporBesar}
            {...(format === undefined ? {} : { format })}
        />
    );
}

/** Tautan unduh satu berkas berformat tetap (XML Coretax, CSV nota retur): bukan laporan Excel/CSV/cetak. */
export function TautanUnduh({
    alamat,
    query,
    label,
}: {
    alamat: string;
    query: Record<string, string>;
    label: string;
}) {
    const teksQuery = BuatQueryLaporan(query);

    return (
        <Button asChild variant="outline" className="h-8 pointer-coarse:h-11 px-4 text-label font-semibold">
            <a href={teksQuery === '' ? alamat : `${alamat}?${teksQuery}`}>
                <DownloadIcon aria-hidden="true" />
                {label}
            </a>
        </Button>
    );
}
