import { useId, useRef } from 'react';

import { Button } from '@/Komponen/Ui/button';
import { Input } from '@/Komponen/Ui/input';
import { FormatUkuranBerkas } from '@/Pustaka/FormatUkuran';

import {
    BuatKelasKontrol,
    GabungDijelaskanOleh,
    GalatBidang,
    KerangkaBidang,
    KeteranganBidang,
    LabelBidang,
} from './BagianBidang';

type PropsBidangBerkas = {
    label: string;
    berkas: File[];
    saatBerubah: (berkas: File[]) => void;
    ekstensi: string[];
    maksimal: number;
    ukuranMaksimalKb: number;
    galat?: string | undefined;
    /** Berkas wajib diisi: keterangan tidak lagi bertuliskan "Opsional". */
    wajib?: boolean;
};

/** Pilih beberapa berkas lampiran dengan batas jumlah, jenis, dan ukuran yang disebutkan jelas (PRD §17.6.7). */
export default function BidangBerkas({
    label,
    berkas,
    saatBerubah,
    ekstensi,
    maksimal,
    ukuranMaksimalKb,
    galat,
    wajib = false,
}: PropsBidangBerkas) {
    const id = useId();
    const masukan = useRef<HTMLInputElement>(null);
    const keterangan = `${wajib ? 'Wajib diisi.' : 'Opsional.'} Maksimal ${maksimal} berkas, masing-masing ${FormatUkuranBerkas(ukuranMaksimalKb * 1024)} (${ekstensi.join(', ')}).`;
    const Hapus = (indeks: number) => {
        saatBerubah(berkas.filter((_, i) => i !== indeks));

        if (masukan.current) {
            masukan.current.value = '';
        }
    };

    return (
        <KerangkaBidang galat={galat}>
            <LabelBidang htmlFor={id}>{label}</LabelBidang>
            <Input
                ref={masukan}
                id={id}
                type="file"
                multiple
                accept={ekstensi.map((nilai) => `.${nilai}`).join(',')}
                onChange={(peristiwa) =>
                    saatBerubah([...berkas, ...Array.from(peristiwa.target.files ?? [])].slice(0, maksimal))
                }
                aria-invalid={galat ? true : undefined}
                aria-describedby={GabungDijelaskanOleh(`${id}-keterangan`, galat && `${id}-galat`)}
                className={BuatKelasKontrol(
                    galat,
                    'cursor-pointer py-1.5 file:mr-3 file:text-label file:font-semibold file:text-teks-utama',
                )}
            />
            <KeteranganBidang id={`${id}-keterangan`}>{keterangan}</KeteranganBidang>
            {berkas.length > 0 ? (
                <ul className="flex flex-col gap-1">
                    {berkas.map((file, indeks) => (
                        <li
                            key={`${file.name}-${String(indeks)}`}
                            className="flex items-center justify-between gap-2 text-keterangan"
                        >
                            <span className="break-all text-teks-utama">
                                {file.name} | {FormatUkuranBerkas(file.size)}
                            </span>
                            <Button
                                type="button"
                                variant="link"
                                onClick={() => Hapus(indeks)}
                                className="h-auto p-0 text-keterangan font-semibold text-bahaya underline"
                            >
                                Hapus
                            </Button>
                        </li>
                    ))}
                </ul>
            ) : null}
            {galat ? <GalatBidang id={`${id}-galat`}>{galat}</GalatBidang> : null}
        </KerangkaBidang>
    );
}
