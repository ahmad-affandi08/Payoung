import { ClockIcon, XIcon } from 'lucide-react';
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';

import { PemicuDalamPembungkus } from '@/Komponen/Formulir/PerilakuPopoverCari';
import { Button } from '@/Komponen/Ui/button';
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupInput } from '@/Komponen/Ui/input-group';
import { Label } from '@/Komponen/Ui/label';
import { Popover, PopoverAnchor, PopoverContent } from '@/Komponen/Ui/popover';
import { cn } from '@/Komponen/Ui/utils';
import { RapikanTeksJam, UraiTeksJam } from '@/Pustaka/Tanggal';

export type PilihanCepatJam = { Label: string; Nilai: string };

export type PropsPemilihJam = {
    label: string;
    /** `JJ:MM` (24 jam), string kosong, atau ketikan yang belum sah (agar formulir induk bisa menandainya). */
    nilai: string;
    saatBerubah: (nilai: string) => void;
    galat?: string | undefined;
    keterangan?: string | undefined;
    disabled?: boolean;
    required?: boolean;
    id?: string;
    className?: string;
    /** Placeholder isian, misal `08:00`. */
    contoh?: string;
    /** Selang menit di kolom Menit (bawaan 5). */
    langkahMenit?: number;
    /** Tombol satu-ketuk di atas panel, misal shift Pagi atau Happy hour. */
    pilihanCepat?: PilihanCepatJam[];
    /** Tombol "Sekarang" (dibulatkan ke `langkahMenit`), misal untuk koreksi absensi. */
    tombolSekarang?: boolean;
    /** Label hanya untuk pembaca layar (sel tabel/jadwal yang sudah berjudul). */
    labelTersembunyi?: boolean;
    /** Nama isian bagi pembaca layar bila berbeda dari label yang tampil (misal "Jam Mulai berlaku"). */
    labelAria?: string;
    /**
     * Untuk sel sempit seperti jadwal kerja: tanpa tombol jam & kosongkan, pesan galat hanya untuk pembaca layar
     * (bingkai merah tetap tampil). Panel tetap terbuka saat isian diklik.
     */
    ringkas?: boolean;
};

const daftarJam = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0'));

/** Daftar menit per [langkah] (00, 05, …) ditambah menit nilai sekarang bila di luar selang. */
export function SusunDaftarMenit(langkah: number, menitSekarang?: string): string[] {
    const aman = Math.min(30, Math.max(1, Math.floor(langkah)));
    const daftar = Array.from({ length: Math.ceil(60 / aman) }, (_, i) => String(i * aman).padStart(2, '0'));

    return menitSekarang !== undefined && !daftar.includes(menitSekarang) ? [...daftar, menitSekarang].sort() : daftar;
}

/** Jam sekarang dibulatkan ke bawah per [langkah] menit, format `JJ:MM`. */
export function AmbilJamSekarang(langkah: number, sekarang = new Date()): string {
    const aman = Math.min(30, Math.max(1, Math.floor(langkah)));
    const menit = Math.floor(sekarang.getMinutes() / aman) * aman;

    return `${String(sekarang.getHours()).padStart(2, '0')}:${String(menit).padStart(2, '0')}`;
}

/**
 * Pemilih jam 24 jam (§17.6): isian `JJ:MM` yang bisa diketik tanpa titik dua (`830` → 08:30, `17.30` → 17:30),
 * plus panel kolom Jam & Menit yang terbuka saat isian diklik atau lewat tombol jam / Alt+↓. Tidak memakai
 * `<input type="time">` karena tampilannya berbeda tiap peramban (sebagian AM/PM).
 */
