import type { ReactNode } from 'react';

import { LogoToko } from '@/Komponen/TokoOnline/KepalaToko';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';

type PropsKerangkaMerek = {
    /** Nama tampil usaha (D-77) dan logo tenant bila ada. Tanpa nama, kepala memakai nama Payoung. */
    toko?: { Nama: string; UrlLogo?: string | null | undefined } | null | undefined;
    /** Teks kecil di atas judul, misal "Reservasi online" atau "Struk digital". */
    label: string;
    /** Judul halaman (`<h1>`). */
    judul: string;
    /** `toko` = nama besar (halaman pesan), `situs` = judul sedang (halaman status). */
    skala?: 'toko' | 'situs';
    /** Kalimat pendek di bawah judul, ditaruh di dalam kepala gelap. */
    keterangan?: ReactNode;
    /** `xl` (bawaan) untuk satu kolom sempit, `3xl` untuk halaman berdaftar seperti Akun saya. */
    lebar?: 'xl' | '3xl';
    children: ReactNode;
};

/**
 * Kerangka halaman pelanggan bermerek tenant (D-240, D-241), selaras toko online (D-238) dan self-order (D-239): kepala teal gelap
 * berisi logo tenant dan judul besar, isi menjorok ke atas kepala sebagai kartu, kaki "Didukung Payoung". Warna tetap
 * merek Payoung, hanya logo dan nama yang milik tenant.
 */
export default function KerangkaMerek({
    toko,
    label,
    judul,
    skala = 'toko',
    keterangan,
    lebar = 'xl',
    children,
}: PropsKerangkaMerek) {
    const kelasLebar = lebar === '3xl' ? 'max-w-3xl' : 'max-w-xl';
    const nama = toko?.Nama ?? 'Payoung';

    return (
        <div className="flex min-h-dvh flex-col bg-latar text-isi text-teks-utama">
            <header className="bg-brand-gelap pb-14 text-permukaan">
                <div className={`mx-auto flex w-full ${kelasLebar} flex-col gap-4 px-4 pt-5`}>
                    <div className="flex items-center gap-3">
                        <LogoToko nama={nama} url={toko?.UrlLogo} />
                        <span className="min-w-0 truncate text-subjudul font-bold">{nama}</span>
                    </div>
                    <div className="flex flex-col gap-2 pt-2">
                        <p className="text-label font-bold tracking-wide text-brand-gelap-teks uppercase">{label}</p>
                        <JudulHalaman skala={skala} className="[overflow-wrap:anywhere] text-permukaan">
                            {judul}
                        </JudulHalaman>
                        {keterangan ? <p className="text-isi text-brand-gelap-teks">{keterangan}</p> : null}
                    </div>
                </div>
            </header>
            <main className={`mx-auto -mt-8 flex w-full ${kelasLebar} flex-1 flex-col gap-4 px-4 pb-6 tepi-bawah-aman`}>
                {children}
            </main>
            <footer className="py-4 text-center text-label text-teks-sekunder">Didukung Payoung</footer>
        </div>
    );
}
