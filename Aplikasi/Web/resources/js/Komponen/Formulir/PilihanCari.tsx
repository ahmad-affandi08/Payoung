import { CheckIcon, ChevronsUpDownIcon, SearchIcon } from 'lucide-react';
import { useId, useMemo, useRef, useState, type KeyboardEvent } from 'react';

import BarisTambahPilihan, { type AksiTambahPilihan } from '@/Komponen/Formulir/BarisTambahPilihan';
import { Command, CommandEmpty, CommandGroup, CommandItem, CommandList } from '@/Komponen/Ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/Komponen/Ui/popover';
import { cn } from '@/Komponen/Ui/utils';

import { GayaTinggiPopoverCari, usePerilakuPopoverCari } from './PerilakuPopoverCari';

export type OpsiPilihan = { Nilai: string; Label: string; Keterangan?: string | null };

export type PropsPilihanCari = {
    /** Nama bidang (untuk placeholder cari & pembaca layar). */
    label: string;
    nilai: string;
    opsi: OpsiPilihan[];
    saatBerubah: (nilai: string) => void;
    /** Opsi "kosong" di urutan pertama (nilai ""), mis. "Semua status". */
    kosong?: string | undefined;
    /** Teks tombol saat belum ada pilihan dan tidak ada opsi kosong. */
    placeholder?: string | undefined;
    id?: string | undefined;
    disabled?: boolean | undefined;
    /** Wajib diisi: label bidang otomatis diberi tanda * merah (Gaya/Aplikasi.css). */
    required?: boolean | undefined;
    galat?: unknown;
    'aria-describedby'?: string | undefined;
    /** Nama aksesibel bila tidak ada label terlihat (mis. di sel tabel isian). */
    'aria-label'?: string | undefined;
    className?: string | undefined;
    /** Baris aksi di dasar daftar, mis. "Tambah pemasok baru" (D-273). */
    aksiTambah?: AksiTambahPilihan | undefined;
};

/** Pencocokan cari: tanpa beda huruf besar/kecil & tanda baca ringan, setiap kata harus ada. */
export function CocokkanCari(opsi: OpsiPilihan, kata: string): boolean {
    const teks = `${opsi.Label} ${opsi.Keterangan ?? ''}`.toLocaleLowerCase('id-ID');

    return kata
        .toLocaleLowerCase('id-ID')
        .split(/\s+/)
        .filter(Boolean)
        .every((bagian) => teks.includes(bagian));
}

/**
 * Pilihan tunggal dengan kotak cari (§17.6): tombol pemicu + daftar yang selalu terbuka di bawah (atau di atas
 * bila ruang bawah tidak cukup) sehingga tidak pernah menutupi tombolnya sendiri, selebar tombol, dengan tanda
 * centang pada pilihan aktif. Keyboard: Enter/Spasi/↓ membuka, huruf apa pun langsung mulai mencari, ↑/↓ + Enter
 * memilih, Esc menutup. Menggantikan elemen select bawaan peramban di seluruh web.
 */
