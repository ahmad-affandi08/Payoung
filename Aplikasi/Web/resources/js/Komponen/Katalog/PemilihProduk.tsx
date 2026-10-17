import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { ChevronsUpDownIcon, SearchIcon } from 'lucide-react';
import { useEffect, useId, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';

import TombolPindaiKamera from '@/Komponen/Katalog/TombolPindaiKamera';
import { Command, CommandItem, CommandList } from '@/Komponen/Ui/command';
import { Label } from '@/Komponen/Ui/label';
import { GayaTinggiPopoverCari, usePerilakuPopoverCari } from '@/Komponen/Formulir/PerilakuPopoverCari';
import { Popover, PopoverContent, PopoverTrigger } from '@/Komponen/Ui/popover';
import { cn } from '@/Komponen/Ui/utils';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import type { HasilCariProduk, JenisProduk } from '@/Tipe/Katalog';

export type ProdukTerpilih = HasilCariProduk['Data'][number];

/** Jumlah produk yang diambil sekali minta, termasuk saat daftar dibuka tanpa kata kunci. */
export const BATAS_CARI_PRODUK = 20;

/** URL pencarian produk untuk pemilih bahan/komponen (DesainF03 D.2). */
export function BuatUrlCariProduk(kata: string, jenis: readonly JenisProduk[], batas = BATAS_CARI_PRODUK): string {
    const parameter = new URLSearchParams({ kata });

    jenis.forEach((item) => parameter.append('jenis[]', item));
    parameter.set('batas', String(batas));

    return `/kelola/produk/cari?${parameter.toString()}`;
}

/** Ambil hasil pencarian produk dari endpoint back-office (JSON `{ Data: [...] }`). */
export async function AmbilHasilCari<T>(url: string, sinyal: AbortSignal): Promise<T> {
    const respons = await fetch(url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: sinyal,
    });

    if (!respons.ok) {
        throw new Error(`Pencarian produk gagal (${String(respons.status)})`);
    }

    return (await respons.json()) as T;
}

/** Nilai yang baru berubah setelah pengguna berhenti mengetik `jeda` ms. */
export function useNilaiTertunda(nilai: string, jeda: number): string {
    const [tertunda, AturTertunda] = useState(nilai);

    useEffect(() => {
        const pewaktu = window.setTimeout(() => AturTertunda(nilai), jeda);

        return () => window.clearTimeout(pewaktu);
    }, [nilai, jeda]);

    return tertunda;
}

type PropsKerangka = {
    label: string;
    keterangan?: string | undefined;
    galat?: string | undefined;
    disabled?: boolean | undefined;
    /** Teks tombol pemicu dan placeholder kotak cari. */
    placeholder: string;
    /** Pilihan yang sedang aktif (pemilih satu nilai, misal pelanggan): tampil di tombol pemicu menggantikan `placeholder`. */
    nilaiTerpilih?: string | undefined;
    /** Wajib diisi: label diberi tanda * merah otomatis (Gaya/Aplikasi.css), sama dengan `BidangPilihan`. */
    wajib?: boolean | undefined;
    kata: string;
    saatKata: (kata: string) => void;
    terbuka: boolean;
    saatTerbuka: (terbuka: boolean) => void;
    /** Nilai item cmdk yang sedang disorot; diatur sendiri karena penyaringan milik server. */
    sorot: string;
    saatSorot: (nilai: string) => void;
    status: string | null;
    /**
     * Bila diisi, tombol kamera kecil muncul di samping pemicu; barcode yang terbaca diteruskan ke fungsi ini
     * (biasanya dari {@link usePilihSetelahPindai}). Hanya untuk pemilih produk; pemilih pelanggan/jasa tidak memakainya.
     */
    saatPindai?: ((barcode: string) => void) | undefined;
    children: ReactNode;
};

/**
 * Kerangka pemilih produk berbasis pencarian server, **mengikuti pola `Komponen/Formulir/PilihanCari`**: tombol
 * pemicu + kotak cari di dalam popover, bukan input telanjang yang menutup daftar lewat `onBlur`.
 *
 * Pola lama membuat daftar tidak bisa digulir: menyentuh scrollbar memindahkan fokus dari input sehingga daftar
 * langsung menutup. Dengan kotak cari di dalam popover, fokus tidak pernah keluar dan Radix yang mengatur
 * tutup-buka — persis seperti dropdown lain di aplikasi ini.
 *
 * Yang tetap berbeda dari `PilihanCari` hanya sumber datanya: opsi produk datang dari server (bisa ribuan,
 * termasuk pencarian barcode), bukan larik statis yang disaring di klien.
 */
