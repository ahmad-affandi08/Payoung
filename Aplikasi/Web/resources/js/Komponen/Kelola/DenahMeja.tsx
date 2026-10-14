import { router } from '@inertiajs/react';
import { useRef, useState, type KeyboardEvent, type PointerEvent } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';

import type { Meja } from './BagianMeja';

/** Sisi kanvas relatif: letak meja disimpan 0–BATAS_KANVAS di kedua sumbu (sama dengan batas server). */
export const BATAS_KANVAS = 1000;
const LANGKAH_RAPAT = 10;
const LANGKAH_KEYBOARD = 10;
const LANGKAH_KEYBOARD_BESAR = 50;

type Titik = { x: number; y: number };
type Letak = Record<string, Titik | null>;

/** Menjepit nilai ke 0–BATAS_KANVAS dan merapatkannya ke kelipatan 10 supaya denah rapi. */
export function RapatkanKeKanvas(nilai: number): number {
    const dijepit = Math.min(BATAS_KANVAS, Math.max(0, nilai));

    return Math.round(dijepit / LANGKAH_RAPAT) * LANGKAH_RAPAT;
}

/** Titik kanvas relatif dari koordinat pointer dan kotak kanvas; kotak tanpa ukuran dianggap titik tengah. */
export function HitungTitikKanvas(
    klienX: number,
    klienY: number,
    kotak: Pick<DOMRect, 'left' | 'top' | 'width' | 'height'>,
): Titik {
    if (kotak.width <= 0 || kotak.height <= 0) {
        return { x: BATAS_KANVAS / 2, y: BATAS_KANVAS / 2 };
    }

    return {
        x: RapatkanKeKanvas(((klienX - kotak.left) / kotak.width) * BATAS_KANVAS),
        y: RapatkanKeKanvas(((klienY - kotak.top) / kotak.height) * BATAS_KANVAS),
    };
}

function AwalLetak(meja: Meja[]): Letak {
    return Object.fromEntries(
        meja.map((m) => [
            m.Uuid,
            m.PosisiX === null || m.PosisiX === undefined || m.PosisiY === null || m.PosisiY === undefined
                ? null
                : { x: m.PosisiX, y: m.PosisiY },
        ]),
    );
}

const kelasBentuk: Record<string, string> = {
    Persegi: 'h-14 w-14 rounded-md',
    Bundar: 'h-14 w-14 rounded-full',
    Panjang: 'h-12 w-24 rounded-md',
};

type PropsDenahMeja = {
    alamatOutlet: string;
    /** Meja aktif outlet; yang diarsipkan tidak ikut denah. */
    meja: Meja[];
    bolehKelola: boolean;
};

/**
 * POS-03: denah meja outlet. Bacaan saja bagi yang hanya boleh melihat; yang boleh mengelola menyeret meja ke posisinya
 * (pointer, sentuh, atau panah keyboard; Shift = loncat jauh) lalu menyimpan sekali. Meja yang belum ditaruh ada di
 * baris "Belum ditata" dan masuk denah lewat satu ketukan; Hapus/Backspace mengembalikan meja terpilih ke baris itu.
 * Kasir memakai denah yang sama di layar Meja (tanpa denah, kasir tetap melihat daftar biasa).
 */
