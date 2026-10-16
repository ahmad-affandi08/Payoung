import { Head } from '@inertiajs/react';

import TampilanDokumenInvoice from '@/Komponen/Invoice/TampilanDokumenInvoice';
import { Button } from '@/Komponen/Ui/button';
import type { PropsCetakInvoice } from '@/Tipe/Invoice';

/**
 * Cetak A4 invoice (F-21): dokumen yang sama dengan halaman publik, tanpa kerangka aplikasi supaya yang keluar di kertas
 * hanya invoicenya. "Cetak atau simpan PDF" memakai dialog cetak peramban (Simpan sebagai PDF).
 */
export default function HalamanCetakInvoice(props: PropsCetakInvoice) {
    return (
        <main className="mx-auto flex max-w-4xl flex-col gap-6 bg-permukaan p-6 print:p-0">
            <Head title={`Invoice ${props.Invoice.Nomor ?? 'draf'}`} />
            <div className="flex justify-end print:hidden">
                <Button onClick={() => window.print()}>Cetak atau simpan PDF</Button>
            </div>
            <TampilanDokumenInvoice dokumen={props} />
        </main>
    );
}
