import { useId } from 'react';

import { Label } from '@/Komponen/Ui/label';
import { RadioGroup, RadioGroupItem } from '@/Komponen/Ui/radio-group';
import type { ArahHargaOtomatis } from '@/Tipe/Katalog';

const Opsi: { Nilai: ArahHargaOtomatis; Judul: string; Keterangan: string }[] = [
    { Nilai: 'NaikTurun', Judul: 'Harga jual ikut turun', Keterangan: 'Cocok kalau harga beli Anda cukup stabil.' },
    {
        Nilai: 'HanyaNaik',
        Judul: 'Harga jual tetap',
        Keterangan: 'Aman dari harga beli yang turun sementara, misalnya karena promo pemasok.',
    },
];

type PropsKartuArahHarga = {
    nilai: ArahHargaOtomatis;
    saatBerubah: (nilai: ArahHargaOtomatis) => void;
    disabled?: boolean;
};

/** Dua kartu pilihan "kalau harga beli turun" (D-268), masing-masing dengan satu kalimat penjelasan. */
export default function KartuArahHarga({ nilai, saatBerubah, disabled = false }: PropsKartuArahHarga) {
    const id = useId();

    return (
        <RadioGroup
            value={nilai}
            disabled={disabled}
            aria-label="Kalau harga beli turun"
            className="grid gap-2 sm:grid-cols-2"
            onValueChange={(baru) => {
                const pilihan = Opsi.find((o) => o.Nilai === baru);

                if (pilihan !== undefined) {
                    saatBerubah(pilihan.Nilai);
                }
            }}
        >
            {Opsi.map((o) => (
                <Label
                    key={o.Nilai}
                    htmlFor={`${id}-${o.Nilai}`}
                    className="flex cursor-pointer items-start gap-3 rounded-panel border border-garis-input p-3 font-normal has-data-[state=checked]:border-brand has-data-[state=checked]:bg-brand-lembut"
                >
                    <RadioGroupItem id={`${id}-${o.Nilai}`} value={o.Nilai} className="mt-0.5" />
                    <span className="flex flex-col gap-0.5">
                        <span className="text-isi font-semibold text-teks-utama">{o.Judul}</span>
                        <span className="text-keterangan text-teks-sekunder">{o.Keterangan}</span>
                    </span>
                </Label>
            ))}
        </RadioGroup>
    );
}
