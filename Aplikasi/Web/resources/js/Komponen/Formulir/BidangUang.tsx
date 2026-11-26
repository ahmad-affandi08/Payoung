import { useId, useLayoutEffect, useRef, type ChangeEvent } from 'react';

import { InputGroup, InputGroupAddon, InputGroupInput } from '@/Komponen/Ui/input-group';
import { cn } from '@/Komponen/Ui/utils';
import {
    CekMasukanUangValid,
    FormatMasukanUang,
    HitungDigitSebelumKursor,
    HitungPosisiKursor,
    NormalisasiMasukanUang,
} from '@/Pustaka/MasukanUang';

import { GabungDijelaskanOleh, GalatBidang, KerangkaBidang, KeteranganBidang, LabelBidang } from './BagianBidang';

type PropsBidangUang = {
    label: string;
    /** String desimal polos seperti yang dikirim ke server ("15000"), atau "" bila kosong. */
    nilai: string;
    saatBerubah: (nilai: string) => void;
    galat?: string | undefined;
    keterangan?: string;
    disabled?: boolean;
    required?: boolean;
    /** Label hanya untuk pembaca layar, misal di dalam baris tabel yang judul kolomnya sudah "Harga". */
    labelTersembunyi?: boolean;
};

/**
 * Input Rupiah: tampil "Rp 15.000" (titik ribuan, angka tabular, rata kanan), dikirim "15000".
 * Karakter yang tidak valid diabaikan; tidak ada perhitungan number/float (CLAUDE.md #7).
 */
export default function BidangUang({
    label,
    nilai,
    saatBerubah,
    galat,
    keterangan,
    disabled,
    required,
    labelTersembunyi = false,
}: PropsBidangUang) {
    const id = useId();
    const masukan = useRef<HTMLInputElement>(null);
    const digitKursor = useRef<number | null>(null);
    const tampil = FormatMasukanUang(nilai);
    const dijelaskanOleh = GabungDijelaskanOleh(keterangan && `${id}-keterangan`, galat && `${id}-galat`);

    useLayoutEffect(() => {
        const elemen = masukan.current;

        if (digitKursor.current === null || elemen === null || document.activeElement !== elemen) {
            return;
        }

        const posisi = HitungPosisiKursor(elemen.value, digitKursor.current);
        elemen.setSelectionRange(posisi, posisi);
        digitKursor.current = null;
    }, [tampil]);

    const Ubah = (peristiwa: ChangeEvent<HTMLInputElement>) => {
        const mentah = peristiwa.target.value;

        if (!CekMasukanUangValid(mentah)) {
            return;
        }

        digitKursor.current = HitungDigitSebelumKursor(mentah, peristiwa.target.selectionStart ?? mentah.length);
        saatBerubah(NormalisasiMasukanUang(mentah));
    };

    return (
        <KerangkaBidang galat={galat}>
            <LabelBidang htmlFor={id} tersembunyi={labelTersembunyi}>
                {label}
            </LabelBidang>
            <InputGroup
                data-disabled={disabled ? true : undefined}
                className={cn(
                    'h-8 pointer-coarse:h-11 bg-permukaan',
                    galat ? 'border-bahaya' : 'border-garis-input',
                    disabled && 'bg-latar shadow-none',
                )}
            >
                <InputGroupAddon aria-hidden="true" className="text-isi font-normal text-teks-sekunder">
                    Rp
                </InputGroupAddon>
                <InputGroupInput
                    ref={masukan}
                    id={id}
                    type="text"
                    inputMode="numeric"
                    autoComplete="off"
                    value={tampil}
                    onChange={Ubah}
                    disabled={disabled}
                    required={required}
                    aria-invalid={galat ? true : undefined}
                    aria-describedby={dijelaskanOleh}
                    className="h-full text-right text-isi text-teks-utama tabular-nums disabled:text-teks-sekunder disabled:opacity-100"
                />
            </InputGroup>
            {keterangan ? <KeteranganBidang id={`${id}-keterangan`}>{keterangan}</KeteranganBidang> : null}
            {galat ? <GalatBidang id={`${id}-galat`}>{galat}</GalatBidang> : null}
        </KerangkaBidang>
    );
}
