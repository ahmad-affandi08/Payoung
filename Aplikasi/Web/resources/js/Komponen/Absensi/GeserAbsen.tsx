import { ChevronsRightIcon } from 'lucide-react';
import { useRef, useState, type KeyboardEvent, type PointerEvent } from 'react';

import { Spinner } from '@/Komponen/Ui/spinner';

type PropsGeserAbsen = {
    /** Nama aksi untuk pembaca layar, mis. "Absen pulang". */
    nama: string;
    /** Teks petunjuk di jalur, mis. "Geser untuk absen pulang". */
    petunjuk: string;
    disabled: boolean;
    sibuk: boolean;
    /** Teks langkah yang sedang berjalan menggantikan petunjuk saat sibuk. */
    langkah: string | null;
    saatSelesai: () => void;
};

/** Seberapa jauh (0..1) pegangan harus digeser supaya dianggap sengaja. */
const AMBANG = 0.85;

/**
 * Tombol geser (D-337) untuk aksi yang tidak boleh tertekan tidak sengaja, yaitu absen pulang. Digeser sampai ujung
 * dengan jari atau kursor. Pengguna papan ketik dan pembaca layar cukup menekan Enter atau Spasi pada pegangan,
 * karena itu sudah tindakan yang disengaja. Ketukan biasa tidak melakukan apa pun.
 */
export default function GeserAbsen({ nama, petunjuk, disabled, sibuk, langkah, saatSelesai }: PropsGeserAbsen) {
    const jalur = useRef<HTMLDivElement>(null);
    const awal = useRef<{ x: number; geser: number } | null>(null);
    const [geser, AturGeser] = useState(0);
    const [menyeret, AturMenyeret] = useState(false);
    // Jarak geser maksimal (px), diukur saat jari menyentuh pegangan dan tidak dibaca dari ref saat render.
    const [maks, AturMaks] = useState(0);
    const mati = disabled || sibuk;

    const BatasMaksimal = (): number => {
        const lebar = jalur.current?.clientWidth ?? 0;
        // Pegangan 56px (size-14) ditambah celah 4px di tiap sisi.
        return Math.max(0, lebar - 56 - 8);
    };

    const Mulai = (kejadian: PointerEvent<HTMLButtonElement>) => {
        if (mati) {
            return;
        }

        kejadian.currentTarget.setPointerCapture(kejadian.pointerId);
        AturMaks(BatasMaksimal());
        awal.current = { x: kejadian.clientX, geser };
        AturMenyeret(true);
    };

    const Geser = (kejadian: PointerEvent<HTMLButtonElement>) => {
        if (awal.current === null) {
            return;
        }

        AturGeser(Math.min(BatasMaksimal(), Math.max(0, awal.current.geser + kejadian.clientX - awal.current.x)));
    };

    const Lepas = () => {
        if (awal.current === null) {
            return;
        }

        awal.current = null;
        AturMenyeret(false);
        const batas = BatasMaksimal();

        if (batas > 0 && geser >= batas * AMBANG) {
            AturGeser(batas);
            saatSelesai();
            // Pemindai wajah menutupi layar. Bila dibatalkan, pegangan kembali ke awal.
            window.setTimeout(() => AturGeser(0), 400);

            return;
        }

        AturGeser(0);
    };

    const Tombol = (kejadian: KeyboardEvent<HTMLButtonElement>) => {
        if (mati || (kejadian.key !== 'Enter' && kejadian.key !== ' ')) {
            return;
        }

        kejadian.preventDefault();
        saatSelesai();
    };

    const kemajuan = maks > 0 ? geser / maks : 0;

    return (
        <div
            ref={jalur}
            className={`relative flex h-16 touch-none items-center justify-center overflow-hidden rounded-full bg-brand-gelap-sorot text-brand-gelap-teks select-none ${
                mati ? 'opacity-60' : ''
            }`}
        >
            <span
                className="pl-14 text-isi font-semibold"
                style={{ opacity: sibuk ? 1 : Math.max(0, 1 - kemajuan * 1.6) }}
                aria-live="polite"
            >
                {langkah ?? petunjuk}
            </span>
            <button
                type="button"
                aria-label={nama}
                aria-describedby={undefined}
                disabled={mati}
                aria-busy={sibuk || undefined}
                onPointerDown={Mulai}
                onPointerMove={Geser}
                onPointerUp={Lepas}
                onPointerCancel={Lepas}
                onKeyDown={Tombol}
                style={{ transform: `translateX(${String(geser)}px)` }}
                className={`absolute top-1 left-1 grid size-14 place-items-center rounded-full bg-aksen text-brand-gelap focus-visible:ring-4 focus-visible:ring-aksen/50 focus-visible:outline-none ${
                    menyeret ? '' : 'transition-transform duration-200'
                }`}
            >
                {sibuk ? (
                    <Spinner role={undefined} aria-label={undefined} aria-hidden="true" className="size-6" />
                ) : (
                    <ChevronsRightIcon aria-hidden="true" className="size-6" />
                )}
            </button>
        </div>
    );
}