export function KerangkaPemilihProduk({
    label,
    keterangan,
    galat,
    disabled,
    placeholder,
    nilaiTerpilih,
    wajib,
    kata,
    saatKata,
    terbuka,
    saatTerbuka,
    sorot,
    saatSorot,
    status,
    saatPindai,
    children,
}: PropsKerangka) {
    const id = useId();
    const idDaftar = `${id}-daftar`;
    const pemicu = useRef<HTMLButtonElement>(null);
    const { modal, isi, SiapkanBuka, SaatBukaFokus } = usePerilakuPopoverCari(pemicu, terbuka);
    const AturBuka = (buka: boolean) => {
        if (buka) {
            SiapkanBuka();
        }
        saatTerbuka(buka);
    };

    // Mengetik huruf saat tombol fokus langsung membuka daftar dan mengisi kotak cari, sehingga pemindai barcode
    // yang mengetik ke bidang terfokus tetap bekerja tanpa klik tambahan.
    const SaatTombol = (peristiwa: KeyboardEvent<HTMLButtonElement>) => {
        if (peristiwa.key.length === 1 && !peristiwa.ctrlKey && !peristiwa.metaKey && !peristiwa.altKey) {
            if (peristiwa.key !== ' ') {
                peristiwa.preventDefault();
                AturBuka(true);
                saatKata(peristiwa.key);
            }
        } else if (peristiwa.key === 'ArrowDown') {
            peristiwa.preventDefault();
            AturBuka(true);
        }
    };

    /*
     * `modal` hanya bila pemicu berada di dalam Dialog/Sheet/Popover lain; di halaman biasa non-modal supaya halaman
     * tetap bisa digulir dan daftar tidak terkunci di bawah keyboard HP (alasan lengkap: `PerilakuPopoverCari`).
     */
    return (
        <div data-slot="field" className="flex flex-col gap-1">
            <Label htmlFor={id} className="text-label font-semibold text-teks-utama">
                {label}
            </Label>
            <div className="flex items-center gap-2">
                <div className="min-w-0 flex-1">
                    <Popover modal={modal} open={terbuka} onOpenChange={AturBuka}>
                        <PopoverTrigger asChild>
                            <button
                                ref={pemicu}
                                id={id}
                                type="button"
                                role="combobox"
                                aria-expanded={terbuka}
                                aria-controls={idDaftar}
                                aria-haspopup="listbox"
                                aria-invalid={galat ? true : undefined}
                                aria-required={wajib || undefined}
                                data-wajib={wajib || undefined}
                                aria-describedby={
                                    [keterangan ? `${id}-keterangan` : null, galat ? `${id}-galat` : null]
                                        .filter(Boolean)
                                        .join(' ') || undefined
                                }
                                disabled={disabled}
                                onKeyDown={SaatTombol}
                                className={cn(
                                    'flex h-8 w-full min-w-0 items-center justify-between gap-2 rounded-kontrol border bg-permukaan px-3 text-left text-isi shadow-xs outline-none pointer-coarse:h-11',
                                    'focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-brand/40',
                                    'disabled:cursor-not-allowed disabled:bg-latar disabled:text-teks-sekunder',
                                    galat ? 'border-bahaya' : 'border-garis-input',
                                    terbuka && 'border-brand ring-2 ring-brand/40',
                                )}
                            >
                                <span
                                    className={cn('truncate', nilaiTerpilih ? 'text-teks-utama' : 'text-teks-sekunder')}
                                >
                                    {nilaiTerpilih ?? placeholder}
                                </span>
                                <ChevronsUpDownIcon aria-hidden="true" className="size-4 shrink-0 text-teks-sekunder" />
                            </button>
                        </PopoverTrigger>
                        <PopoverContent
                            ref={isi}
                            style={GayaTinggiPopoverCari}
                            align="start"
                            side="bottom"
                            sideOffset={4}
                            collisionPadding={8}
                            className="flex max-h-(--radix-popover-content-available-height) w-(--radix-popover-trigger-width) min-w-56 flex-col overflow-hidden border-garis bg-permukaan p-0"
                            onOpenAutoFocus={SaatBukaFokus}
                            onCloseAutoFocus={(peristiwa) => {
                                peristiwa.preventDefault();
                                if (pemicu.current?.isConnected) {
                                    pemicu.current.focus();
                                }
                            }}
                        >
                            <Command
                                id={idDaftar}
                                shouldFilter={false}
                                loop
                                value={sorot}
                                onValueChange={saatSorot}
                                className="flex min-h-0 flex-col bg-permukaan"
                                label={label}
                            >
                                <div className="flex shrink-0 items-center gap-2 border-b border-garis px-3">
                                    <SearchIcon aria-hidden="true" className="size-4 shrink-0 text-teks-sekunder" />
                                    <input
                                        value={kata}
                                        onChange={(peristiwa) => saatKata(peristiwa.target.value)}
                                        placeholder={placeholder}
                                        aria-label={`Cari ${label}`}
                                        autoComplete="off"
                                        className="h-8 w-full bg-transparent text-isi text-teks-utama outline-none pointer-coarse:h-11 placeholder:text-teks-sekunder"
                                    />
                                </div>
                                <CommandList className="max-h-72 min-h-0 flex-1 touch-pan-y overscroll-contain">
                                    {children}
                                </CommandList>
                                <p
                                    id={`${id}-status`}
                                    aria-live="polite"
                                    // Saat tidak ada status, elemennya tetap ada sebagai area live tetapi tanpa kotak:
                                    // sebelumnya padding-nya menyisakan pita putih kosong di bawah daftar.
                                    className={cn(
                                        'shrink-0 text-keterangan text-teks-sekunder',
                                        status !== null ? 'border-t border-garis px-3 py-2' : 'sr-only',
                                    )}
                                >
                                    {status ?? ''}
                                </p>
                            </Command>
                        </PopoverContent>
                    </Popover>
                </div>
                {saatPindai ? (
                    <TombolPindaiKamera
                        ikonSaja
                        label={`Pindai barcode ${label}`}
                        saatTerbaca={saatPindai}
                        disabled={disabled === true}
                    />
                ) : null}
            </div>
            {keterangan ? (
                <p id={`${id}-keterangan`} className="text-keterangan text-teks-sekunder">
                    {keterangan}
                </p>
            ) : null}
            {galat ? (
                <p id={`${id}-galat`} className="text-keterangan font-semibold text-bahaya">
                    {galat}
                </p>
            ) : null}
        </div>
    );
}

