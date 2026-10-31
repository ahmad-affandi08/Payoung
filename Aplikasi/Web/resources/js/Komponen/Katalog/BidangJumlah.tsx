import { useId, useState, type ChangeEvent } from 'react';

import { InputGroup, InputGroupAddon, InputGroupInput, InputGroupText } from '@/Komponen/Ui/input-group';
import { Label } from '@/Komponen/Ui/label';
import { cn } from '@/Komponen/Ui/utils';
import {
    CekMasukanJumlahValid,
    FormatMasukanJumlah,
    NormalisasiMasukanJumlah,
    SkalaKuantitas,
} from '@/Pustaka/MasukanJumlah';

type PropsBidangJumlah = {
    label: string;
    /** String desimal polos seperti yang dikirim ke server ("1.5"), atau "" bila kosong. */
    nilai: string;
    saatBerubah: (nilai: string) => void;
    /** Digit pecahan yang boleh diketik: 4 untuk kuantitas, 0 untuk satuan tanpa desimal, 6 untuk persen. */
    desimal?: number;
    digitBulat?: number;
    /** Akhiran tampilan, misal "kg" atau "%". */
    akhiran?: string;
    galat?: string | undefined;
    keterangan?: string | undefined;
    disabled?: boolean;
    required?: boolean;
    labelTersembunyi?: boolean;
    /** Id isian, supaya bisa difokuskan dari luar (entri cepat barang). Bawaan: id otomatis. */
    id?: string;
    /**
     * Entri cepat: Enter di isian ini memanggil fungsi ini (misal pindah ke kotak cari produk) dan **tidak** mengirim
     * formulir. Isi isian juga terpilih semua saat fokus, jadi ketikan pertama menggantikan nilai bawaan.
     */
    saatEnter?: () => void;
};

/**
 * Input kuantitas/persen: tampil "1.250,5" (koma desimal, angka tabular, rata kanan), dikirim "1250.5".
 * Ketikan setengah jadi ("3,") dipertahankan selama fokus. Tidak ada number/float (CLAUDE.md #7).
 */
export default function BidangJumlah({
    label,
    nilai,
    saatBerubah,
    desimal = SkalaKuantitas,
    digitBulat = 14,
    akhiran,
    galat,
    keterangan,
    disabled,
    required,
    labelTersembunyi = false,
    id: idKustom,
    saatEnter,
}: PropsBidangJumlah) {
    const idOtomatis = useId();
    const id = idKustom ?? idOtomatis;
    const [teksKetik, AturTeksKetik] = useState<string | null>(null);
    const tampil = teksKetik ?? FormatMasukanJumlah(nilai).replace('−', '-');
    const opsi = { desimal, digitBulat };
    const dijelaskanOleh = [keterangan ? `${id}-keterangan` : null, galat ? `${id}-galat` : null]
        .filter(Boolean)
        .join(' ');

    const Ubah = (peristiwa: ChangeEvent<HTMLInputElement>) => {
        const mentah = peristiwa.target.value;

        if (!CekMasukanJumlahValid(mentah, opsi)) {
            return;
        }

        AturTeksKetik(mentah);
        saatBerubah(NormalisasiMasukanJumlah(mentah, opsi));
    };

    return (
        <div className="flex flex-col gap-1">
            <Label htmlFor={id} className={labelTersembunyi ? 'sr-only' : 'text-label font-semibold text-teks-utama'}>
                {label}
            </Label>
            <InputGroup
                data-disabled={disabled ? true : undefined}
                className={cn('h-8 pointer-coarse:h-11', disabled && 'bg-latar')}
            >
                <InputGroupInput
                    id={id}
                    type="text"
                    inputMode={desimal > 0 ? 'decimal' : 'numeric'}
                    autoComplete="off"
                    value={tampil}
                    onChange={Ubah}
                    onBlur={() => AturTeksKetik(null)}
                    onFocus={saatEnter ? (peristiwa) => peristiwa.currentTarget.select() : undefined}
                    onKeyDown={
                        saatEnter
                            ? (peristiwa) => {
                                  if (peristiwa.key === 'Enter') {
                                      peristiwa.preventDefault();
                                      saatEnter();
                                  }
                              }
                            : undefined
                    }
                    disabled={disabled}
                    required={required}
                    aria-invalid={galat ? true : undefined}
                    aria-describedby={dijelaskanOleh || undefined}
                    className="text-right text-isi text-teks-utama tabular-nums disabled:text-teks-sekunder"
                />
                {akhiran ? (
                    <InputGroupAddon align="inline-end" aria-hidden="true">
                        <InputGroupText className="text-isi font-normal text-teks-sekunder">{akhiran}</InputGroupText>
                    </InputGroupAddon>
                ) : null}
            </InputGroup>
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
