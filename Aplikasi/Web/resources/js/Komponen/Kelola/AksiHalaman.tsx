import { Link } from '@inertiajs/react';
import { Children, useEffect, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

import { Button } from '@/Komponen/Ui/button';
import { useLebarLayar } from '@/Komponen/TabelData/useLebarLayar';

import { useAksiHalaman, type ItemAksiHalaman } from './KonteksAksiHalaman';
import MenuLainnya from './MenuLainnya';

type PropsAksiHalaman = {
    /** Keterangan singkat di kiri (opsional); aksi tetap rata kanan walau ini kosong. */
    keterangan?: ReactNode;
    /**
     * Aksi utama halaman (satu saja, mis. "Tambah produk"). Di HP ikut pindah ke baris Filter bilah alat tabel
     * dan satu-satunya tombol berwarna di sana (D-228).
     */
    utama?: ReactNode;
    /**
     * Aksi sekunder (mis. "Impor dari Excel"). Layar lebar: tombol di samping aksi utama. HP: menu "Lainnya".
     */
    lainnya?: ItemAksiHalaman[];
    children?: ReactNode;
};

function TombolSekunder({ butir }: { butir: ItemAksiHalaman }) {
    if (butir.href === undefined) {
        return (
            <Button
                type="button"
                variant="outline"
                className="h-8 pointer-coarse:h-11"
                disabled={butir.nonaktif === true}
                onClick={() => butir.saatKlik?.()}
            >
                {butir.label}
            </Button>
        );
    }

    return (
        <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
            {butir.unduh === true || butir.tabBaru === true ? (
                <a href={butir.href} {...(butir.tabBaru === true ? { target: '_blank', rel: 'noreferrer' } : {})}>
                    {butir.label}
                </a>
            ) : (
                <Link href={butir.href}>{butir.label}</Link>
            )}
        </Button>
    );
}

/**
 * Baris aksi halaman: keterangan di kiri, tombol aksi **selalu rata kanan** (D-27).
 *
 * Sebelum ini tiap halaman daftar merakit barisnya sendiri: sebagian memakai `justify-between` (tombol di kanan),
 * sebagian hanya `<div>` biasa (tombol di kiri). Hasilnya posisi tombol "Tambah" berpindah-pindah antar halaman
 * dan pengguna harus mencarinya tiap kali. Rata kanan dipakai lewat `ml-auto`, bukan `justify-between`, supaya
 * tetap kanan meski `keterangan` tidak diisi.
 *
 * Halaman yang tombolnya sudah berada di bilah alat `TabelData` (`aksiAlat`) tidak perlu komponen ini — di sana
 * tombolnya juga sudah rata kanan. Dijaga `AksiHalamanTes`.
 *
 * D-228, di HP: halaman bertabel tidak lagi membuka dengan dua-tiga baris tombol. Aksi utama dipindah ke baris
 * Filter bilah alat tabel (lewat `PenyediaAksiHalaman`) dan aksi sekunder masuk menu "Lainnya". Halaman tanpa tabel
 * memakai satu baris: aksi utama selebar sisa baris, ditambah menu "Lainnya".
 */
export default function AksiHalaman({ keterangan, utama, lainnya = [], children }: PropsAksiHalaman) {
    const lebar = useLebarLayar();
    const konteks = useAksiHalaman();
    const hp = lebar === 'hp';
    const slot = hp ? (konteks?.slot ?? null) : null;
    const AturLainnya = konteks?.AturLainnya;
    const adaIsi = Children.toArray(children).length > 0 || Boolean(utama) || lainnya.length > 0;

    // Aksi sekunder dititipkan ke menu "Lainnya" di bilah alat tabel selama tabelnya ada di halaman ini. Didaftarkan
    // ulang hanya bila isinya berubah (kunci teks), dan penangan klik dibaca dari render terbaru lewat `terbaru`.
    const terbaru = useRef(lainnya);
    useEffect(() => {
        terbaru.current = lainnya;
    });
    const kunci = lainnya
        .map(
            (b) =>
                `${b.label}|${b.href ?? ''}|${b.unduh === true ? '1' : '0'}|${b.tabBaru === true ? '1' : '0'}|${b.nonaktif === true ? '1' : '0'}|${b.saatKlik ? '1' : '0'}`,
        )
        .join('~');

    useEffect(() => {
        if (slot === null || AturLainnya === undefined) {
            return undefined;
        }

        AturLainnya(
            terbaru.current.map((butir, indeks) => ({
                ...butir,
                ...(butir.saatKlik ? { saatKlik: () => terbaru.current[indeks]?.saatKlik?.() } : {}),
            })),
        );

        return () => AturLainnya([]);
    }, [slot, AturLainnya, kunci]);

    // Banyak halaman mengisi aksinya dengan nilai yang bisa `null` (tombol disembunyikan karena izin). Tanpa
    // penjagaan ini barisnya tetap terbentuk dan menyisakan celah kosong di atas tabel.
    if (!adaIsi && keterangan === undefined) {
        return null;
    }

    if (slot !== null) {
        return (
            <>
                {keterangan !== undefined ? (
                    <div className="flex flex-wrap items-center gap-2">{keterangan}</div>
                ) : null}
                {createPortal(
                    <>
                        {utama}
                        {children}
                    </>,
                    slot,
                )}
            </>
        );
    }

    if (hp) {
        return (
            <div className="flex flex-wrap items-center gap-2">
                {keterangan}
                <div className="ml-auto flex min-w-0 flex-1 items-center justify-end gap-2">
                    {utama}
                    {children}
                    <MenuLainnya butir={lainnya} />
                </div>
            </div>
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            {keterangan}
            <div className="ml-auto flex flex-wrap items-center gap-2">
                {lainnya.map((butir) => (
                    <TombolSekunder key={butir.label} butir={butir} />
                ))}
                {utama}
                {children}
            </div>
        </div>
    );
}
