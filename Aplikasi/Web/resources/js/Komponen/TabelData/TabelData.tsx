import { router } from '@inertiajs/react';
import {
    flexRender,
    getCoreRowModel,
    getFilteredRowModel,
    getSortedRowModel,
    useReactTable,
    type Cell,
    type ColumnDef,
    type FilterFn,
    type Header,
    type Renderable,
    type Row,
    type RowSelectionState,
    type SortingState,
    type Updater,
    type VisibilityState,
} from '@tanstack/react-table';
import { useVirtualizer } from '@tanstack/react-virtual';
import { ArrowDownIcon, ArrowUpDownIcon, ArrowUpIcon, EllipsisIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type MouseEvent, type ReactNode } from 'react';

import KeadaanKosong from '@/Komponen/Katalog/KeadaanKosong';
import { Button } from '@/Komponen/Ui/button';
import { Checkbox } from '@/Komponen/Ui/checkbox';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/Komponen/Ui/dropdown-menu';
import { Skeleton } from '@/Komponen/Ui/skeleton';
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { cn } from '@/Komponen/Ui/utils';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';

import BilahAlat from './BilahAlat';
import { TulisKeadaanKeUrl } from './KeadaanUrl';
import PaginasiTabel from './PaginasiTabel';
import { usePreferensiKolom } from './PreferensiKolom';
import { PecahRentang } from './Saring';
import type { DefinisiSaring, HasilTabel, KeadaanTabel, KolomTabel, MetaTabel } from './Tipe';
import { AmbilMeta } from './Tipe';
import { useDataTabel } from './useDataTabel';
import { useKeadaanTabel } from './useKeadaanTabel';
import { useLebarLayar } from './useLebarLayar';

export type SumberTabel<T> = { mode: 'server'; alamat: string; awal?: HasilTabel<T> } | { mode: 'lokal'; data: T[] };

export type KonteksAksiMassal<T> = {
    terpilih: T[];
    /** Pengguna memilih seluruh hasil saring (bukan hanya halaman ini). Server memakai `keadaan` untuk menyaring ulang. */
    semuaHasil: boolean;
    total: number;
    keadaan: KeadaanTabel;
    bersihkan: () => void;
};

export type PropsTabelData<T> = {
    /** Kunci unik tabel: dipakai untuk cache kueri & pilihan kolom pengguna. */
    id: string;
    /** Nama tabel untuk pembaca layar, mis. "Daftar shift". */
    label: string;
    kolom: KolomTabel<T>[];
    sumber: SumberTabel<T>;
    ambilIdBaris: (baris: T) => string;
    urutBawaan?: string;
    /** Placeholder kotak cari; `false` = tanpa pencarian. */
    cari?: string | false;
    /**
     * Tombol pindai kamera di samping kotak cari (isi kode ke kotak cari). `true` = barcode barang; `'Seri'` = nomor
     * seri/IMEI (menambah QR, DataMatrix, Codabar).
     */
    pindaiKamera?: boolean | 'Seri';
    saring?: DefinisiSaring[];
    alamatDetail?: (baris: T) => string;
    /** Isi menu aksi baris (`DropdownMenuItem`); `null` bila baris ini tidak punya aksi yang boleh. */
    aksiBaris?: (baris: T) => ReactNode;
    /** Nama baris untuk tombol menu aksi yang dibacakan, mis. "Aksi Minuman" (bawaan "Aksi baris"). */
    labelBaris?: (baris: T) => string;
    aksiMassal?: (konteks: KonteksAksiMassal<T>) => ReactNode;
    ekspor?: { alamat: string; label?: string; query?: string; laporan?: boolean; eksporBesar?: boolean };
    /** `ilustrasi` (D-18): ilustrasi subjek untuk daftar utama yang belum berisi data. */
    kosong: { judul: string; aksi?: ReactNode; ilustrasi?: boolean };
    aksiAlat?: ReactNode;
    /** Ringkasan di atas tabel dari hasil server terbaru (ikut berubah saat saring berubah). */
    ringkasan?: (hasil: HasilTabel<T> | undefined) => ReactNode;
    /** Dipanggil setiap baris yang tampil berganti (mis. untuk isian yang bisa diedit lintas halaman). */
    saatData?: (baris: T[]) => void;
};

