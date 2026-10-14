import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import { CariTarget, type LangkahAktif } from '@/Pustaka/DefinisiTur';

type PropsBalon = {
    langkah: LangkahAktif;
    /** Urutan langkah ini mulai dari 0. */
    indeks: number;
    total: number;
    saatLanjut: () => void;
    saatKembali: () => void;
    saatLewati: () => void;
};

type Kotak = { atas: number; kiri: number; lebar: number; tinggi: number };

const JARAK = 12;
const TEPI = 16;
const LEBAR_BALON = 360;
const BATAS_HP = 640;
const PADDING_SOROT = 6;

function UkurTarget(elemen: HTMLElement): Kotak {
    const k = elemen.getBoundingClientRect();

    return {
        atas: k.top - PADDING_SOROT,
        kiri: k.left - PADDING_SOROT,
        lebar: k.width + PADDING_SOROT * 2,
        tinggi: k.height + PADDING_SOROT * 2,
    };
}

/** Letak balon di layar lebar: di bawah target bila muat, kalau tidak di atasnya, lalu di sisinya, terakhir di tengah. */
export function HitungPosisiBalon(
    sorot: Kotak | null,
    balon: { lebar: number; tinggi: number },
    layar: { lebar: number; tinggi: number },
): { atas: number; kiri: number } {
    const tengah = {
        atas: Math.max(TEPI, (layar.tinggi - balon.tinggi) / 2),
        kiri: Math.max(TEPI, (layar.lebar - balon.lebar) / 2),
    };

    if (sorot === null) {
        return tengah;
    }

    const kiri = Math.min(
        Math.max(sorot.kiri + sorot.lebar / 2 - balon.lebar / 2, TEPI),
        Math.max(TEPI, layar.lebar - balon.lebar - TEPI),
    );
    const bawah = sorot.atas + sorot.tinggi + JARAK;

    if (bawah + balon.tinggi <= layar.tinggi - TEPI) {
        return { atas: bawah, kiri };
    }

    const atas = sorot.atas - JARAK - balon.tinggi;

    if (atas >= TEPI) {
        return { atas, kiri };
    }

    // Target tinggi (menu samping): balon di sisinya, sejajar dengan bagian atas target.
    const sejajar = Math.min(Math.max(sorot.atas, TEPI), Math.max(TEPI, layar.tinggi - balon.tinggi - TEPI));
    const kanan = sorot.kiri + sorot.lebar + JARAK;

    if (kanan + balon.lebar <= layar.lebar - TEPI) {
        return { atas: sejajar, kiri: kanan };
    }

    const kiriTarget = sorot.kiri - JARAK - balon.lebar;

    if (kiriTarget >= TEPI) {
        return { atas: sejajar, kiri: kiriTarget };
    }

    return tengah;
}

/**
 * Satu langkah tur produk (PLT-12): kain penutup gelap dengan lubang di atas elemen yang disorot, dan balon penjelasan.
 * Kain penutup menahan klik ke halaman selama tur berjalan. Balon adalah dialog yang diumumkan pembaca layar; fokus
 * berpindah ke balon tiap pergantian langkah, Tab berputar di dalam balon, Esc melewati tur.
 */
