import { Head, Link } from '@inertiajs/react';

import TampilanKuitansiInvoice from '@/Komponen/Invoice/TampilanKuitansiInvoice';
import { Button } from '@/Komponen/Ui/button';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import type { PropsKuitansiInvoiceDigital } from '@/Tipe/Invoice';

/**
 * Halaman kuitansi publik (F-21 bagian 5, `/i/{kodeInvoice}/kuitansi/{pembayaran}`), tanpa login: dokumen yang sama
 * dengan cetak A4 di back-office. Kode atau pembayaran yang salah, atau invoice yang masih draf, menampilkan keadaan
 * "tidak ditemukan" (404). Halaman ini hanya membaca.
 */
export default function HalamanKuitansiInvoiceDigital({ Dokumen, KodeInvoice }: PropsKuitansiInvoiceDigital) {
    if (Dokumen === null) {
        return (
            <>
                <Head title="Kuitansi tidak ditemukan" />
                <main className="mx-auto flex min-h-screen max-w-md flex-col justify-center gap-2 px-4 py-10">
                    <JudulHalaman skala="ringkas">Kuitansi tidak ditemukan</JudulHalaman>
                    <p className="text-isi text-teks-sekunder">
                        Tautan ini salah atau kuitansinya sudah tidak tersedia. Hubungi penjual untuk meminta tautan
                        yang baru.
                    </p>
                </main>
            </>
        );
    }

    return (
        <>
            <Head title={`Kuitansi ${Dokumen.Kuitansi.Nomor}`} />
            <main className="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-8 print:p-0">
                <div className="flex flex-wrap justify-between gap-2 print:hidden">
                    <Button asChild variant="outline">
                        <Link href={`/i/${KodeInvoice}`}>Kembali ke invoice</Link>
                    </Button>
                    <Button variant="outline" onClick={() => window.print()}>
                        Cetak atau simpan PDF
                    </Button>
                </div>
                <div className="rounded-panel border border-garis bg-permukaan p-4 sm:p-6 print:border-0 print:p-0">
                    <TampilanKuitansiInvoice dokumen={Dokumen} />
                </div>
            </main>
        </>
    );
}