const BATAS_VIRTUAL = 100;
const TINGGI_BARIS = 44;
// Ketebalan (tebal) datang dari TableHead; di sini hanya ukuran, warna, dan jarak.
const kelasKepala = 'h-auto bg-permukaan px-3 py-2 text-label text-teks-sekunder';

/** Penyaring kolom mode lokal sesuai jenis definisi saring (nilai dari `saring[...]`). */
function BuatPenyaringLokal(saring: DefinisiSaring[]): FilterFn<unknown> {
    const peta = new Map(saring.map((d) => [d.id, d]));

    return (baris, idKolom, nilai: string) => {
        const definisi = peta.get(idKolom);
        const isi = baris.getValue<unknown>(idKolom);

        if (!definisi) {
            return String(isi) === nilai;
        }

        switch (definisi.jenis) {
            case 'ya':
                return nilai !== '1' || Boolean(isi);
            case 'rentangTanggal': {
                const [dari, sampai] = PecahRentang(nilai);
                const tanggal = String(isi ?? '').slice(0, 10);

                return (dari === '' || tanggal >= dari) && (sampai === '' || tanggal <= sampai);
            }
            default:
                return nilai.split(',').includes(String(isi));
        }
    };
}

function IkonUrut({ arah }: { arah: false | 'asc' | 'desc' }) {
    if (arah === 'asc') {
        return <ArrowUpIcon aria-hidden="true" className="size-4 text-teks-utama" />;
    }

    if (arah === 'desc') {
        return <ArrowDownIcon aria-hidden="true" className="size-4 text-teks-utama" />;
    }

    return <ArrowUpDownIcon aria-hidden="true" className="size-4 text-teks-sekunder" />;
}

function SelKepala<T>({
    header,
    jumlahUrut,
    menempel,
}: {
    header: Header<T, unknown>;
    jumlahUrut: number;
    menempel: boolean;
}) {
    const meta = AmbilMeta(header.column.columnDef.meta);
    const arah = header.column.getIsSorted();
    const isi = header.isPlaceholder ? null : TampilkanIsi(header.column.columnDef.header, header.getContext());
    const indeksUrut = header.column.getSortIndex();

    return (
        <TableHead
            scope="col"
            aria-sort={arah === 'asc' ? 'ascending' : arah === 'desc' ? 'descending' : undefined}
            style={header.column.getIsResizing() || header.getSize() !== 150 ? { width: header.getSize() } : undefined}
            className={cn(
                kelasKepala,
                meta?.angka && 'text-right',
                'relative',
                menempel && 'sticky left-0 z-[1]',
                header.column.id === 'aksi' && 'sticky right-0 z-[1]',
            )}
        >
            {header.column.getCanSort() ? (
                <button
                    type="button"
                    onClick={header.column.getToggleSortingHandler()}
                    className={cn(
                        'inline-flex min-h-8 items-center gap-1 rounded-kontrol text-left hover:text-teks-utama focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none',
                        meta?.angka && 'flex-row-reverse text-right',
                    )}
                    title="Klik untuk mengurutkan. Shift+klik untuk urut bertingkat."
                >
                    {isi}
                    <IkonUrut arah={arah} />
                    {arah !== false && jumlahUrut > 1 ? (
                        <span className="text-keterangan text-teks-sekunder tabular-nums">{indeksUrut + 1}</span>
                    ) : null}
                </button>
            ) : (
                isi
            )}
            {header.column.getCanResize() ? (
                <div
                    role="separator"
                    aria-orientation="vertical"
                    aria-label={`Ubah lebar kolom ${meta?.label ?? header.column.id}`}
                    onMouseDown={header.getResizeHandler()}
                    onTouchStart={header.getResizeHandler()}
                    className="absolute top-0 right-0 h-full w-1.5 cursor-col-resize select-none hover:bg-garis-input"
                />
            ) : null}
        </TableHead>
    );
}

