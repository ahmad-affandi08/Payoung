import { Head, Link } from '@inertiajs/react';

import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';

export type KartuQrMeja = { Uuid: string; Nama: string; NamaArea: string | null; Url: string; QrSvg: string };

export type PropsQrMeja = {
    Outlet: { Uuid: string; Kode: string; Nama: string };
    NamaUsaha: string;
    PesanSendiriAktif: boolean;
    Meja: KartuQrMeja[];
};

/**
 * F-17: kartu QR pesan sendiri semua meja aktif satu outlet (gaya kartu tenda), untuk dicetak atau disimpan PDF lewat
 * peramban. Tanpa kerangka aplikasi agar rapi saat dicetak; tiap kartu tidak terpotong antarhalaman.
 */
export default function HalamanQrMeja({ Outlet, NamaUsaha, PesanSendiriAktif, Meja }: PropsQrMeja) {
    return (
        <main className="mx-auto flex max-w-5xl flex-col gap-6 bg-permukaan p-4 text-isi text-teks-utama sm:p-6 print:max-w-none print:p-0">
            <Head title={`QR meja ${Outlet.Nama}`} />
            <div className="flex flex-wrap items-center justify-between gap-3 print:hidden">
                <div className="flex flex-col gap-1">
                    <Link
                        href={`/kelola/outlet/${Outlet.Uuid}`}
                        className="text-label font-semibold text-brand underline"
                    >
                        Kembali ke outlet {Outlet.Nama}
                    </Link>
                    <JudulHalaman>QR pesan sendiri | {Outlet.Nama}</JudulHalaman>
                </div>
                <Button onClick={() => window.print()} disabled={Meja.length === 0}>
                    Cetak atau simpan PDF
                </Button>
            </div>
            {PesanSendiriAktif ? null : (
                <div className="print:hidden">
                    <Pemberitahuan jenis="peringatan" judul="Pesan sendiri belum aktif">
                        Tamu yang memindai akan melihat pesan bahwa pesan sendiri belum aktif. Hidupkan di halaman
                        outlet.
                    </Pemberitahuan>
                </div>
            )}
            {Meja.length === 0 ? (
                <p className="text-teks-sekunder">Belum ada meja aktif di outlet ini. Tambah meja di halaman outlet.</p>
            ) : (
                <ul
                    aria-label="Kartu QR meja"
                    className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 print:grid-cols-2 print:gap-6"
                >
                    {Meja.map((meja) => (
                        <li
                            key={meja.Uuid}
                            aria-label={`QR meja ${meja.Nama}`}
                            className="flex break-inside-avoid flex-col items-center gap-3 rounded-panel border border-garis p-5 text-center"
                        >
                            <p className="text-label text-teks-sekunder">{NamaUsaha}</p>
                            <p className="text-tampilan font-bold">Meja {meja.Nama}</p>
                            {meja.NamaArea ? <p className="text-label text-teks-sekunder">{meja.NamaArea}</p> : null}
                            <img
                                src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(meja.QrSvg)}`}
                                alt={`QR pesan sendiri meja ${meja.Nama}`}
                                className="size-48"
                            />
                            <p className="text-subjudul font-semibold">Pindai untuk lihat menu & pesan</p>
                            <p className="text-keterangan text-teks-sekunder">Bayar di kasir setelah makan.</p>
                            <p className="font-mono text-keterangan break-all text-teks-sekunder">{meja.Url}</p>
                        </li>
                    ))}
                </ul>
            )}
        </main>
    );
}
