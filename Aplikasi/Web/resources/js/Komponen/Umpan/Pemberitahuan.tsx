import type { ReactNode } from 'react';

import { cn } from '@/Komponen/Ui/utils';

export type JenisPemberitahuan = 'info' | 'sukses' | 'peringatan' | 'bahaya';

type PropsPemberitahuan = {
    jenis: JenisPemberitahuan;
    judul?: string;
    children: ReactNode;
};

/** Titik penanda nada: warna terang agar terbaca di atas bilah gelap; artinya tetap dibawa teks (judul/label). */
const titikJenis = {
    info: 'bg-info-lembut',
    sukses: 'bg-sukses-lembut',
    peringatan: 'bg-peringatan-lembut',
    bahaya: 'bg-bahaya-lembut',
} as const;

const labelJenis = { info: 'Info', sukses: 'Berhasil', peringatan: 'Perhatian', bahaya: 'Ada masalah' } as const;

/**
 * Bilah status tipis gelap (D-193): satu blok teks ringkas di atas `brand-gelap`, penanda nada berupa titik kecil
 * (bukan kotak berwarna, ikon, atau garis tebal di sisi), tautan di dalamnya berwarna aksen. Dipakai `Pemberitahuan`
 * dan bilah status lain (ringkasan galat formulir) supaya seluruh web tampil sama.
 */
export function KelasBilahStatus(): string {
    return 'flex items-start gap-2.5 rounded-kontrol bg-brand-gelap px-3 py-2.5 text-isi text-brand-teks [&_a]:font-semibold [&_a]:text-aksen [&_a]:underline [&_a]:underline-offset-2';
}

export function TitikBilahStatus({ jenis }: { jenis: JenisPemberitahuan }) {
    return <span aria-hidden="true" className={cn('mt-2 size-2 shrink-0 rounded-full', titikJenis[jenis])} />;
}

/** Tanda baca penyambung judul ke kalimat berikutnya; ditaruh di luar `<strong>` agar teks judul tetap utuh. */
function PenutupJudul(teks: string): string {
    return /[.!?:]$/.test(teks) ? '' : '.';
}

/** Pesan status. Nada selalu dibawa teks (judul, atau label untuk pembaca layar), tidak hanya warna (PRD §17.6.11). */
export default function Pemberitahuan({ jenis, judul, children }: PropsPemberitahuan) {
    return (
        <div
            role={jenis === 'bahaya' || jenis === 'peringatan' ? 'alert' : 'status'}
            data-slot="alert"
            data-jenis={jenis}
            className={KelasBilahStatus()}
        >
            <TitikBilahStatus jenis={jenis} />
            <div className="min-w-0 flex-1 wrap-anywhere">
                {judul ? (
                    <>
                        <strong data-slot="alert-title" className="font-semibold">
                            {judul}
                        </strong>
                        {PenutupJudul(judul)}{' '}
                    </>
                ) : (
                    <span className="sr-only">{labelJenis[jenis]}: </span>
                )}
                <span>{children}</span>
            </div>
        </div>
    );
}