/** Sorotan menunjuk item pertama yang tersedia, supaya Enter langsung memilih tanpa menekan panah dulu. */
export function useSorotPertama(kunci: string[], AturSorot: (nilai: string) => void): void {
    const gabungan = kunci.join('|');

    useEffect(() => {
        AturSorot(gabungan.split('|')[0] ?? '');
    }, [gabungan, AturSorot]);
}

/**
 * Pindai kamera untuk pemilih produk: barcode yang terbaca mengisi kotak cari dan membuka daftar; bila hasil
 * pencarian persis satu barang, barang itu langsung dipilih (sama dengan pemindai USB + Enter), selain itu daftar
 * dibiarkan terbuka supaya pengguna memilih. `siap` = hasil untuk kata itu sudah selesai dimuat.
 */
export function usePilihSetelahPindai<T>({
    kataCari,
    hasil,
    siap,
    Pilih,
    AturKata,
    AturTerbuka,
}: {
    kataCari: string;
    hasil: T[];
    siap: boolean;
    Pilih: (baris: T) => void;
    AturKata: (kata: string) => void;
    AturTerbuka: (terbuka: boolean) => void;
}): (barcode: string) => void {
    // Ref, bukan state: kode yang menunggu hasil tidak memengaruhi tampilan, dan dibersihkan di dalam efek.
    const menunggu = useRef<string | null>(null);
    const [, AturDenyut] = useState(0);

    useEffect(() => {
        if (menunggu.current === null || !siap || kataCari !== menunggu.current) {
            return;
        }

        menunggu.current = null;

        const satu = hasil.length === 1 ? hasil[0] : undefined;

        if (satu !== undefined) {
            Pilih(satu);
        }
    }, [siap, kataCari, hasil, Pilih]);

    return (barcode: string) => {
        const kode = barcode.trim();

        menunggu.current = kode;
        AturKata(kode);
        AturTerbuka(true);
        // Pastikan render ulang walau kata dan status buka tidak berubah (barcode yang sama dipindai dua kali).
        AturDenyut((n) => n + 1);
    };
}

type PropsPemilihProduk = {
    label: string;
    /** Jenis produk yang boleh dipilih, misal bahan resep: BahanBaku, Stok, Produksi. */
    jenis: readonly JenisProduk[];
    saatPilih: (produk: ProdukTerpilih) => void;
    /** Uuid produk yang tidak boleh dipilih (produk itu sendiri, bahan yang sudah ada). */
    kecuali?: string[];
    keterangan?: string;
    galat?: string | undefined;
    disabled?: boolean;
};

