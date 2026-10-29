import { ChevronLeftIcon, ChevronRightIcon, ChevronsLeftIcon, ChevronsRightIcon } from 'lucide-react';

import { Button } from '@/Komponen/Ui/button';
import { Label } from '@/Komponen/Ui/label';

import { UKURAN_HALAMAN, type MetaTabel } from './Tipe';
import PilihanCari from '@/Komponen/Formulir/PilihanCari';

type PropsPaginasi = {
    label: string;
    meta: MetaTabel;
    jumlahBaris: number;
    AturHalaman: (halaman: number) => void;
    AturPerHalaman: (perHalaman: number) => void;
};

const angka = new Intl.NumberFormat('id-ID');

/** Paginasi server `TabelData`: rentang baris, ukuran halaman (25/50/100), dan navigasi halaman. */
export default function PaginasiTabel({ label, meta, jumlahBaris, AturHalaman, AturPerHalaman }: PropsPaginasi) {
    const awal = meta.Total === 0 ? 0 : (meta.Halaman - 1) * meta.PerHalaman + 1;
    const akhir = meta.Total === 0 ? 0 : awal + jumlahBaris - 1;
    const kelasTombol = 'size-11 sm:size-9';

    return (
        <nav
            aria-label={`Halaman ${label}`}
            className="flex flex-col gap-3 text-label text-teks-sekunder sm:flex-row sm:items-center sm:justify-between"
        >
            <p aria-live="polite">
                Menampilkan{' '}
                <span className="font-semibold text-teks-utama tabular-nums">
                    {angka.format(awal)}–{angka.format(akhir)}
                </span>{' '}
                dari <span className="font-semibold text-teks-utama tabular-nums">{angka.format(meta.Total)}</span>
            </p>
            <div className="flex flex-wrap items-center gap-3">
                <div className="flex items-center gap-2">
                    <Label htmlFor={`per-halaman-${label}`} className="text-label font-normal text-teks-sekunder">
                        Baris per halaman
                    </Label>
                    <PilihanCari
                        id={`per-halaman-${label}`}
                        label="Baris per halaman"
                        nilai={String(meta.PerHalaman)}
                        opsi={UKURAN_HALAMAN.map((ukuran) => ({ Nilai: String(ukuran), Label: String(ukuran) }))}
                        saatBerubah={(nilai) => AturPerHalaman(Number(nilai))}
                        className="h-11 w-20 sm:h-9"
                    />
                </div>
                <div className="flex items-center gap-1">
                    <Button
                        type="button"
                        size="icon"
                        variant="outline"
                        className={kelasTombol}
                        disabled={meta.Halaman <= 1}
                        onClick={() => AturHalaman(1)}
                        aria-label="Halaman pertama"
                    >
                        <ChevronsLeftIcon aria-hidden="true" />
                    </Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="outline"
                        className={kelasTombol}
                        disabled={meta.Halaman <= 1}
                        onClick={() => AturHalaman(meta.Halaman - 1)}
                        aria-label="Halaman sebelumnya"
                    >
                        <ChevronLeftIcon aria-hidden="true" />
                    </Button>
                    <span className="px-2 whitespace-nowrap text-teks-utama tabular-nums">
                        Halaman {angka.format(meta.Halaman)} dari {angka.format(meta.JumlahHalaman)}
                    </span>
                    <Button
                        type="button"
                        size="icon"
                        variant="outline"
                        className={kelasTombol}
                        disabled={meta.Halaman >= meta.JumlahHalaman}
                        onClick={() => AturHalaman(meta.Halaman + 1)}
                        aria-label="Halaman berikutnya"
                    >
                        <ChevronRightIcon aria-hidden="true" />
                    </Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="outline"
                        className={kelasTombol}
                        disabled={meta.Halaman >= meta.JumlahHalaman}
                        onClick={() => AturHalaman(meta.JumlahHalaman)}
                        aria-label="Halaman terakhir"
                    >
                        <ChevronsRightIcon aria-hidden="true" />
                    </Button>
                </div>
            </div>
        </nav>
    );
}
