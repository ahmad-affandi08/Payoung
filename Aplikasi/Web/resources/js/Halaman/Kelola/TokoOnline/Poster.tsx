import { Head, Link } from '@inertiajs/react';

import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';

type PropsPosterTokoOnline = {
    NamaToko: string;
    Tautan: string;
    QrSvg: string;
    /** False bila toko online belum menerima pesanan: poster tetap bisa dilihat, tetapi diberi peringatan. */
    Aktif: boolean;
};

/**
 * D-110: poster A5 "Pesan online di sini" berisi nama toko, QR, dan tautan toko online, untuk ditempel di kasir, pintu,
 * atau meja. Tanpa kerangka aplikasi agar rapi saat dicetak atau disimpan PDF lewat peramban.
 */
export default function PosterTokoOnline({ NamaToko, Tautan, QrSvg, Aktif }: PropsPosterTokoOnline) {
    return (
        <main className="mx-auto flex max-w-xl flex-col gap-6 bg-permukaan p-4 text-isi text-teks-utama sm:p-6 print:max-w-none print:p-0">
            <Head title={`Poster toko online ${NamaToko}`} />
            <div className="flex flex-wrap items-center justify-between gap-3 print:hidden">
                <div className="flex flex-col gap-1">
                    <Link href="/kelola/toko-online" className="text-label font-semibold text-brand underline">
                        Kembali ke toko online
                    </Link>
                    <JudulHalaman>Poster toko online</JudulHalaman>
                </div>
                <Button onClick={() => window.print()}>Cetak atau simpan PDF</Button>
            </div>
            {Aktif ? null : (
                <div className="print:hidden">
                    <Pemberitahuan jenis="peringatan" judul="Toko online belum menerima pesanan">
                        Pembeli yang memindai poster ini akan melihat toko tutup. Aktifkan toko di halaman toko online
                        sebelum poster ditempel.
                    </Pemberitahuan>
                </div>
            )}
            <section
                aria-label="Poster"
                className="flex break-inside-avoid flex-col items-center gap-4 rounded-panel border-2 border-brand p-8 text-center"
            >
                <p className="text-label font-semibold tracking-wide text-teks-sekunder uppercase">Pesan online</p>
                <h2 className="text-tampilan font-bold">{NamaToko}</h2>
                <img
                    src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(QrSvg)}`}
                    alt={`QR toko online ${NamaToko}`}
                    className="size-64"
                />
                <p className="text-judul font-semibold">Pindai, pilih menu, pesan dari mana saja</p>
                <p className="text-isi text-teks-sekunder">Ambil sendiri atau diantar ke alamat Anda.</p>
                <p className="font-mono text-label break-all text-teks-sekunder">{Tautan}</p>
            </section>
        </main>
    );
}