/**
 * Pengganti `flexRender` untuk `cell`/`header`. `flexRender` memakai fungsi kolom sebagai tipe komponen React, jadi
 * halaman yang membuat ulang definisi kolom (misal kolom berisi isian yang bergantung pada nilai ketikan) me-remount
 * isi sel di setiap render dan isian kehilangan fokus setelah satu ketikan. Di sini fungsi itu dipanggil dari dalam
 * satu komponen yang tipenya tetap, sehingga elemen di dalam sel (dan fokusnya) dipertahankan.
 */
function IsiSel<P extends object>({ isi, konteks }: { isi: (konteks: P) => ReactNode; konteks: P }) {
    return <>{isi(konteks)}</>;
}

function TampilkanIsi<P extends object>(isi: Renderable<P> | undefined, konteks: P): ReactNode {
    const komponen = isi as unknown as { prototype?: { isReactComponent?: unknown } } | undefined;

    if (typeof isi === 'function' && !komponen?.prototype?.isReactComponent) {
        return <IsiSel isi={isi as (konteks: P) => ReactNode} konteks={konteks} />;
    }

    return flexRender(isi, konteks);
}

function SelData<T>({ cell, menempel }: { cell: Cell<T, unknown>; menempel: boolean }) {
    const meta = AmbilMeta(cell.column.columnDef.meta);

    return (
        <TableCell
            className={cn(
                'px-3 py-2 align-top whitespace-normal',
                meta?.angka && 'text-right whitespace-nowrap tabular-nums',
                cell.column.id === 'pilih' && 'w-10',
                // `bg-inherit`: sel menempel harus buram (isi yang tergulir tidak tembus) tetapi ikut warna barisnya, jadi baris
                // terpilih/disorot tidak terbelah dua warna.
                cell.column.id === 'aksi' && 'sticky right-0 w-12 bg-inherit',
                menempel && 'sticky left-0 z-[1] bg-inherit',
                meta?.kelasSel,
            )}
        >
            {TampilkanIsi(cell.column.columnDef.cell, cell.getContext())}
        </TableCell>
    );
}

/** Klik baris membuka detail, kecuali klik pada elemen interaktif di dalamnya. */
function TanganiKlikBaris(peristiwa: MouseEvent<HTMLElement>, alamat: string | undefined) {
    if (!alamat) {
        return;
    }

    const target = peristiwa.target as HTMLElement;

    if (target.closest('a,button,input,label,select,textarea,[role="checkbox"],[role="menuitem"]')) {
        return;
    }

    router.visit(alamat);
}

/**
 * Baris HP: kolom utama sebagai judul, kolom penting sebagai pasangan label–nilai yang mengalir sebaris (§17.4.4,
 * D-228). Dulu tiap pasangan satu baris, jadi satu produk memakan hampir satu layar.
 */
