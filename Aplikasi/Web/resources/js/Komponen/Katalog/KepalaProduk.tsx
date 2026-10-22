import { Link } from '@inertiajs/react';

import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import type { KepalaProduk as DataKepalaProduk, TabProduk as DataTabProduk } from '@/Tipe/Katalog';

import GambarProduk from './GambarProduk';
import TabProduk from './TabProduk';

type PropsKepalaProduk = { kepala: DataKepalaProduk; tabAktif: DataTabProduk['Kunci'] };

/** Kepala halaman satu produk: gambar kecil, nama, SKU (Mono), jenis, status, induk varian, lalu tab. */
export default function KepalaProduk({ kepala, tabAktif }: PropsKepalaProduk) {
    return (
        <div className="flex flex-col gap-3">
            <p className="text-label">
                <Link href="/kelola/produk" className="font-semibold text-brand underline">
                    Kembali ke daftar produk
                </Link>
            </p>
            <div className="flex items-start gap-3">
                <GambarProduk
                    nama={kepala.Nama}
                    url={kepala.UrlGambarKecil}
                    ukuran="sedang"
                    className="flex size-14 shrink-0 rounded-kontrol border border-garis bg-latar"
                />
                <div className="flex min-w-0 flex-col gap-1">
                    <p className="text-subjudul font-semibold break-words text-teks-utama">{kepala.Nama}</p>
                    <p className="flex flex-wrap items-center gap-2 text-label text-teks-sekunder">
                        <span className="font-mono text-teks-utama">{kepala.Sku ?? 'Tanpa SKU'}</span>
                        <span aria-hidden="true">|</span>
                        <span>{kepala.LabelJenis}</span>
                        {kepala.Status === 'Diarsipkan' ? <LabelStatus jenis="netral" teks="Diarsipkan" /> : null}
                    </p>
                    {kepala.UuidInduk ? (
                        <p className="text-keterangan text-teks-sekunder">
                            Varian dari{' '}
                            <Link
                                href={`/kelola/produk/${kepala.UuidInduk}`}
                                className="font-semibold text-brand underline"
                            >
                                {kepala.NamaInduk ?? 'produk induk'}
                            </Link>
                        </p>
                    ) : null}
                </div>
            </div>
            <TabProduk tab={kepala.Tab} aktif={tabAktif} />
        </div>
    );
}
