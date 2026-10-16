import { Head } from '@inertiajs/react';

import TampilanDokumenInvoice from '@/Komponen/Invoice/TampilanDokumenInvoice';
import { Button } from '@/Komponen/Ui/button';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import type { PropsInvoiceDigital } from '@/Tipe/Invoice';

/**
 * Halaman invoice publik (F-21, `/i/{kodeInvoice}`), tanpa login: dokumen yang sama dengan cetak A4 di back-office.
 * Kode yang salah, draf, atau usaha yang sudah ditutup menampilkan keadaan "tidak ditemukan" (404). Halaman ini hanya
 * membaca; harga dan angka datang dari server.
 */
export default function HalamanInvoiceDigital({ Dokumen }: PropsInvoiceDigital) {
    if (Dokumen === null) {
        return (
            <>
                <Head title="Invoice tidak ditemukan" />
                <main className="mx-auto flex min-h-screen max-w-md flex-col justify-center gap-2 px-4 py-10">
                    <JudulHalaman skala="ringkas">Invoice tidak ditemukan</JudulHalaman>
                    <p className="text-isi text-teks-sekunder">
                        Tautan ini salah atau invoicenya sudah tidak tersedia. Hubungi penerbit invoice untuk meminta
                        tautan yang baru.
                    </p>
                </main>
            </>
        );
    }

    return (
        <>
            <Head title={`Invoice ${Dokumen.Invoice.Nomor ?? ''}`.trim()} />
            <main className="mx-auto flex max-w-4xl flex-col gap-6 px-4 py-8 print:p-0">
                <div className="flex justify-end print:hidden">
                    <Button variant="outline" onClick={() => window.print()}>
                        Cetak atau simpan PDF
                    </Button>
                </div>
                <div className="rounded-panel border border-garis bg-permukaan p-4 sm:p-6 print:border-0 print:p-0">
                    <TampilanDokumenInvoice dokumen={Dokumen} />
                </div>
            </main>
        </>
    );
}
