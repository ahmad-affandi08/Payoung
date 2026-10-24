import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';

import { cn } from '@/Komponen/Ui/utils';

/**
 * Satu gaya tab untuk seluruh web (gaya Majoo): teks besar `text-subjudul`, tab aktif berwarna brand dengan batang
 * tebal membulat di bawah teksnya. Dipakai oleh tab tautan (`TabTautan`) dan tab panel Radix
 * (`kelasDaftarTabPanel`/`kelasItemTabPanel`) supaya semua tab tampil sama. Satu baris yang hanya bisa digeser
 * menyamping (gulir vertikal dikunci) di dalam `JalurGulirTab`, yang menambah tombol panah di tepi saat ada tab lain.
 */
export const kelasDaftarTab =
    'relative flex w-full justify-start gap-1 overflow-x-auto overflow-y-hidden border-b border-garis [scrollbar-width:none] [&::-webkit-scrollbar]:hidden';

const kelasItemTab =
    'relative inline-flex shrink-0 items-center gap-1 px-4 py-3 text-subjudul font-semibold whitespace-nowrap outline-none transition-colors focus-visible:ring-2 focus-visible:ring-brand/40 pointer-coarse:min-h-12 before:pointer-events-none before:absolute before:inset-x-3 before:bottom-0 before:h-[3px] before:rounded-full before:bg-transparent before:transition-colors';

export function KelasItemTab(aktif: boolean): string {
    return cn(kelasItemTab, aktif ? 'text-brand before:bg-brand' : 'text-teks-sekunder hover:text-teks-utama');
}

/** Untuk `TabsList` shadcn (tab panel di satu halaman). */
export const kelasDaftarTabPanel = cn(
    kelasDaftarTab,
    'h-auto rounded-none bg-transparent p-0 group-data-[orientation=horizontal]/tabs:h-auto',
);

/** Untuk `TabsTrigger` shadcn: keadaan aktif dari `data-state`. */
export const kelasItemTabPanel = cn(
    kelasItemTab,
    'h-auto flex-none rounded-none border-0 bg-transparent after:hidden text-teks-sekunder shadow-none hover:text-teks-utama data-[state=active]:bg-transparent data-[state=active]:text-brand data-[state=active]:shadow-none data-[state=active]:before:bg-brand',
);

const kelasPanah =
    'absolute top-1/2 z-10 grid size-9 -translate-y-1/2 place-items-center rounded-full border border-garis bg-permukaan text-brand shadow-sm transition-opacity hover:bg-brand-lembut';

/**
 * Membungkus bilah tab (anak pertamanya yang bergulir menyamping): menampilkan panah kiri/kanan bila masih ada tab
 * di luar layar, dan menggeser tab aktif ke tengah saat berganti. Panah hanya bantuan sentuh/mouse; papan ketik tetap
 * memakai panah/Tab pada tabnya sendiri, jadi panah disembunyikan dari pembaca layar.
 */
export function JalurGulirTab({ children, className }: { children: ReactNode; className?: string | undefined }) {
    const pembungkus = useRef<HTMLDivElement>(null);
    const terakhirDipusatkan = useRef<Element | null>(null);
    const [adaKiri, AturAdaKiri] = useState(false);
    const [adaKanan, AturAdaKanan] = useState(false);

    const AmbilBilah = useCallback(() => pembungkus.current?.firstElementChild as HTMLElement | null, []);

    const PerbaruiPanah = useCallback(() => {
        const bilah = AmbilBilah();

        if (!bilah) {
            return;
        }

        AturAdaKiri(bilah.scrollLeft > 4);
        AturAdaKanan(bilah.scrollLeft + bilah.clientWidth < bilah.scrollWidth - 4);
    }, [AmbilBilah]);

    // Tab aktif digeser ke tengah hanya saat tab aktifnya berganti (bukan setiap render), lalu panah diperbarui.
    useEffect(() => {
        const bilah = AmbilBilah();
        const aktif = bilah?.querySelector<HTMLElement>('[aria-current="page"], [data-state="active"]') ?? null;

        if (bilah && aktif && aktif !== terakhirDipusatkan.current) {
            terakhirDipusatkan.current = aktif;
            bilah.scrollLeft = aktif.offsetLeft - (bilah.clientWidth - aktif.offsetWidth) / 2;
        }

        // Ditunda satu bingkai: ukuran tab baru terbaca setelah tata letak selesai (dan setState bukan di badan efek).
        const bingkai = requestAnimationFrame(PerbaruiPanah);

        return () => cancelAnimationFrame(bingkai);
    });

    useEffect(() => {
        const bilah = AmbilBilah();

        if (!bilah) {
            return;
        }

        bilah.addEventListener('scroll', PerbaruiPanah, { passive: true });
        const pengamat = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(PerbaruiPanah);
        pengamat?.observe(bilah);

        return () => {
            bilah.removeEventListener('scroll', PerbaruiPanah);
            pengamat?.disconnect();
        };
    }, [AmbilBilah, PerbaruiPanah]);

    const Geser = (arah: -1 | 1) => {
        const bilah = AmbilBilah();

        bilah?.scrollBy?.({ left: arah * bilah.clientWidth * 0.6, behavior: 'smooth' });
    };

    return (
        <div ref={pembungkus} className={cn('relative', className)}>
            {children}
            <button
                type="button"
                tabIndex={-1}
                aria-hidden="true"
                data-panah="kiri"
                onClick={() => Geser(-1)}
                className={cn(kelasPanah, 'left-0', adaKiri ? 'opacity-100' : 'pointer-events-none opacity-0')}
            >
                <ChevronLeft className="size-5" aria-hidden="true" />
            </button>
            <button
                type="button"
                tabIndex={-1}
                aria-hidden="true"
                data-panah="kanan"
                onClick={() => Geser(1)}
                className={cn(kelasPanah, 'right-0', adaKanan ? 'opacity-100' : 'pointer-events-none opacity-0')}
            >
                <ChevronRight className="size-5" aria-hidden="true" />
            </button>
        </div>
    );
}

export type ItemTabTautan = { label: string; href: string; aktif: boolean };

type PropsTabTautan = {
    label: string;
    tab: ItemTabTautan[];
    /** Pertahankan posisi gulir saat pindah tab (misal tab laporan dengan saring yang sama). */
    pertahankanGulir?: boolean;
    className?: string;
};

/** Tab navigasi antar halaman (tautan Inertia); tab aktif ditandai `aria-current="page"`, teks brand, dan batang bawah. */
export default function TabTautan({ label, tab, pertahankanGulir = false, className }: PropsTabTautan) {
    return (
        <JalurGulirTab className={className}>
            <nav aria-label={label} className={kelasDaftarTab}>
                {tab.map((item) => (
                    <Link
                        key={item.href}
                        href={item.href}
                        preserveScroll={pertahankanGulir}
                        aria-current={item.aktif ? 'page' : undefined}
                        className={KelasItemTab(item.aktif)}
                    >
                        {item.label}
                    </Link>
                ))}
            </nav>
        </JalurGulirTab>
    );
}