function BarisBertumpuk<T>({ row, alamatDetail }: { row: Row<T>; alamatDetail: ((baris: T) => string) | undefined }) {
    const sel = row.getVisibleCells();
    const utama =
        sel.find((c) => AmbilMeta(c.column.columnDef.meta)?.prioritas === 'utama') ??
        sel.find((c) => c.column.id !== 'pilih');
    const pilih = sel.find((c) => c.column.id === 'pilih');
    const aksi = sel.find((c) => c.column.id === 'aksi');
    const penting = sel.filter(
        (c) =>
            c !== utama &&
            AmbilMeta(c.column.columnDef.meta)?.prioritas === 'penting' &&
            AmbilMeta(c.column.columnDef.meta)?.label &&
            !(AmbilMeta(c.column.columnDef.meta)?.sembunyiBilaKosong && c.getValue() == null),
    );
    const alamat = alamatDetail?.(row.original);

    return (
        <li
            className={cn(
                'flex gap-3 border-b border-garis px-4 py-3 last:border-b-0',
                alamat && 'cursor-pointer hover:bg-permukaan-redup',
                row.getIsSelected() && 'bg-brand-lembut hover:bg-brand-lembut-sorot',
            )}
            onClick={(e) => TanganiKlikBaris(e, alamat)}
        >
            {pilih ? (
                <div className="pt-0.5">{TampilkanIsi(pilih.column.columnDef.cell, pilih.getContext())}</div>
            ) : null}
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <div className="text-isi font-semibold text-teks-utama">
                    {utama ? TampilkanIsi(utama.column.columnDef.cell, utama.getContext()) : null}
                </div>
                {penting.length > 0 ? (
                    <dl className="flex flex-wrap gap-x-3 gap-y-0.5 text-label">
                        {penting.map((c) => (
                            <div key={c.id} className="flex min-w-0 gap-1">
                                <dt className="text-teks-sekunder">{AmbilMeta(c.column.columnDef.meta)?.label}</dt>
                                <dd
                                    className={cn(
                                        'min-w-0 text-teks-utama',
                                        AmbilMeta(c.column.columnDef.meta)?.angka && 'tabular-nums',
                                    )}
                                >
                                    {TampilkanIsi(c.column.columnDef.cell, c.getContext())}
                                </dd>
                            </div>
                        ))}
                    </dl>
                ) : null}
            </div>
            {aksi ? <div>{TampilkanIsi(aksi.column.columnDef.cell, aksi.getContext())}</div> : null}
        </li>
    );
}

/**
 * Tabel data standar back-office & Platform Pengelola (PRD §17.4.3, §17.4.4, D-16): TanStack Table untuk logika,
 * TanStack Query untuk data server. Cari, saring, urut (bertingkat dengan Shift), atur kolom, pilih baris & aksi
 * massal, aksi baris, ekspor, paginasi server, keadaan di URL, keadaan memuat/kosong/galat, dan tampilan bertumpuk di HP.
 */
