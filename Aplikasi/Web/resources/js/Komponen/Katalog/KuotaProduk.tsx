import { Link } from '@inertiajs/react';

import AjakanTambahBatas from '@/Komponen/Kelola/AjakanTambahBatas';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { CekBatasPenuh, type Batas } from '@/Tipe/Organisasi';

type PropsKuotaProduk = {
    batas: Batas;
    /** Hanya yang boleh mengelola produk diberi ajakan arsip/naik paket. */
    bolehKelola: boolean;
};

/**
 * Kuota produk paket dalam satu strip: angka terpakai/batas, meteran, dan label "Penuh" bila batas tercapai.
 * Satu blok menggantikan dua (baris "Produk terhitung paket" + kotak pemberitahuan) yang mengulang hal yang sama.
 * Penuh = label bertulis (bukan warna saja); ajakannya hanya satu kalimat pendek.
 */
export default function KuotaProduk({ batas, bolehKelola }: PropsKuotaProduk) {
    if (batas.Batas === null) {
        return (
            <p className="text-isi text-teks-sekunder">
                <span className="font-semibold text-teks-utama tabular-nums">{batas.Terpakai}</span> produk terhitung
                paket (tanpa batas)
            </p>
        );
    }

    const penuh = CekBatasPenuh(batas);
    const persen = Math.min(100, Math.round((batas.Terpakai / Math.max(1, batas.Batas)) * 100));

    return (
        <section
            aria-label="Kuota produk paket"
            className="flex flex-col gap-2 rounded-panel border border-garis bg-permukaan px-4 py-3"
        >
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span className="text-label text-teks-sekunder">Kuota produk</span>
                <span className="text-isi font-semibold text-teks-utama tabular-nums">
                    {batas.Terpakai} / {batas.Batas}
                </span>
                {penuh ? <LabelStatus jenis="peringatan" teks="Penuh" /> : null}
            </div>
            <div
                role="progressbar"
                aria-label="Pemakaian kuota produk"
                aria-valuemin={0}
                aria-valuemax={batas.Batas}
                aria-valuenow={Math.min(batas.Terpakai, batas.Batas)}
                aria-valuetext={`${String(batas.Terpakai)} dari ${String(batas.Batas)} produk`}
                className="h-1.5 overflow-hidden rounded-full bg-garis"
            >
                <div
                    className={penuh ? 'h-full bg-peringatan' : 'h-full bg-brand'}
                    style={{ width: `${String(persen)}%` }}
                />
            </div>
            {penuh && bolehKelola ? (
                <p className="text-keterangan text-teks-sekunder">
                    Arsipkan produk yang tidak dijual lagi atau{' '}
                    <AjakanTambahBatas>
                        <Link href="/kelola/langganan" className="font-semibold text-brand underline">
                            tingkatkan paket
                        </Link>
                    </AjakanTambahBatas>
                    .
                </p>
            ) : null}
        </section>
    );
}
