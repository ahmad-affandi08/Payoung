import { CalendarRangeIcon, ChevronDownIcon } from 'lucide-react';
import { useId, useState } from 'react';

import { useLebarLayar } from '@/Komponen/TabelData/useLebarLayar';
import { Button } from '@/Komponen/Ui/button';
import { Label } from '@/Komponen/Ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/Komponen/Ui/popover';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/Komponen/Ui/sheet';
import { cn } from '@/Komponen/Ui/utils';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import {
    BuatPresetTanggal,
    CekDiLuarBatas,
    GabungRentang,
    PecahRentang,
    TulisTanggal,
    UraiTanggal,
} from '@/Pustaka/Tanggal';

import Kalender from './Kalender';
import PemilihTanggal from './PemilihTanggal';

type PropsPanelRentang = {
    /** Nama rentang untuk pembaca layar & label isian, mis. "Tanggal". */
    label: string;
    /** `dari..sampai` (salah satu boleh kosong) atau string kosong. */
    nilai: string;
    saatBerubah: (nilai: string) => void;
    min?: string | undefined;
    max?: string | undefined;
    /** Paksa satu bulan (mis. di dalam Sheet HP). Bawaan: dua bulan di desktop, satu di tablet/HP. */
    satuBulan?: boolean | undefined;
    /** Sembunyikan preset (bila rentang bebas tidak relevan, mis. periode laporan tetap). */
    tanpaPreset?: boolean | undefined;
};

/** Ringkasan rentang untuk tombol & chip: nama preset, "1 Sep – 24 Sep 2026", "sejak …", atau "sampai …". */
export function RingkasRentang(nilai: string, hariIni = new Date()): string {
    const preset = BuatPresetTanggal(hariIni).find((p) => p.nilai === nilai);

    if (preset) {
        return preset.label;
    }

    const [dari, sampai] = PecahRentang(nilai);

    if (dari !== '' && sampai !== '') {
        return dari === sampai ? FormatTanggal(dari) : `${FormatTanggal(dari)} – ${FormatTanggal(sampai)}`;
    }

    if (dari !== '') {
        return `sejak ${FormatTanggal(dari)}`;
    }

    return sampai !== '' ? `sampai ${FormatTanggal(sampai)}` : '';
}

/**
 * Isi pemilih rentang tanggal (§17.6.5): preset cepat, kalender rentang (klik awal lalu akhir; klik pertama
 * langsung berlaku sebagai satu hari), dan isian Dari/Sampai `HH/BB/TTTT` untuk diketik. Dipakai langsung di
 * penyunting saring `TabelData` dan di dalam popover `PemilihRentangTanggal`.
 */
export function PanelRentangTanggal({
    label,
    nilai,
    saatBerubah,
    min,
    max,
    satuBulan,
    tanpaPreset,
}: PropsPanelRentang) {
    const lebar = useLebarLayar();
    const [dari, sampai] = PecahRentang(nilai);
    const tanggalDari = UraiTanggal(dari);
    const tanggalSampai = UraiTanggal(sampai);
    const [ujungBaru, AturUjungBaru] = useState(true);
    const preset = tanpaPreset
        ? []
        : BuatPresetTanggal().filter((p) => {
              const [d, s] = PecahRentang(p.nilai);

              return !CekDiLuarBatas(d, min, max) || !CekDiLuarBatas(s, min, max);
          });
    const duaBulan = !satuBulan && lebar === 'desktop';
    const batasKalender = [
        ...(min && UraiTanggal(min) ? [{ before: UraiTanggal(min) as Date }] : []),
        ...(max && UraiTanggal(max) ? [{ after: UraiTanggal(max) as Date }] : []),
    ];

    const PilihHari = (hari: Date) => {
        const iso = TulisTanggal(hari);

        // Klik pertama (atau setelah rentang lengkap) memulai rentang baru sebagai satu hari; klik kedua
        // melengkapi rentang ke arah mana pun.
        if (ujungBaru || dari === '') {
            saatBerubah(GabungRentang(iso, iso));
            AturUjungBaru(false);

            return;
        }

        saatBerubah(iso < dari ? GabungRentang(iso, dari) : GabungRentang(dari, iso));
        AturUjungBaru(true);
    };

    return (
        <div className={cn('flex flex-col gap-2', !satuBulan && 'sm:flex-row sm:items-start sm:gap-3')}>
            {preset.length > 0 ? (
                <div
                    role="group"
                    aria-label={`Preset ${label}`}
                    className={cn(
                        'flex flex-wrap gap-2',
                        !satuBulan && 'sm:w-32 sm:shrink-0 sm:flex-col sm:flex-nowrap sm:gap-1',
                    )}
                >
                    {preset.map((p) => {
                        const aktif = p.nilai === nilai;

                        return (
                            <Button
                                key={p.label}
                                type="button"
                                size="sm"
                                variant={aktif ? 'default' : 'outline'}
                                aria-pressed={aktif}
                                onClick={() => {
                                    saatBerubah(p.nilai);
                                    AturUjungBaru(true);
                                }}
                                className={cn(
                                    'text-label pointer-coarse:h-11',
                                    !satuBulan && 'sm:h-8 sm:justify-start sm:border-transparent sm:shadow-none',
                                    !satuBulan && !aktif && 'sm:bg-transparent sm:hover:bg-brand-lembut',
                                )}
                            >
                                {p.label}
                            </Button>
                        );
                    })}
                </div>
            ) : null}
            <div className="flex min-w-0 flex-col gap-2">
                <Kalender
                    mode="range"
                    numberOfMonths={duaBulan ? 2 : 1}
                    {...(tanggalDari
                        ? { defaultMonth: duaBulan && tanggalSampai ? tanggalDari : (tanggalSampai ?? tanggalDari) }
                        : {})}
                    selected={tanggalDari ? { from: tanggalDari, to: tanggalSampai ?? tanggalDari } : undefined}
                    disabled={batasKalender}
                    onDayClick={(hari, modifier) => {
                        if (!modifier.disabled) {
                            PilihHari(hari);
                        }
                    }}
                    className="self-center"
                />
                <div className="grid grid-cols-2 gap-2 border-t border-garis pt-2">
                    <PemilihTanggal
                        label="Dari"
                        nilai={dari}
                        min={min}
                        max={sampai || max}
                        tanpaKosongkan
                        saatBerubah={(baru) => saatBerubah(GabungRentang(baru, sampai))}
                    />
                    <PemilihTanggal
                        label="Sampai"
                        nilai={sampai}
                        min={dari || min}
                        max={max}
                        tanpaKosongkan
                        saatBerubah={(baru) => saatBerubah(GabungRentang(dari, baru))}
                    />
                </div>
            </div>
        </div>
    );
}

