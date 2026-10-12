import { useMemo } from 'react';

import { AmbilBarcodeCetak, type BarisCetak } from '@/Komponen/Katalog/Label/BantuanLabel';
import StikerLabel from '@/Komponen/Katalog/Label/StikerLabel';
import {
    HitungTataIsi,
    type IsiLabel,
    type MediaLabel,
    type TataHalaman,
    type TataIsiLabel,
} from '@/Pustaka/UkuranLabel';

type PropsLembarLabel = {
    /** Halaman yang digambar: tiap sel = indeks ke `baris`, atau null (kosong). */
    halaman: readonly (readonly (number | null)[])[];
    baris: readonly BarisCetak[];
    isi: IsiLabel;
    media: MediaLabel;
    tata: TataHalaman;
    /** Pratinjau di layar: halaman diberi bingkai dan tanpa pemisah halaman cetak. */
    pratinjau?: boolean;
};

/** Selisih kecil agar pembulatan peramban tidak melahirkan halaman kosong ekstra saat dicetak. */
const longgarMm = 0.3;

/** Tata isi untuk stiker berbarcode dan tanpa barcode (dihitung sekali per lembar, bukan per stiker). */
export function HitungDuaTataIsi(media: MediaLabel, isi: IsiLabel): { Barcode: TataIsiLabel; Polos: TataIsiLabel } {
    return { Barcode: HitungTataIsi(media.TinggiMm, isi, true), Polos: HitungTataIsi(media.TinggiMm, isi, false) };
}

/**
 * PRD-10: lembar label. Setiap halaman berukuran persis kertas (A4, atau seukuran stiker untuk label gulung); stiker
 * ditata dalam kisi berukuran milimeter. Dipakai untuk pratinjau layar dan untuk area cetak.
 */
export default function LembarLabel({ halaman, baris, isi, media, tata, pratinjau = false }: PropsLembarLabel) {
    const tataIsi = useMemo(() => HitungDuaTataIsi(media, isi), [media, isi]);

    return (
        <>
            {halaman.map((sel, nomor) => (
                <section
                    key={nomor}
                    data-slot="halaman-label"
                    aria-label={`Halaman label ${String(nomor + 1)}`}
                    style={{
                        position: 'relative',
                        overflow: 'hidden',
                        boxSizing: 'border-box',
                        width: `${String(tata.LebarHalamanMm)}mm`,
                        height: `${String(tata.TinggiHalamanMm - (pratinjau ? 0 : longgarMm))}mm`,
                        background: 'var(--color-permukaan)',
                        color: 'var(--color-teks-utama)',
                        breakAfter: pratinjau || nomor === halaman.length - 1 ? 'auto' : 'page',
                        pageBreakAfter: pratinjau || nomor === halaman.length - 1 ? 'auto' : 'always',
                    }}
                >
                    <div
                        style={{
                            position: 'absolute',
                            left: `${String(tata.KiriMm)}mm`,
                            top: `${String(tata.AtasMm)}mm`,
                            display: 'grid',
                            gridTemplateColumns: `repeat(${String(media.Kolom)}, ${String(media.LebarMm)}mm)`,
                            gridAutoRows: `${String(media.TinggiMm)}mm`,
                            columnGap: `${String(media.JarakXMm)}mm`,
                            rowGap: `${String(media.JarakYMm)}mm`,
                        }}
                    >
                        {sel.map((indeks, posisi) => {
                            const b = indeks === null ? undefined : baris[indeks];

                            return b === undefined ? (
                                <div key={posisi} />
                            ) : (
                                <StikerLabel
                                    key={posisi}
                                    baris={b}
                                    isi={isi}
                                    media={media}
                                    tata={
                                        isi.Barcode && AmbilBarcodeCetak(b) !== null ? tataIsi.Barcode : tataIsi.Polos
                                    }
                                />
                            );
                        })}
                    </div>
                </section>
            ))}
        </>
    );
}
