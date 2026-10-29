import { CircleAlert, CircleCheck, Link2Off } from 'lucide-react';
import type { ReactNode } from 'react';

import { LogoToko } from '@/Komponen/TokoOnline/KepalaToko';
import { cn } from '@/Komponen/Ui/utils';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';

type PropsKerangka = {
    /** Nama outlet yang tampil di kepala (D-77). Tanpa nama, kepala menampilkan merek Payoung. */
    toko?: string | null | undefined;
    /** Logo tenant (D-242) bila halaman tahu alamatnya, selain itu kepala memakai inisial toko. */
    urlLogo?: string | null | undefined;
    children: ReactNode;
};

/**
 * Kerangka halaman pelanggan yang dibuka dari tautan toko (status reservasi, persetujuan servis, portal kurir, berhenti
 * berlangganan): kepala bernama outlet, isi satu kolom di atas latar lembut, dan kaki "Didukung Payoung". Hanya
 * pembungkus visual; isi dan aksinya tetap milik halaman masing-masing (D-92).
 */
export default function KerangkaPelanggan({ toko, urlLogo, children }: PropsKerangka) {
    return (
        <div className="flex min-h-screen flex-col bg-latar text-isi text-teks-utama">
            <header className="bg-brand-gelap text-permukaan">
                <div className="mx-auto flex h-16 w-full max-w-xl items-center gap-3 px-4">
                    <LogoToko nama={toko ?? 'Payoung'} url={urlLogo} />
                    <span className="min-w-0 truncate text-subjudul font-bold">{toko ?? 'Payoung'}</span>
                </div>
            </header>
            <main className="mx-auto flex w-full max-w-xl flex-1 flex-col gap-4 px-4 py-6 tepi-bawah-aman">
                {children}
            </main>
            <footer className="py-4 text-center text-label text-teks-sekunder">Didukung Payoung</footer>
        </div>
    );
}

const IKON_HASIL = { sukses: CircleCheck, bahaya: CircleAlert, netral: Link2Off } as const;
const KELAS_HASIL = {
    sukses: 'bg-sukses-lembut text-sukses',
    bahaya: 'bg-bahaya-lembut text-bahaya',
    netral: 'bg-permukaan-redup text-teks-sekunder',
} as const;

/** Hasil satu langkah (berhasil, ditolak, tautan tidak berlaku): ikon bulat, judul, dan penjelasan yang ditengahkan. */
export function PanelHasil({
    jenis,
    judul,
    judulBagian = false,
    children,
}: {
    jenis: keyof typeof IKON_HASIL;
    judul: string;
    /** Bila halaman sudah punya judul `<h1>` sendiri (kepala bermerek), judul panel jadi `<h2>`. */
    judulBagian?: boolean;
    children?: ReactNode;
}) {
    const Ikon = IKON_HASIL[jenis];

    return (
        <section className="flex flex-col items-center gap-3 rounded-3xl bg-permukaan px-6 py-10 text-center">
            <span className={cn('flex size-14 items-center justify-center rounded-full', KELAS_HASIL[jenis])}>
                <Ikon aria-hidden className="size-7" strokeWidth={1.75} />
            </span>
            {judulBagian ? (
                <h2 className="text-center text-judul font-bold text-teks-utama">{judul}</h2>
            ) : (
                <JudulHalaman className="text-center">{judul}</JudulHalaman>
            )}
            {children ? <div className="max-w-md text-isi text-teks-sekunder">{children}</div> : null}
        </section>
    );
}