type PropsPemilihRentang = Omit<PropsPanelRentang, 'satuBulan'> & {
    galat?: string | undefined;
    keterangan?: string | undefined;
    /** Teks tombol saat kosong. */
    kosong?: string;
    disabled?: boolean;
};

/** Bidang rentang tanggal untuk form & laporan: tombol ringkasan + popover `PanelRentangTanggal`. */
export default function PemilihRentangTanggal({
    label,
    nilai,
    saatBerubah,
    min,
    max,
    tanpaPreset,
    galat,
    keterangan,
    kosong = 'Semua tanggal',
    disabled = false,
}: PropsPemilihRentang) {
    const id = useId();
    const hp = useLebarLayar() === 'hp';
    const [terbuka, AturTerbuka] = useState(false);
    const ringkasan = RingkasRentang(nilai);
    const dijelaskanOleh = [keterangan ? `${id}-keterangan` : null, galat ? `${id}-galat` : null]
        .filter(Boolean)
        .join(' ');
    const panel = (
        <PanelRentangTanggal
            label={label}
            nilai={nilai}
            saatBerubah={saatBerubah}
            min={min}
            max={max}
            tanpaPreset={tanpaPreset}
            satuBulan={hp}
        />
    );
    const pemicu = (
        <Button
            id={id}
            type="button"
            variant="outline"
            disabled={disabled}
            aria-invalid={galat ? true : undefined}
            aria-describedby={dijelaskanOleh || undefined}
            aria-haspopup="dialog"
            aria-expanded={terbuka}
            onClick={() => AturTerbuka(true)}
            className="h-8 w-full justify-between border-garis-input bg-permukaan px-3 text-isi font-normal text-teks-utama hover:bg-permukaan-redup hover:text-teks-utama pointer-coarse:h-11"
        >
            <span className="flex min-w-0 items-center gap-2">
                <CalendarRangeIcon aria-hidden="true" className="size-4 text-teks-sekunder" />
                <span className={cn('truncate tabular-nums', ringkasan === '' && 'text-teks-sekunder')}>
                    {ringkasan === '' ? kosong : ringkasan}
                </span>
            </span>
            <ChevronDownIcon aria-hidden="true" className="size-4 text-teks-sekunder" />
        </Button>
    );
    const tombol = (
        <>
            {nilai !== '' ? (
                <Button type="button" variant="ghost" size="sm" onClick={() => saatBerubah('')}>
                    Kosongkan
                </Button>
            ) : null}
            <Button type="button" size="sm" onClick={() => AturTerbuka(false)}>
                Selesai
            </Button>
        </>
    );

    return (
        <div className="flex flex-col gap-1">
            <Label htmlFor={id} className="text-label font-semibold text-teks-utama">
                {label}
            </Label>
            {hp ? (
                <>
                    {pemicu}
                    {/* HP (§17.4.4): lembar dari bawah agar kalender & tombol tidak terpotong layar. */}
                    <Sheet open={terbuka} onOpenChange={AturTerbuka}>
                        <SheetContent side="bottom" className="max-h-[90dvh] overflow-y-auto bg-permukaan">
                            <SheetHeader>
                                <SheetTitle>{label}</SheetTitle>
                                <SheetDescription>{ringkasan === '' ? kosong : ringkasan}</SheetDescription>
                            </SheetHeader>
                            <div className="px-4">{panel}</div>
                            <SheetFooter className="flex-row justify-end">{tombol}</SheetFooter>
                        </SheetContent>
                    </Sheet>
                </>
            ) : (
                <Popover open={terbuka} onOpenChange={AturTerbuka}>
                    <PopoverTrigger asChild>{pemicu}</PopoverTrigger>
                    <PopoverContent
                        align="start"
                        className="w-auto max-w-[calc(100vw-2rem)] border-garis bg-permukaan p-2"
                        aria-label={`Pilih ${label}`}
                    >
                        {panel}
                        <div className="mt-2 flex justify-end gap-2 border-t border-garis pt-2">{tombol}</div>
                    </PopoverContent>
                </Popover>
            )}
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
