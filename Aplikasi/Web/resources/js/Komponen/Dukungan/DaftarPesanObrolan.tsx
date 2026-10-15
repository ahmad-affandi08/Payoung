import { useEffect, useRef } from 'react';

import { cn } from '@/Komponen/Ui/utils';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { PesanObrolan } from '@/Pustaka/Obrolan';

type PropsDaftarPesanObrolan = {
    pesan: PesanObrolan[];
    /** Sisi yang sedang membaca: pesannya sendiri rata kanan dan berlatar lembut. */
    sebagai: 'Pelapor' | 'Petugas';
    /** Nama wilayah untuk pembaca layar. */
    label?: string;
    className?: string;
};

/**
 * Daftar pesan obrolan langsung (PLT-12). Wilayah `log` yang dibacakan sopan (`aria-live="polite"`): pesan baru tidak
 * memotong pembacaan. Pengirim selalu ditulis sebagai teks (bukan hanya sisi/warna), pesan sistem ringkas di tengah.
 * Menggulir ke pesan terakhir hanya bila pembaca memang sudah berada di dasar daftar, jadi tidak merebut gulir saat
 * ia sedang membaca pesan lama.
 */
export default function DaftarPesanObrolan({
    pesan,
    sebagai,
    label = 'Percakapan obrolan',
    className,
}: PropsDaftarPesanObrolan) {
    const wadah = useRef<HTMLOListElement | null>(null);
    const diDasar = useRef(true);

    useEffect(() => {
        const elemen = wadah.current;

        if (elemen !== null && diDasar.current) {
            elemen.scrollTop = elemen.scrollHeight;
        }
    }, [pesan.length]);

    return (
        <ol
            ref={wadah}
            role="log"
            aria-live="polite"
            aria-label={label}
            tabIndex={0}
            onScroll={(peristiwa) => {
                const elemen = peristiwa.currentTarget;
                diDasar.current = elemen.scrollHeight - elemen.scrollTop - elemen.clientHeight < 24;
            }}
            className={cn('flex flex-col gap-2 overflow-y-auto', className)}
        >
            {pesan.map((baris) => {
                if (baris.JenisPengirim === 'Sistem') {
                    return (
                        <li key={baris.Uuid} className="self-center text-center text-keterangan text-teks-sekunder">
                            {baris.Isi}
                        </li>
                    );
                }

                const milikSendiri = (sebagai === 'Pelapor') === (baris.JenisPengirim === 'Pengguna');

                return (
                    <li
                        key={baris.Uuid}
                        className={cn(
                            'flex max-w-[85%] flex-col gap-1 rounded-panel border px-3 py-2',
                            milikSendiri
                                ? 'self-end border-brand bg-brand-lembut'
                                : 'self-start border-garis bg-permukaan',
                        )}
                    >
                        <p className="flex flex-wrap items-baseline gap-x-2 text-keterangan">
                            <span className="font-semibold text-teks-utama">{baris.NamaPengirim}</span>
                            <span className="text-teks-sekunder">{FormatTanggalWaktu(baris.DibuatPada)}</span>
                        </p>
                        <p className="whitespace-pre-line break-words text-isi text-teks-utama">{baris.Isi}</p>
                    </li>
                );
            })}
        </ol>
    );
}