export default function DenahMeja({ alamatOutlet, meja, bolehKelola }: PropsDenahMeja) {
    const aktif = meja.filter((m) => m.Status === 'Aktif');
    const [letak, AturLetak] = useState<Letak>(() => AwalLetak(aktif));
    const [mengatur, AturMengatur] = useState(false);
    const [terpilih, AturTerpilih] = useState<string | null>(null);
    const [menyimpan, AturMenyimpan] = useState(false);
    const kanvas = useRef<HTMLDivElement>(null);
    const diseret = useRef<string | null>(null);

    const tertata = aktif.filter((m) => letak[m.Uuid] !== null && letak[m.Uuid] !== undefined);
    const belumDitata = aktif.filter((m) => letak[m.Uuid] === null || letak[m.Uuid] === undefined);

    if (aktif.length === 0) {
        return null;
    }

    const Taruh = (uuid: string, titik: Titik | null) => AturLetak((lama) => ({ ...lama, [uuid]: titik }));

    const SaatTekan = (uuid: string, kejadian: PointerEvent<HTMLButtonElement>) => {
        if (!mengatur) {
            return;
        }

        diseret.current = uuid;
        AturTerpilih(uuid);
        kejadian.currentTarget.setPointerCapture?.(kejadian.pointerId);
    };

    const SaatGeser = (kejadian: PointerEvent<HTMLButtonElement>) => {
        const uuid = diseret.current;
        const elemen = kanvas.current;

        if (uuid === null || elemen === null) {
            return;
        }

        Taruh(uuid, HitungTitikKanvas(kejadian.clientX, kejadian.clientY, elemen.getBoundingClientRect()));
    };

    const SaatLepas = () => {
        diseret.current = null;
    };

    const SaatTombol = (uuid: string, kejadian: KeyboardEvent<HTMLButtonElement>) => {
        if (!mengatur) {
            return;
        }

        const titik = letak[uuid];
        const langkah = kejadian.shiftKey ? LANGKAH_KEYBOARD_BESAR : LANGKAH_KEYBOARD;
        const arah: Record<string, Titik> = {
            ArrowLeft: { x: -langkah, y: 0 },
            ArrowRight: { x: langkah, y: 0 },
            ArrowUp: { x: 0, y: -langkah },
            ArrowDown: { x: 0, y: langkah },
        };

        if ((kejadian.key === 'Delete' || kejadian.key === 'Backspace') && titik) {
            kejadian.preventDefault();
            Taruh(uuid, null);
            AturTerpilih(null);

            return;
        }

        const geser = arah[kejadian.key];

        if (geser !== undefined && titik) {
            kejadian.preventDefault();
            Taruh(uuid, { x: RapatkanKeKanvas(titik.x + geser.x), y: RapatkanKeKanvas(titik.y + geser.y) });
        }
    };

    const Simpan = () => {
        AturMenyimpan(true);
        router.put(
            `${alamatOutlet}/denah-meja`,
            {
                Meja: aktif.map((m) => ({
                    Uuid: m.Uuid,
                    PosisiX: letak[m.Uuid]?.x ?? null,
                    PosisiY: letak[m.Uuid]?.y ?? null,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: () => AturMengatur(false),
                onFinish: () => AturMenyimpan(false),
            },
        );
    };

    const Batal = () => {
        AturLetak(AwalLetak(aktif));
        AturTerpilih(null);
        AturMengatur(false);
    };

    return (
        <section className="flex flex-col gap-3" aria-labelledby="judul-denah-meja">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 id="judul-denah-meja" className="text-isi font-semibold text-teks-utama">
                    Denah meja
                </h3>
                {bolehKelola ? (
                    mengatur ? (
                        <div className="flex flex-wrap gap-2">
                            <Tombol varian="sekunder" onClick={Batal} disabled={menyimpan}>
                                Batal
                            </Tombol>
                            <Tombol onClick={Simpan} memproses={menyimpan}>
                                Simpan denah
                            </Tombol>
                        </div>
                    ) : (
                        <Tombol varian="sekunder" onClick={() => AturMengatur(true)}>
                            Atur denah
                        </Tombol>
                    )
                ) : null}
            </div>
            <p className="text-keterangan text-teks-sekunder">
                {mengatur
                    ? 'Seret meja ke tempatnya, atau pilih meja lalu tekan tombol panah (Shift untuk loncat jauh). Hapus mengembalikan meja ke baris "Belum ditata".'
                    : 'Letak meja seperti yang tampil di layar Meja kasir. Meja yang belum ditata tetap muncul sebagai daftar di kasir.'}
            </p>
            {tertata.length === 0 && !mengatur ? (
                <Pemberitahuan jenis="info" judul="Denah belum ditata">
                    Tekan Atur denah untuk menaruh meja pada tempatnya. Selama denah kosong, kasir melihat daftar meja
                    per area.
                </Pemberitahuan>
            ) : (
                <div
                    ref={kanvas}
                    role="group"
                    aria-label="Kanvas denah meja"
                    className="relative aspect-[16/10] w-full touch-none overflow-hidden rounded-panel border border-garis bg-permukaan-redup"
                >
                    {tertata.map((m) => {
                        const titik = letak[m.Uuid];

                        if (!titik) {
                            return null;
                        }

                        const dipilih = terpilih === m.Uuid;

                        return (
                            <button
                                key={m.Uuid}
                                type="button"
                                disabled={!mengatur}
                                aria-label={`Meja ${m.Nama}, ${String(m.Kapasitas)} orang${mengatur ? ', seret atau tekan panah untuk memindah' : ''}`}
                                aria-pressed={mengatur ? dipilih : undefined}
                                onPointerDown={(k) => SaatTekan(m.Uuid, k)}
                                onPointerMove={SaatGeser}
                                onPointerUp={SaatLepas}
                                onPointerCancel={SaatLepas}
                                onKeyDown={(k) => SaatTombol(m.Uuid, k)}
                                onFocus={() => mengatur && AturTerpilih(m.Uuid)}
                                style={{ left: `${String(titik.x / 10)}%`, top: `${String(titik.y / 10)}%` }}
                                className={`absolute flex -translate-x-1/2 -translate-y-1/2 touch-none select-none flex-col items-center justify-center border-2 text-label font-semibold text-teks-utama disabled:cursor-default focus-visible:ring-2 focus-visible:ring-brand ${kelasBentuk[m.Bentuk] ?? kelasBentuk.Persegi} ${dipilih ? 'border-brand bg-brand-lembut-sorot' : 'border-garis-input bg-permukaan'} ${mengatur ? 'cursor-grab active:cursor-grabbing' : ''}`}
                            >
                                <span className="max-w-full truncate px-1">{m.Nama}</span>
                                <span className="text-keterangan font-normal text-teks-sekunder">
                                    {String(m.Kapasitas)} org
                                </span>
                            </button>
                        );
                    })}
                </div>
            )}
            {belumDitata.length > 0 ? (
                <div className="flex flex-col gap-2">
                    <h4 className="text-label font-semibold text-teks-sekunder">
                        Belum ditata ({String(belumDitata.length)})
                    </h4>
                    <div className="flex flex-wrap gap-2">
                        {belumDitata.map((m) =>
                            mengatur ? (
                                <Tombol
                                    key={m.Uuid}
                                    varian="sekunder"
                                    aria-label={`Taruh meja ${m.Nama} di denah`}
                                    onClick={() => {
                                        Taruh(m.Uuid, { x: BATAS_KANVAS / 2, y: BATAS_KANVAS / 2 });
                                        AturTerpilih(m.Uuid);
                                    }}
                                >
                                    {m.Nama}
                                </Tombol>
                            ) : (
                                <span
                                    key={m.Uuid}
                                    className="rounded-panel border border-garis px-3 py-1 text-label text-teks-sekunder"
                                >
                                    {m.Nama}
                                </span>
                            ),
                        )}
                    </div>
                </div>
            ) : null}
        </section>
    );
}