/**
 * Pemilih produk dengan pencarian server (TanStack Query, KunciKueri.Produk.Cari) di dalam
 * {@link KerangkaPemilihProduk}. Membuka daftar tanpa mengetik menampilkan produk pertama urut nama, sehingga
 * pengguna yang belum hafal nama/SKU/barcode tetap tahu ada produk apa saja.
 */
export default function PemilihProduk({
    label,
    jenis,
    saatPilih,
    kecuali = [],
    keterangan,
    galat,
    disabled,
}: PropsPemilihProduk) {
    const [kata, AturKata] = useState('');
    const [terbuka, AturTerbuka] = useState(false);
    const [sorot, AturSorot] = useState('');
    const kataCari = useNilaiTertunda(kata.trim(), 300);
    const kueri = useQuery({
        queryKey: KunciKueri.Produk.Cari(kataCari, jenis),
        queryFn: ({ signal }) => AmbilHasilCari<HasilCariProduk>(BuatUrlCariProduk(kataCari, jenis), signal),
        enabled: terbuka,
        staleTime: 30_000,
        // Hasil lama tetap tampil selama hasil baru dimuat, jadi daftar tidak berkedip saat mengetik.
        placeholderData: keepPreviousData,
    });
    const hasil = (kueri.data?.Data ?? []).filter((produk) => !kecuali.includes(produk.Uuid));

    useSorotPertama(
        hasil.map((produk) => produk.Uuid),
        AturSorot,
    );

    const Buka = (buka: boolean) => {
        AturTerbuka(buka);
        if (!buka) {
            AturKata('');
        }
    };

    const Pilih = (produk: ProdukTerpilih) => {
        saatPilih(produk);
        Buka(false);
    };
    const SaatPindai = usePilihSetelahPindai({
        kataCari,
        hasil,
        siap: !kueri.isFetching && !kueri.isPlaceholderData,
        Pilih,
        AturKata,
        AturTerbuka,
    });

    let status: string | null = null;

    if (terbuka && kueri.isPending) {
        status = kataCari === '' ? 'Memuat produk…' : 'Mencari produk…';
    } else if (terbuka && kueri.isError) {
        status = 'Pencarian gagal. Periksa koneksi lalu ketik ulang.';
    } else if (terbuka && hasil.length === 0) {
        status =
            kataCari === ''
                ? 'Belum ada produk yang bisa dipilih.'
                : `Tidak ada produk yang cocok dengan "${kataCari}".`;
    } else if (terbuka && kataCari === '' && (kueri.data?.Data.length ?? 0) >= BATAS_CARI_PRODUK) {
        // Daftar dipotong server; tanpa keterangan ini pengguna mengira produknya memang cuma segitu.
        status = `Menampilkan ${String(BATAS_CARI_PRODUK)} produk pertama. Ketik untuk mencari yang lain.`;
    }

    return (
        <KerangkaPemilihProduk
            label={label}
            keterangan={keterangan}
            galat={galat}
            disabled={disabled}
            placeholder="Cari nama, SKU, atau barcode"
            kata={kata}
            saatKata={AturKata}
            terbuka={terbuka}
            saatTerbuka={Buka}
            sorot={sorot}
            saatSorot={AturSorot}
            status={status}
            saatPindai={SaatPindai}
        >
            {hasil.map((produk) => (
                <CommandItem
                    key={produk.Uuid}
                    value={produk.Uuid}
                    data-slot="pilihan-cari-item"
                    data-nilai={produk.Uuid}
                    onSelect={() => Pilih(produk)}
                    className="flex min-h-9 cursor-pointer flex-col items-start gap-0 rounded-kontrol px-3 py-2 text-isi data-[selected=true]:bg-brand-lembut data-[selected=true]:text-teks-utama pointer-coarse:min-h-11"
                >
                    <span className="font-semibold break-words text-teks-utama">{produk.Nama}</span>
                    <span className="text-keterangan text-teks-sekunder">
                        {produk.Sku ? <span className="font-mono">{produk.Sku}</span> : 'Tanpa SKU'} |{' '}
                        {produk.Satuan.map((satuan) => satuan.Simbol).join(', ')}
                    </span>
                </CommandItem>
            ))}
        </KerangkaPemilihProduk>
    );
}