export default function BalonTur({ langkah, indeks, total, saatLanjut, saatKembali, saatLewati }: PropsBalon) {
    const idJudul = useId();
    const idIsi = useId();
    const balonRef = useRef<HTMLElement>(null);
    const [terukur, AturTerukur] = useState<{ langkah: LangkahAktif; kotak: Kotak } | null>(null);
    const [posisi, AturPosisi] = useState<{ atas: number; kiri: number } | null>(null);
    const [layar, AturLayar] = useState(() => ({ lebar: window.innerWidth, tinggi: window.innerHeight }));
    // Hasil ukur milik langkah sebelumnya tidak boleh menyorot langkah ini (langkah tanpa target = di tengah layar).
    const sorot = terukur !== null && terukur.langkah === langkah ? terukur.kotak : null;
    const terakhir = indeks === total - 1;
    const sempit = layar.lebar < BATAS_HP;

    // Sorot target: gulirkan ke tengah, lalu ukur ulang tiap layar berubah ukuran atau halaman tergulir.
    useLayoutEffect(() => {
        const elemen = CariTarget(langkah.Target);

        if (elemen === null) {
            return;
        }

        elemen.scrollIntoView({ block: 'center', inline: 'nearest' });
        const Ukur = () => {
            AturTerukur({ langkah, kotak: UkurTarget(elemen) });
            AturLayar({ lebar: window.innerWidth, tinggi: window.innerHeight });
        };
        Ukur();
        window.addEventListener('resize', Ukur);
        window.addEventListener('scroll', Ukur, true);

        return () => {
            window.removeEventListener('resize', Ukur);
            window.removeEventListener('scroll', Ukur, true);
        };
    }, [langkah]);

    // Letak balon dihitung dari ukurannya sendiri; di HP balon menempel di tepi atas/bawah (diatur kelas).
    useLayoutEffect(() => {
        const balon = balonRef.current;

        if (balon === null || sempit) {
            AturPosisi(null);

            return;
        }

        AturPosisi(HitungPosisiBalon(sorot, { lebar: balon.offsetWidth, tinggi: balon.offsetHeight }, layar));
    }, [sorot, layar, sempit, langkah]);

    useEffect(() => {
        balonRef.current?.focus();
    }, [langkah]);

    const TanganiTombol = (peristiwa: React.KeyboardEvent<HTMLElement>) => {
        if (peristiwa.key === 'Escape') {
            peristiwa.stopPropagation();
            saatLewati();

            return;
        }

        if (peristiwa.key !== 'Tab') {
            return;
        }

        const dapatDifokus = Array.from(
            balonRef.current?.querySelectorAll<HTMLElement>('button:not([disabled])') ?? [],
        );
        const pertama = dapatDifokus[0];
        const akhir = dapatDifokus[dapatDifokus.length - 1];

        if (pertama === undefined || akhir === undefined) {
            return;
        }

        if (peristiwa.shiftKey && (document.activeElement === pertama || document.activeElement === balonRef.current)) {
            peristiwa.preventDefault();
            akhir.focus();
        } else if (!peristiwa.shiftKey && document.activeElement === akhir) {
            peristiwa.preventDefault();
            pertama.focus();
        }
    };

    const bawahLayar = sorot === null ? true : sorot.atas + sorot.tinggi / 2 > layar.tinggi / 2;
    const kelasPenutup = 'fixed bg-teks-utama/60';

    return (
        <div data-slot="tur" className="fixed inset-0 z-[60]">
            {sorot === null ? (
                <div aria-hidden="true" className={`${kelasPenutup} inset-0`} />
            ) : (
                <>
                    <div
                        aria-hidden="true"
                        className={`${kelasPenutup} inset-x-0 top-0`}
                        style={{ height: Math.max(0, sorot.atas) }}
                    />
                    <div
                        aria-hidden="true"
                        className={`${kelasPenutup} inset-x-0 bottom-0`}
                        style={{ top: Math.max(0, sorot.atas + sorot.tinggi) }}
                    />
                    <div
                        aria-hidden="true"
                        className={`${kelasPenutup} left-0`}
                        style={{ top: sorot.atas, height: sorot.tinggi, width: Math.max(0, sorot.kiri) }}
                    />
                    <div
                        aria-hidden="true"
                        className={`${kelasPenutup} right-0`}
                        style={{ top: sorot.atas, height: sorot.tinggi, left: Math.max(0, sorot.kiri + sorot.lebar) }}
                    />
                    <div
                        aria-hidden="true"
                        className="fixed rounded-lg border-2 border-brand"
                        style={{ top: sorot.atas, left: sorot.kiri, width: sorot.lebar, height: sorot.tinggi }}
                    />
                </>
            )}
            <section
                ref={balonRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby={idJudul}
                aria-describedby={idIsi}
                tabIndex={-1}
                onKeyDown={TanganiTombol}
                style={
                    sempit || posisi === null
                        ? undefined
                        : { top: posisi.atas, left: posisi.kiri, width: Math.min(LEBAR_BALON, layar.lebar - TEPI * 2) }
                }
                className={
                    sempit
                        ? `fixed inset-x-0 flex flex-col gap-3 border-garis bg-permukaan px-4 pt-4 focus-visible:outline-none ${bawahLayar ? 'top-0 rounded-b-panel border-b pb-4' : 'tepi-bawah-aman bottom-0 rounded-t-panel border-t'}`
                        : `fixed flex flex-col gap-3 rounded-panel border border-garis bg-permukaan p-4 focus-visible:outline-none ${posisi === null ? 'invisible' : ''}`
                }
            >
                <p className="text-keterangan text-teks-sekunder">
                    Langkah {String(indeks + 1)} dari {String(total)}
                </p>
                <h2 id={idJudul} className="text-subjudul font-semibold text-teks-utama">
                    {langkah.Judul}
                </h2>
                <p id={idIsi} className="text-isi text-teks-sekunder">
                    {langkah.Isi}
                </p>
                <div className="flex flex-wrap items-center gap-2">
                    {terakhir ? null : (
                        <Tombol varian="sekunder" onClick={saatLewati}>
                            Lewati tur
                        </Tombol>
                    )}
                    <div className="ml-auto flex items-center gap-2">
                        {indeks > 0 ? (
                            <Tombol varian="sekunder" onClick={saatKembali}>
                                Kembali
                            </Tombol>
                        ) : null}
                        <Tombol onClick={saatLanjut}>{terakhir ? 'Selesai' : 'Lanjut'}</Tombol>
                    </div>
                </div>
            </section>
        </div>
    );
}
