import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import Tombol from '@/Komponen/Formulir/Tombol';
import { SamaDenganPersen } from '@/Pustaka/HargaOtomatis';

type PropsPilihanCepatPersen = {
    label: string;
    nilai: string;
    saatBerubah: (nilai: string) => void;
    /** Persen yang bisa dipilih dengan satu ketukan. */
    pilihan: number[];
    digitBulat?: number;
    galat?: string | undefined;
    keterangan?: string;
    disabled?: boolean;
    required?: boolean;
};

/**
 * Isian persen dengan pilihan cepat (D-268): pemilik toko yang tidak terbiasa mengetik angka cukup mengetuk 10, 20, atau
 * 30 persen, yang mau angka lain tetap bisa mengetiknya. Pilihan yang sama dengan isian tampil terpilih.
 */
export default function PilihanCepatPersen({
    label,
    nilai,
    saatBerubah,
    pilihan,
    digitBulat = 4,
    galat,
    keterangan,
    disabled = false,
    required = false,
}: PropsPilihanCepatPersen) {
    return (
        <div className="flex flex-col gap-2">
            <BidangJumlah
                label={label}
                nilai={nilai}
                saatBerubah={saatBerubah}
                desimal={2}
                digitBulat={digitBulat}
                akhiran="%"
                galat={galat}
                disabled={disabled}
                required={required}
                {...(keterangan === undefined ? {} : { keterangan })}
            />
            <div role="group" aria-label={`Pilihan cepat ${label.toLowerCase()}`} className="flex flex-wrap gap-2">
                {pilihan.map((persen) => {
                    const terpilih = SamaDenganPersen(nilai, persen);

                    return (
                        <Tombol
                            key={persen}
                            varian={terpilih ? 'utama' : 'sekunder'}
                            aria-pressed={terpilih}
                            disabled={disabled}
                            onClick={() => saatBerubah(String(persen))}
                        >
                            {persen}%
                        </Tombol>
                    );
                })}
            </div>
        </div>
    );
}