export default function PemilihJam({
    label,
    nilai,
    saatBerubah,
    galat,
    keterangan,
    disabled = false,
    required = false,
    id,
    className,
    contoh = '08:00',
    langkahMenit = 5,
    pilihanCepat = [],
    tombolSekarang = false,
    labelTersembunyi = false,
    labelAria,
    ringkas = false,
}: PropsPemilihJam) {
    const idOtomatis = useId();
    const idBidang = id ?? idOtomatis;
    const idKeterangan = `${idBidang}-keterangan`;
    const idGalat = `${idBidang}-galat`;
    const [terbuka, AturTerbuka] = useState(false);
    const [galatKetikan, AturGalatKetikan] = useState<string | null>(null);
    const refPanel = useRef<HTMLDivElement>(null);
    const refAkar = useRef<HTMLDivElement>(null);
    // Di dalam Dialog/Sheet, kunci gulir pembungkus memblokir sentuhan di panel yang di-portal ke body,
    // jadi kolom jam/menit tidak bisa digulir di HP. Panel dibuat `modal` hanya di sana (lihat PerilakuPopoverCari).
    const [modal, AturModal] = useState(false);
    const Buka = (buka: boolean) => {
        if (buka) {
            AturModal(PemicuDalamPembungkus(refAkar.current));
        }
        AturTerbuka(buka);
    };

    const sah = UraiTeksJam(nilai);
    const [jamTerpilih, menitTerpilih] = sah === undefined ? [undefined, undefined] : sah.split(':');
    const daftarMenit = SusunDaftarMenit(langkahMenit, menitTerpilih);
    const galatTampil = galat ?? galatKetikan ?? undefined;
    const dijelaskanOleh = [keterangan ? idKeterangan : null, galatTampil ? idGalat : null].filter(Boolean).join(' ');

    // Saat panel terbuka, gulir kolom ke jam/menit terpilih (atau ke jam 08 bila kosong) agar tidak mulai dari 00.
    useEffect(() => {
        if (!terbuka) {
            return;
        }
        // Panel dipasang lewat portal setelah render ini, jadi gulir pada bingkai berikutnya.
        const bingkai = requestAnimationFrame(() => {
            const panel = refPanel.current;
            [
                panel?.querySelector<HTMLElement>(`[data-jam="${jamTerpilih ?? '08'}"]`),
                panel?.querySelector<HTMLElement>(`[data-menit="${menitTerpilih ?? '00'}"]`),
            ].forEach((el) => el?.scrollIntoView?.({ block: 'center' }));
        });

        return () => cancelAnimationFrame(bingkai);
    }, [terbuka, jamTerpilih, menitTerpilih]);

    const Terapkan = (baru: string, tutup = false) => {
        AturGalatKetikan(null);
        saatBerubah(baru);
        if (tutup) {
            AturTerbuka(false);
        }
    };

    const SaatKetik = (teks: string) => {
        AturGalatKetikan(null);
        // Empat angka atau `JJ:MM` langsung dirapikan; ketikan lain diteruskan apa adanya sampai isian ditinggalkan.
        const langsung =
            /^\d{4}$/.test(teks.trim()) || UraiTeksJam(teks) !== undefined ? RapikanTeksJam(teks) : undefined;
        saatBerubah(langsung ?? teks);
    };

    const SaatKeluar = () => {
        if (nilai.trim() === '') {
            return;
        }
        const rapi = RapikanTeksJam(nilai);

        if (rapi === undefined) {
            AturGalatKetikan('Tulis jam sebagai JJ:MM (24 jam), misal 08:30.');
        } else if (rapi !== nilai) {
            saatBerubah(rapi);
        }
    };

    const SaatTombol = (peristiwa: KeyboardEvent<HTMLInputElement>) => {
        if (peristiwa.altKey && peristiwa.key === 'ArrowDown') {
            peristiwa.preventDefault();
            Buka(true);
        } else if (peristiwa.key === 'Escape' && terbuka) {
            AturTerbuka(false);
        } else if (peristiwa.key === 'Enter' && terbuka) {
            AturTerbuka(false);
        }
    };

    return (
        <div ref={refAkar} className={cn('flex min-w-0 flex-col gap-1', className)}>
            <Label
                htmlFor={idBidang}
                className={labelTersembunyi ? 'sr-only' : 'text-label font-semibold text-teks-utama'}
            >
                {label}
            </Label>
            <Popover modal={modal} open={terbuka && !disabled} onOpenChange={Buka}>
                <PopoverAnchor asChild>
                    <InputGroup className="h-8 min-w-0 border-garis-input bg-permukaan pointer-coarse:h-11">
                        <InputGroupInput
                            id={idBidang}
                            value={nilai}
                            inputMode="numeric"
                            autoComplete="off"
                            maxLength={5}
                            placeholder={contoh}
                            onChange={(peristiwa) => SaatKetik(peristiwa.target.value)}
                            onClick={() => Buka(true)}
                            onBlur={SaatKeluar}
                            onKeyDown={SaatTombol}
                            aria-label={labelAria ?? (labelTersembunyi ? label : undefined)}
                            aria-invalid={galatTampil ? true : undefined}
                            aria-describedby={dijelaskanOleh || undefined}
                            aria-required={required || undefined}
                            aria-haspopup="dialog"
                            aria-expanded={terbuka}
                            disabled={disabled}
                            className={cn(
                                'min-w-0 text-isi tabular-nums placeholder:text-teks-sekunder/70',
                                ringkas && 'px-1 text-center font-mono',
                            )}
                        />
                        {ringkas ? null : (
                            <InputGroupAddon align="inline-end">
                                {!required && nilai !== '' && !disabled ? (
                                    <InputGroupButton
                                        size="icon-xs"
                                        aria-label={`Kosongkan ${label}`}
                                        onClick={() => Terapkan('')}
                                    >
                                        <XIcon />
                                    </InputGroupButton>
                                ) : null}
                                <InputGroupButton
                                    size="icon-xs"
                                    aria-label={`Pilih ${label}`}
                                    disabled={disabled}
                                    onClick={() => Buka(!terbuka)}
                                    className="text-teks-sekunder hover:text-brand"
                                >
                                    <ClockIcon />
                                </InputGroupButton>
                            </InputGroupAddon>
                        )}
                    </InputGroup>
                </PopoverAnchor>
                <PopoverContent
                    ref={refPanel}
                    className="w-60 p-2"
                    align="start"
                    // Fokus tetap di isian supaya jam masih bisa diketik saat panel terbuka.
                    onOpenAutoFocus={(peristiwa) => peristiwa.preventDefault()}
                    onClick={(peristiwa) => peristiwa.stopPropagation()}
                    aria-label={`Pilih ${label}`}
                >
                    {pilihanCepat.length > 0 ? (
                        <div className="mb-2 flex flex-wrap gap-1">
                            {pilihanCepat.map((p) => (
                                <Button
                                    key={`${p.Label}-${p.Nilai}`}
                                    type="button"
                                    variant={nilai === p.Nilai ? 'default' : 'outline'}
                                    size="sm"
                                    className="h-8 px-2 text-keterangan"
                                    onClick={() => Terapkan(p.Nilai, true)}
                                >
                                    {p.Label}
                                </Button>
                            ))}
                        </div>
                    ) : null}
                    <div className="grid grid-cols-2 gap-2">
                        {(
                            [
                                ['Jam', daftarJam, jamTerpilih, 'jam'],
                                ['Menit', daftarMenit, menitTerpilih, 'menit'],
                            ] as const
                        ).map(([judul, daftar, terpilih, jenis]) => (
                            <div key={judul} className="flex min-w-0 flex-col gap-1">
                                <p className="px-1 text-keterangan font-semibold text-teks-sekunder">{judul}</p>
                                <div
                                    role="listbox"
                                    aria-label={`${judul} ${label}`}
                                    className="flex h-48 touch-pan-y flex-col gap-0.5 overflow-y-auto overscroll-contain pr-1"
                                >
                                    {daftar.map((angka) => (
                                        <button
                                            key={angka}
                                            type="button"
                                            role="option"
                                            aria-selected={terpilih === angka}
                                            {...(jenis === 'jam' ? { 'data-jam': angka } : { 'data-menit': angka })}
                                            onClick={() =>
                                                jenis === 'jam'
                                                    ? Terapkan(`${angka}:${menitTerpilih ?? '00'}`)
                                                    : Terapkan(`${jamTerpilih ?? '08'}:${angka}`, true)
                                            }
                                            className={cn(
                                                'shrink-0 rounded-md px-2 py-1.5 text-center font-mono text-isi tabular-nums transition-colors pointer-coarse:py-2.5',
                                                terpilih === angka
                                                    ? 'bg-brand font-semibold text-permukaan'
                                                    : 'text-teks-utama hover:bg-brand-lembut',
                                            )}
                                        >
                                            {angka}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                    <div className="mt-2 flex items-center justify-between gap-2 border-t border-garis pt-2">
                        {tombolSekarang ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => Terapkan(AmbilJamSekarang(langkahMenit), true)}
                            >
                                Sekarang
                            </Button>
                        ) : (
                            <span />
                        )}
                        <span className="font-mono text-isi font-semibold tabular-nums text-teks-utama">
                            {sah ?? '--:--'}
                        </span>
                        <Button type="button" size="sm" onClick={() => AturTerbuka(false)}>
                            Selesai
                        </Button>
                    </div>
                </PopoverContent>
            </Popover>
            {keterangan ? (
                <p id={idKeterangan} className="text-keterangan text-teks-sekunder">
                    {keterangan}
                </p>
            ) : null}
            {galatTampil ? (
                <p id={idGalat} className={ringkas ? 'sr-only' : 'text-keterangan font-semibold text-bahaya'}>
                    {galatTampil}
                </p>
            ) : null}
        </div>
    );
}