export default function PilihanCari({
    label,
    nilai,
    opsi,
    saatBerubah,
    kosong,
    placeholder,
    id,
    disabled = false,
    required = false,
    galat,
    'aria-describedby': dijelaskanOleh,
    'aria-label': labelAria,
    className,
    aksiTambah,
}: PropsPilihanCari) {
    const idOtomatis = useId();
    const idDaftar = `${id ?? idOtomatis}-daftar`;
    const [terbuka, AturTerbuka] = useState(false);
    const pemicu = useRef<HTMLButtonElement>(null);
    // Aksi tambah membuka dialog: fokus tidak boleh kembali ke pemicu, sebab itu mengeluarkannya dari dialog yang baru terbuka.
    const lewatiFokusTutup = useRef(false);
    const [kata, AturKata] = useState('');
    // Sorotan keyboard (nilai item cmdk). Diatur sendiri karena penyaringan juga milik kita.
    const [sorot, AturSorot] = useState('');
    const semua = useMemo<OpsiPilihan[]>(
        () => (kosong !== undefined ? [{ Nilai: '', Label: kosong }, ...opsi] : opsi),
        [kosong, opsi],
    );
    const terpilih = semua.find((o) => o.Nilai === nilai);
    const tampil = kata.trim() === '' ? semua : semua.filter((o) => CocokkanCari(o, kata));

    const KunciItem = (o: OpsiPilihan) => (o.Nilai === '' ? '__kosong' : o.Nilai);

    const { modal, isi, SiapkanBuka, SaatBukaFokus } = usePerilakuPopoverCari(pemicu, terbuka);

    const Buka = (buka: boolean) => {
        if (buka) {
            SiapkanBuka();
        }
        AturTerbuka(buka);
        if (buka) {
            AturSorot(terpilih ? KunciItem(terpilih) : '');
        } else {
            AturKata('');
        }
    };

    const Cari = (teks: string) => {
        AturKata(teks);
        const pertama = (teks.trim() === '' ? semua : semua.filter((o) => CocokkanCari(o, teks)))[0];
        AturSorot(pertama ? KunciItem(pertama) : '');
    };

    const Pilih = (baru: string) => {
        saatBerubah(baru);
        Buka(false);
    };

    // Mengetik huruf saat tombol fokus langsung membuka daftar dan mengisi kotak cari.
    const SaatTombol = (peristiwa: KeyboardEvent<HTMLButtonElement>) => {
        if (peristiwa.key.length === 1 && !peristiwa.ctrlKey && !peristiwa.metaKey && !peristiwa.altKey) {
            if (peristiwa.key !== ' ') {
                peristiwa.preventDefault();
                AturTerbuka(true);
                Cari(peristiwa.key);
            }
        } else if (peristiwa.key === 'ArrowDown') {
            peristiwa.preventDefault();
            Buka(true);
        }
    };

    /*
     * `modal` hanya bila pemicu berada di dalam Dialog/Sheet/Popover lain (lihat `PerilakuPopoverCari`): kunci
     * gulir pembungkus itu memblokir `touchmove` di isi popover yang di-portal ke body. Di halaman biasa popover
     * non-modal, jadi halaman tetap bisa digulir dan daftar tidak terkunci di bawah keyboard HP.
     */
    return (
        <Popover modal={modal} open={terbuka} onOpenChange={Buka}>
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
                    aria-required={required || undefined}
                    data-wajib={required || undefined}
                    aria-describedby={dijelaskanOleh}
                    aria-label={labelAria}
                    disabled={disabled}
                    onKeyDown={SaatTombol}
                    className={cn(
                        'flex h-8 w-full min-w-0 items-center justify-between gap-2 rounded-kontrol border bg-permukaan px-3 text-left text-isi text-teks-utama shadow-xs outline-none pointer-coarse:h-11',
                        'focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-brand/40',
                        'disabled:cursor-not-allowed disabled:bg-latar disabled:text-teks-sekunder',
                        galat ? 'border-bahaya' : 'border-garis-input',
                        terbuka && 'border-brand ring-2 ring-brand/40',
                        className,
                    )}
                >
                    <span className={cn('truncate', (!terpilih || terpilih.Nilai === '') && 'text-teks-sekunder')}>
                        {terpilih ? terpilih.Label : (placeholder ?? `Pilih ${label.toLowerCase()}`)}
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
                onCloseAutoFocus={(peristiwa) => {
                    // Kembalikan fokus ke pemicu hanya bila masih terpasang. Di kalender, pemicu bulan/tahun dibuat
                    // ulang saat bulan berganti; fokus bawaan Radix lalu jatuh ke luar dan menutup popover induk.
                    peristiwa.preventDefault();
                    if (lewatiFokusTutup.current) {
                        lewatiFokusTutup.current = false;

                        return;
                    }
                    if (pemicu.current?.isConnected) {
                        pemicu.current.focus();
                    }
                }}
                onOpenAutoFocus={SaatBukaFokus}
            >
                <Command
                    id={idDaftar}
                    shouldFilter={false}
                    loop
                    value={sorot}
                    onValueChange={AturSorot}
                    className="flex min-h-0 flex-col bg-permukaan"
                    label={label}
                >
                    <div className="flex shrink-0 items-center gap-2 border-b border-garis px-3">
                        <SearchIcon aria-hidden="true" className="size-4 shrink-0 text-teks-sekunder" />
                        <input
                            value={kata}
                            onChange={(peristiwa) => Cari(peristiwa.target.value)}
                            placeholder={`Cari ${label.toLowerCase()}…`}
                            aria-label={`Cari ${label}`}
                            aria-controls={idDaftar}
                            autoComplete="off"
                            className="h-8 pointer-coarse:h-11 w-full bg-transparent text-isi text-teks-utama outline-none placeholder:text-teks-sekunder"
                        />
                    </div>
                    <CommandList className="max-h-72 min-h-0 flex-1 touch-pan-y overscroll-contain">
                        <CommandEmpty className="px-3 py-6 text-center text-label text-teks-sekunder">
                            Tidak ada yang cocok dengan “{kata}”.
                        </CommandEmpty>
                        <CommandGroup className="p-1">
                            {tampil.map((o) => {
                                const aktif = o.Nilai === nilai;

                                return (
                                    <CommandItem
                                        key={KunciItem(o)}
                                        value={KunciItem(o)}
                                        data-nilai={o.Nilai}
                                        data-slot="pilihan-cari-item"
                                        onSelect={() => Pilih(o.Nilai)}
                                        className={cn(
                                            'min-h-9 cursor-pointer gap-2 rounded-kontrol px-2 py-1.5 text-isi text-teks-utama data-[selected=true]:bg-brand-lembut data-[selected=true]:text-teks-utama pointer-coarse:min-h-11',
                                            o.Nilai === '' && 'text-teks-sekunder',
                                        )}
                                    >
                                        <CheckIcon
                                            aria-hidden="true"
                                            className={cn(
                                                'size-4 shrink-0 text-brand',
                                                aktif ? 'opacity-100' : 'opacity-0',
                                            )}
                                        />
                                        <span className="flex min-w-0 flex-col">
                                            <span className={cn('break-words', aktif && 'font-semibold')}>
                                                {o.Label}
                                            </span>
                                            {o.Keterangan ? (
                                                <span className="text-keterangan text-teks-sekunder">
                                                    {o.Keterangan}
                                                </span>
                                            ) : null}
                                        </span>
                                    </CommandItem>
                                );
                            })}
                        </CommandGroup>
                    </CommandList>
                </Command>
                {aksiTambah ? (
                    <BarisTambahPilihan
                        aksi={aksiTambah}
                        saatTutup={() => {
                            lewatiFokusTutup.current = true;
                            Buka(false);
                        }}
                    />
                ) : null}
            </PopoverContent>
        </Popover>
    );
}