export default function TabelData<T>(props: PropsTabelData<T>) {
    const { sumber, saring = [], cari = false } = props;
    const server = sumber.mode === 'server';
    const lebar = useLebarLayar();
    const keadaanTabel = useKeadaanTabel(props.urutBawaan ?? '', server);
    const { keadaan } = keadaanTabel;
    const kueri = useDataTabel<T>(
        props.id,
        server ? sumber.alamat : '',
        keadaan,
        keadaanTabel.urutBawaan,
        server ? sumber.awal : undefined,
        server,
    );
    const { preferensi, AturTampil, AturUrutan, Kembalikan } = usePreferensiKolom(props.id);
    const [pilihan, AturPilihan] = useState<RowSelectionState>({});
    const [semuaHasil, AturSemuaHasil] = useState(false);
    const wadah = useRef<HTMLDivElement>(null);
    const adaAksiMassal = Boolean(props.aksiMassal);

    const data = useMemo<T[]>(
        () => (sumber.mode === 'lokal' ? sumber.data : (kueri.data?.Data ?? [])),
        [sumber, kueri.data],
    );

    const { saatData: SaatData } = props;
    useEffect(() => {
        SaatData?.(data);
    }, [data, SaatData]);

    const kolom = useMemo<ColumnDef<T, unknown>[]>(() => {
        const hasil: ColumnDef<T, unknown>[] = [];

        if (adaAksiMassal) {
            hasil.push({
                id: 'pilih',
                enableSorting: false,
                enableResizing: false,
                meta: { label: '', wajib: true },
                header: ({ table }) => (
                    <Checkbox
                        checked={
                            table.getIsAllPageRowsSelected()
                                ? true
                                : table.getIsSomePageRowsSelected()
                                  ? 'indeterminate'
                                  : false
                        }
                        onCheckedChange={(aktif) => {
                            table.toggleAllPageRowsSelected(aktif === true);
                            AturSemuaHasil(false);
                        }}
                        aria-label="Pilih semua baris di halaman ini"
                        className="size-5"
                    />
                ),
                cell: ({ row }) => (
                    <Checkbox
                        checked={row.getIsSelected()}
                        onCheckedChange={(aktif) => {
                            row.toggleSelected(aktif === true);
                            AturSemuaHasil(false);
                        }}
                        aria-label="Pilih baris"
                        className="size-5"
                    />
                ),
            });
        }

        hasil.push(...props.kolom);

        if (props.aksiBaris) {
            const AksiBaris = props.aksiBaris;
            const LabelBaris = props.labelBaris;
            hasil.push({
                id: 'aksi',
                enableSorting: false,
                enableResizing: false,
                meta: { label: '', wajib: true },
                header: () => <span className="sr-only">Aksi</span>,
                cell: ({ row }) => {
                    const isi = AksiBaris(row.original);

                    // `null` = tidak ada aksi yang boleh untuk baris ini: tombol menu tidak ditampilkan.
                    if (isi === null) {
                        return null;
                    }

                    return (
                        // Non-modal: dialog yang dibuka dari menu tidak berebut fokus & pointer-events dengan menu.
                        <DropdownMenu modal={false}>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    className="size-11 sm:size-8"
                                    aria-label={LabelBaris ? `Aksi ${LabelBaris(row.original)}` : 'Aksi baris'}
                                >
                                    <EllipsisIcon aria-hidden="true" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="min-w-44">
                                {isi}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    );
                },
            });
        }

        return hasil;
    }, [props.kolom, props.aksiBaris, props.labelBaris, adaAksiMassal]);

    const visibilitas = useMemo<VisibilityState>(() => {
        const hasil: VisibilityState = {};

        for (const satu of kolom) {
            const id = satu.id ?? ('accessorKey' in satu ? String(satu.accessorKey) : '');
            const meta = AmbilMeta(satu.meta);

            if (meta?.wajib) {
                hasil[id] = true;
            } else if (preferensi.Sembunyi.includes(id)) {
                hasil[id] = false;
            } else if (preferensi.Tampil.includes(id)) {
                hasil[id] = true;
            } else {
                hasil[id] = !(lebar !== 'desktop' && meta?.prioritas === 'rendah');
            }
        }

        return hasil;
    }, [kolom, preferensi, lebar]);

    const urutanKolom = useMemo(() => {
        const semua = kolom.map((k) => k.id ?? ('accessorKey' in k ? String(k.accessorKey) : ''));
        const tengah = semua.filter((id) => id !== 'pilih' && id !== 'aksi');
        const diatur = preferensi.Urutan.filter((id) => tengah.includes(id));
        const sisa = tengah.filter((id) => !diatur.includes(id));

        return [
            ...(semua.includes('pilih') ? ['pilih'] : []),
            ...diatur,
            ...sisa,
            ...(semua.includes('aksi') ? ['aksi'] : []),
        ];
    }, [kolom, preferensi.Urutan]);

    const urut: SortingState = keadaan.urut;
    // Harus stabil antar-render: TanStack menghitung ulang baris tersaring bila referensinya berubah, dan tiap
    // hitung ulang menjadwalkan reset halaman → render baru → array baru → putaran tanpa akhir (membekukan peramban).
    // Kunci teks (bukan array `kolom`) supaya prop `kolom` yang ditulis inline tidak ikut memecah memo.
    const kunciIdKolom = kolom.map((k) => k.id ?? ('accessorKey' in k ? String(k.accessorKey) : '')).join('|');
    const saringKolom = useMemo(() => {
        if (server) {
            return [];
        }

        const idKolom = kunciIdKolom.split('|');

        return Object.entries(keadaan.saring)
            .filter(([id]) => idKolom.includes(id))
            .map(([id, value]) => ({ id, value }));
    }, [server, keadaan.saring, kunciIdKolom]);
    const PenyaringLokal = useMemo(() => BuatPenyaringLokal(saring), [saring]);

    // TanStack Table (pustaka wajib D-16) mengembalikan fungsi yang tidak bisa di-memo React Compiler; compiler
    // melewati komponen ini dan itu memang yang diinginkan (tabel dirender ulang setiap keadaan berubah).
    // eslint-disable-next-line react-hooks/incompatible-library
    const tabel = useReactTable<T>({
        data,
        columns: kolom,
        getRowId: (baris) => props.ambilIdBaris(baris),
        getCoreRowModel: getCoreRowModel(),
        ...(server
            ? { manualSorting: true, manualFiltering: true, manualPagination: true }
            : { getSortedRowModel: getSortedRowModel(), getFilteredRowModel: getFilteredRowModel() }),
        defaultColumn: { filterFn: PenyaringLokal as FilterFn<T> },
        globalFilterFn: 'includesString',
        // Paginasi dikelola sendiri (server/`keadaan`), bukan oleh TanStack: jangan reset otomatis.
        autoResetPageIndex: false,
        autoResetExpanded: false,
        enableMultiSort: true,
        maxMultiSortColCount: 3,
        enableColumnResizing: lebar === 'desktop',
        columnResizeMode: 'onChange',
        enableRowSelection: Boolean(props.aksiMassal),
        state: {
            sorting: urut,
            columnVisibility: visibilitas,
            columnOrder: urutanKolom,
            rowSelection: pilihan,
            globalFilter: server ? undefined : keadaan.cari,
            columnFilters: saringKolom,
        },
        onSortingChange: (pembaru: Updater<SortingState>) =>
            keadaanTabel.AturUrut(typeof pembaru === 'function' ? pembaru(urut) : pembaru),
        onRowSelectionChange: AturPilihan,
    });

    const baris = tabel.getRowModel().rows;
    const meta: MetaTabel | undefined = server ? kueri.data?.Meta : undefined;
    const total = meta?.Total ?? baris.length;
    const terpilih = tabel.getSelectedRowModel().rows.map((r) => r.original);
    const adaSaring = keadaan.cari.trim() !== '' || Object.keys(keadaan.saring).length > 0;
    const BersihkanPilihan = () => {
        AturPilihan({});
        AturSemuaHasil(false);
    };

    const virtual = !server && baris.length > BATAS_VIRTUAL && lebar !== 'hp';
    const penggulir = useVirtualizer({
        count: virtual ? baris.length : 0,
        getScrollElement: () => wadah.current,
        estimateSize: () => TINGGI_BARIS,
        overscan: 10,
    });

    const memuatPertama = server && kueri.isPending;
    const galat = server && kueri.isError;
    const jumlahUrut = urut.length;
    // Kolom identitas (pertama setelah kotak pilih) menempel saat tabel digulir horizontal.
    const indeksMenempel = props.aksiMassal ? 1 : 0;

    const bilahAlat = (
        <BilahAlat
            label={props.label}
            tabel={tabel}
            lebar={lebar}
            keadaan={keadaan}
            teksCari={keadaanTabel.teksCari}
            cari={cari}
            pindaiKamera={props.pindaiKamera ?? false}
            saring={saring}
            {...(props.ekspor
                ? {
                      ekspor: {
                          ...props.ekspor,
                          query: TulisKeadaanKeUrl(
                              keadaan,
                              keadaanTabel.urutBawaan,
                              props.ekspor.query ?? (server ? window.location.search : ''),
                          ),
                      },
                  }
                : {})}
            aksiAlat={props.aksiAlat}
            AturCari={keadaanTabel.AturCari}
            AturSaring={(id, nilai) => {
                keadaanTabel.AturSaring(id, nilai);
                BersihkanPilihan();
            }}
            AturUrut={keadaanTabel.AturUrut}
            HapusSemua={() => {
                keadaanTabel.HapusSemua();
                BersihkanPilihan();
            }}
            AturTampilKolom={AturTampil}
            AturUrutanKolom={AturUrutan}
            KembalikanKolom={Kembalikan}
        />
    );

    let isi: ReactNode;
    // Tanpa bingkai: sudah berada di dalam panel tabel (hindari kotak di dalam kotak).
    const keadaanKosong = adaSaring ? (
        <KeadaanKosong judul="Tidak ada hasil untuk pencarian atau filter ini." bingkai={false}>
            <Button type="button" variant="link" className="h-auto px-0" onClick={keadaanTabel.HapusSemua}>
                Hapus pencarian & filter
            </Button>
        </KeadaanKosong>
    ) : (
        <KeadaanKosong judul={props.kosong.judul} ilustrasi={props.kosong.ilustrasi} bingkai={false}>
            {props.kosong.aksi}
        </KeadaanKosong>
    );

    if (memuatPertama) {
        isi = (
            <div aria-hidden="true" data-testid="kerangka-tabel" className="flex flex-col gap-2 p-4">
                {[0, 1, 2, 3, 4].map((i) => (
                    <Skeleton key={i} className="h-9 rounded-kontrol" />
                ))}
            </div>
        );
    } else if (galat && data.length === 0) {
        isi = (
            <div className="p-4">
                <Pemberitahuan jenis="bahaya" judul="Data belum bisa dimuat">
                    Periksa koneksi internet Anda, lalu coba lagi.{' '}
                    <Button type="button" variant="link" className="h-auto px-0" onClick={() => void kueri.refetch()}>
                        Coba lagi
                    </Button>
                </Pemberitahuan>
            </div>
        );
    } else if (baris.length === 0 && lebar === 'hp') {
        // HP: tanpa kepala kolom (daftar bertumpuk), cukup keadaan kosongnya.
        isi = <div role="status">{keadaanKosong}</div>;
    } else if (lebar === 'hp') {
        isi = (
            <ul aria-label={props.label} className="flex flex-col">
                {baris.map((row) => (
                    <BarisBertumpuk key={row.id} row={row} alamatDetail={props.alamatDetail} />
                ))}
            </ul>
        );
    } else {
        const barisTampil = virtual ? penggulir.getVirtualItems().map((v) => baris[v.index]) : baris;
        const atas = virtual ? (penggulir.getVirtualItems()[0]?.start ?? 0) : 0;
        const bawah = virtual ? penggulir.getTotalSize() - (penggulir.getVirtualItems().at(-1)?.end ?? 0) : 0;

        isi = (
            <div
                ref={wadah}
                className={cn('relative w-full overflow-x-auto', virtual && 'max-h-[70vh] overflow-y-auto')}
            >
                <table
                    data-slot="table"
                    aria-label={props.label}
                    aria-rowcount={total}
                    className="w-full caption-bottom text-left text-isi"
                    style={
                        lebar === 'desktop' && Object.keys(tabel.getState().columnSizing).length > 0
                            ? { width: tabel.getTotalSize() }
                            : undefined
                    }
                >
                    <TableHeader className={cn(virtual && 'sticky top-0 z-[2]')}>
                        {tabel.getHeaderGroups().map((grup) => (
                            <TableRow key={grup.id} className="border-garis hover:bg-transparent">
                                {grup.headers.map((header, indeks) => (
                                    <SelKepala
                                        key={header.id}
                                        header={header}
                                        jumlahUrut={jumlahUrut}
                                        menempel={indeks === indeksMenempel}
                                    />
                                ))}
                            </TableRow>
                        ))}
                    </TableHeader>
                    <TableBody>
                        {atas > 0 ? (
                            <tr aria-hidden="true">
                                <td style={{ height: atas }} />
                            </tr>
                        ) : null}
                        {barisTampil.map((row) =>
                            row ? (
                                <TableRow
                                    key={row.id}
                                    data-state={row.getIsSelected() ? 'selected' : undefined}
                                    className={cn(
                                        'border-garis bg-permukaan hover:bg-permukaan-redup',
                                        'data-[state=selected]:border-brand-lembut-sorot data-[state=selected]:bg-brand-lembut data-[state=selected]:hover:bg-brand-lembut-sorot',
                                        props.alamatDetail && 'cursor-pointer',
                                    )}
                                    onClick={(e) => TanganiKlikBaris(e, props.alamatDetail?.(row.original))}
                                >
                                    {row.getVisibleCells().map((cell, indeks) => (
                                        <SelData key={cell.id} cell={cell} menempel={indeks === indeksMenempel} />
                                    ))}
                                </TableRow>
                            ) : null,
                        )}
                        {bawah > 0 ? (
                            <tr aria-hidden="true">
                                <td style={{ height: bawah }} />
                            </tr>
                        ) : null}
                        {baris.length === 0 ? (
                            // Kepala kolom tetap tampil; keadaan kosong satu baris selebar tabel. Diumumkan pembaca
                            // layar: penting setelah mencari/menyaring tanpa hasil.
                            <TableRow className="border-garis hover:bg-transparent">
                                <td role="status" colSpan={tabel.getVisibleLeafColumns().length}>
                                    {keadaanKosong}
                                </td>
                            </TableRow>
                        ) : null}
                    </TableBody>
                </table>
            </div>
        );
    }

    return (
        <section className="flex min-w-0 flex-col gap-3" aria-label={props.label}>
            {props.ringkasan ? props.ringkasan(server ? kueri.data : undefined) : null}
            {bilahAlat}
            {terpilih.length > 0 && props.aksiMassal ? (
                <div
                    role="region"
                    aria-label="Aksi untuk baris terpilih"
                    className="sticky bottom-0 z-10 flex flex-wrap items-center gap-2 rounded-panel border border-garis bg-brand-lembut px-3 py-2 tepi-bawah-aman sm:static"
                >
                    <span className="text-label font-semibold text-teks-utama">
                        {semuaHasil ? `Semua ${String(total)} hasil dipilih` : `${String(terpilih.length)} dipilih`}
                    </span>
                    {server && !semuaHasil && tabel.getIsAllPageRowsSelected() && total > terpilih.length ? (
                        <Button
                            type="button"
                            variant="link"
                            className="h-auto px-0 text-label"
                            onClick={() => AturSemuaHasil(true)}
                        >
                            Pilih semua {total} hasil
                        </Button>
                    ) : null}
                    <div className="flex flex-wrap items-center gap-2 sm:ml-auto">
                        {props.aksiMassal({ terpilih, semuaHasil, total, keadaan, bersihkan: BersihkanPilihan })}
                        <Button
                            type="button"
                            variant="ghost"
                            className="h-8 pointer-coarse:h-11 text-label"
                            onClick={BersihkanPilihan}
                        >
                            Batal pilih
                        </Button>
                    </div>
                </div>
            ) : null}
            {galat && data.length > 0 ? (
                <Pemberitahuan jenis="peringatan" judul="Data terbaru belum bisa dimuat">
                    Yang tampil adalah data sebelumnya.{' '}
                    <Button type="button" variant="link" className="h-auto px-0" onClick={() => void kueri.refetch()}>
                        Coba lagi
                    </Button>
                </Pemberitahuan>
            ) : null}
            <div
                aria-busy={server && kueri.isFetching}
                className="relative overflow-hidden rounded-panel border border-garis bg-permukaan"
            >
                {server && kueri.isFetching && !memuatPertama ? (
                    <div
                        role="progressbar"
                        aria-label="Memuat data"
                        className="absolute inset-x-0 top-0 z-[3] h-0.5 animate-pulse bg-brand"
                    />
                ) : null}
                {isi}
            </div>
            {meta && baris.length > 0 ? (
                <PaginasiTabel
                    label={props.label}
                    meta={meta}
                    jumlahBaris={baris.length}
                    AturHalaman={(halaman) => {
                        keadaanTabel.AturHalaman(halaman);
                        BersihkanPilihan();
                    }}
                    AturPerHalaman={(ukuran) => {
                        keadaanTabel.AturPerHalaman(ukuran);
                        BersihkanPilihan();
                    }}
                />
            ) : !server && baris.length > 0 ? (
                <p className="text-label text-teks-sekunder" aria-live="polite">
                    {baris.length} baris
                </p>
            ) : null}
        </section>
    );
}
