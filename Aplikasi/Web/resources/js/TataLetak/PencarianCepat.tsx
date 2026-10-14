import { router } from '@inertiajs/react';
import { useQueries } from '@tanstack/react-query';
import { ArrowRightIcon, SearchIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/Komponen/Ui/button';
import IkonNavigasi, { type NamaIkonNavigasi } from '@/Komponen/Navigasi/IkonNavigasi';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/Komponen/Ui/command';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Komponen/Ui/dialog';
import { Kbd, KbdGroup } from '@/Komponen/Ui/kbd';
import { KunciKueri } from '@/Pustaka/KunciKueri';

/** Halaman yang bisa dibuka dari pencarian cepat (diturunkan dari menu yang boleh dilihat, bukan daftar terpisah). */
export type HalamanPencarian = {
    label: string;
    href: string;
    grup: string | null;
    ikon?: NamaIkonNavigasi | undefined;
};

export type HasilPencarian = { judul: string; keterangan: string | null; href: string };

/**
 * Sumber data yang dicari langsung ke server lewat endpoint JSON `TabelData` yang sudah ada (`?cari=`), sehingga
 * izin & isolasi tenant tetap ditegakkan server. `alamat` sekaligus kunci izin: sumber hanya aktif bila halaman
 * daftarnya ada di menu pengguna.
 */
export type SumberPencarian = {
    id: string;
    label: string;
    alamat: string;
    ikon: NamaIkonNavigasi;
    AmbilHasil: (baris: Record<string, unknown>) => HasilPencarian;
};

type PropsPencarianCepat = { halaman: HalamanPencarian[]; sumber: SumberPencarian[] };

const batasHasilPerSumber = 5;
const panjangMinimalKata = 2;

/** Cocokkan tanpa membedakan huruf besar & spasi berlebih: setiap kata harus ada di label atau grup. */
export function CocokkanHalaman(halaman: HalamanPencarian, kata: string): boolean {
    const teks = `${halaman.grup ?? ''} ${halaman.label}`.toLowerCase();

    return kata
        .toLowerCase()
        .split(/\s+/)
        .filter(Boolean)
        .every((potongan) => teks.includes(potongan));
}

function useKataTertunda(kata: string, jedaMs: number): string {
    const [tertunda, AturTertunda] = useState(kata);

    useEffect(() => {
        const penunda = window.setTimeout(() => AturTertunda(kata), jedaMs);

        return () => window.clearTimeout(penunda);
    }, [kata, jedaMs]);

    return tertunda;
}

async function AmbilHasilSumber(sumber: SumberPencarian, kata: string, sinyal: AbortSignal): Promise<HasilPencarian[]> {
    const respons = await fetch(`${sumber.alamat}?${new URLSearchParams({ cari: kata }).toString()}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal: sinyal,
    });

    if (!respons.ok) {
        throw new Error(`Pencarian ${sumber.label} gagal (${String(respons.status)}).`);
    }

    const isi = (await respons.json()) as { Data?: Record<string, unknown>[] };

    return (isi.Data ?? []).slice(0, batasHasilPerSumber).map(sumber.AmbilHasil);
}

/**
 * Pencarian cepat di kepala halaman (shadcn Command dalam Dialog): buka dengan tombol "Cari" atau Ctrl/⌘ K.
 * Halaman disaring di klien dari menu yang boleh dilihat; data (produk, pelanggan, …) dicari ke server dengan jeda
 * 300 ms mulai 2 huruf. Pilih hasil = pindah halaman lewat Inertia.
 */
export default function PencarianCepat({ halaman, sumber }: PropsPencarianCepat) {
    const [terbuka, AturTerbuka] = useState(false);

    useEffect(() => {
        const TanganiTombol = (peristiwa: KeyboardEvent) => {
            if ((peristiwa.ctrlKey || peristiwa.metaKey) && peristiwa.key.toLowerCase() === 'k') {
                peristiwa.preventDefault();
                AturTerbuka((sebelumnya) => !sebelumnya);
            }
        };

        window.addEventListener('keydown', TanganiTombol);

        return () => window.removeEventListener('keydown', TanganiTombol);
    }, []);

    const Buka = (href: string) => {
        AturTerbuka(false);
        router.visit(href);
    };

    return (
        <>
            {/* Di HP hanya ikon, seukuran kotak logo usaha di kiri (size-8); area sentuh tetap 44px lewat `after:`. */}
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => AturTerbuka(true)}
                aria-label="Pencarian cepat"
                data-tur="pencarian-cepat"
                aria-keyshortcuts="Control+K Meta+K"
                className="relative mr-1 size-8 min-w-0 gap-2 rounded-lg border-garis bg-permukaan px-0 text-label font-normal text-teks-sekunder after:absolute after:-inset-1.5 has-[>svg]:px-0 lg:w-72 lg:justify-start lg:rounded-md lg:border-garis-input lg:px-3 lg:after:hidden lg:has-[>svg]:px-3 lg:pointer-coarse:h-11"
            >
                <SearchIcon aria-hidden="true" className="shrink-0" />
                <span className="hidden min-w-0 flex-1 truncate text-left lg:inline">Cari halaman atau data…</span>
                <Kbd aria-hidden="true" className="hidden shrink-0 lg:inline-flex">
                    Ctrl K
                </Kbd>
            </Button>
            <Dialog open={terbuka} onOpenChange={AturTerbuka}>
                <DialogContent
                    className="top-[15%] translate-y-0 overflow-hidden p-0 sm:max-w-xl"
                    showCloseButton={false}
                >
                    <DialogHeader className="sr-only">
                        <DialogTitle>Pencarian cepat</DialogTitle>
                        <DialogDescription>
                            Cari halaman, produk, pelanggan, pemasok, atau nomor penjualan.
                        </DialogDescription>
                    </DialogHeader>
                    {/* Isi (kata & kueri) hanya terpasang selama dialog terbuka: kata kosong lagi saat dibuka ulang. */}
                    <IsiPencarianCepat halaman={halaman} sumber={sumber} Buka={Buka} />
                </DialogContent>
            </Dialog>
        </>
    );
}

type PropsIsiPencarianCepat = PropsPencarianCepat & { Buka: (href: string) => void };

function IsiPencarianCepat({ halaman, sumber, Buka }: PropsIsiPencarianCepat) {
    const [kata, AturKata] = useState('');
    const kataTertunda = useKataTertunda(kata.trim(), 300);
    const cariData = kataTertunda.length >= panjangMinimalKata;

    const hasilData = useQueries({
        queries: sumber.map((s) => ({
            queryKey: KunciKueri.PencarianCepat(s.id, kataTertunda),
            queryFn: ({ signal }: { signal: AbortSignal }) => AmbilHasilSumber(s, kataTertunda, signal),
            enabled: cariData,
            staleTime: 30_000,
            retry: false,
        })),
    });

    const halamanCocok = kata.trim() === '' ? halaman : halaman.filter((h) => CocokkanHalaman(h, kata.trim()));
    const sedangMencari = cariData && hasilData.some((h) => h.isFetching);
    const adaHasilData = cariData && hasilData.some((h) => (h.data?.length ?? 0) > 0);

    return (
        <>
            {/* Hasil server sudah disaring server: penyaringan bawaan cmdk dimatikan, halaman disaring sendiri. */}
            <Command shouldFilter={false} className="bg-permukaan">
                <CommandInput
                    value={kata}
                    onValueChange={AturKata}
                    placeholder="Ketik nama halaman, produk, pelanggan, atau nomor…"
                    aria-label="Kata pencarian"
                    className="h-12 text-isi"
                />
                <CommandList className="max-h-[60vh]">
                    {halamanCocok.length === 0 && !adaHasilData && !sedangMencari ? (
                        <CommandEmpty className="py-8 text-center text-isi text-teks-sekunder">
                            {kata.trim().length > 0 && kata.trim().length < panjangMinimalKata
                                ? 'Ketik minimal 2 huruf untuk mencari data.'
                                : `Tidak ada hasil untuk "${kata.trim()}".`}
                        </CommandEmpty>
                    ) : null}
                    {halamanCocok.length > 0 ? (
                        <CommandGroup heading="Halaman">
                            {halamanCocok.map((h) => {
                                return (
                                    <CommandItem
                                        key={h.href}
                                        value={`halaman ${h.href}`}
                                        onSelect={() => Buka(h.href)}
                                        className="gap-2 text-isi"
                                    >
                                        {h.ikon ? (
                                            <IkonNavigasi nama={h.ikon} />
                                        ) : (
                                            <ArrowRightIcon aria-hidden="true" className="text-teks-sekunder" />
                                        )}
                                        <span className="truncate text-teks-utama">{h.label}</span>
                                        {h.grup ? (
                                            <span className="ml-auto truncate text-keterangan text-teks-sekunder">
                                                {h.grup}
                                            </span>
                                        ) : null}
                                    </CommandItem>
                                );
                            })}
                        </CommandGroup>
                    ) : null}
                    {sumber.map((s, indeks) => {
                        const daftar = cariData ? (hasilData[indeks]?.data ?? []) : [];
                        if (daftar.length === 0) {
                            return null;
                        }

                        return (
                            <div key={s.id}>
                                <CommandSeparator />
                                <CommandGroup heading={s.label}>
                                    {daftar.map((hasil) => (
                                        <CommandItem
                                            key={`${s.id}-${hasil.href}`}
                                            value={`${s.id} ${hasil.href}`}
                                            onSelect={() => Buka(hasil.href)}
                                            className="gap-2 text-isi"
                                        >
                                            <IkonNavigasi nama={s.ikon} />
                                            <span className="truncate text-teks-utama">{hasil.judul}</span>
                                            {hasil.keterangan ? (
                                                <span className="ml-auto truncate text-keterangan text-teks-sekunder">
                                                    {hasil.keterangan}
                                                </span>
                                            ) : null}
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            </div>
                        );
                    })}
                </CommandList>
                <div
                    role="status"
                    className="flex items-center justify-between gap-2 border-t border-garis px-3 py-2 text-keterangan text-teks-sekunder"
                >
                    <span>{sedangMencari ? 'Mencari data…' : 'Enter untuk membuka, Esc untuk menutup'}</span>
                    <KbdGroup aria-hidden="true">
                        <Kbd>↑</Kbd>
                        <Kbd>↓</Kbd>
                    </KbdGroup>
                </div>
            </Command>
        </>
    );
}
