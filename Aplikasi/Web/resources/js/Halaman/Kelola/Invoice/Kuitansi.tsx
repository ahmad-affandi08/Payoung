import { Head } from '@inertiajs/react';

import TampilanKuitansiInvoice from '@/Komponen/Invoice/TampilanKuitansiInvoice';
import { Button } from '@/Komponen/Ui/button';
import type { DokumenKuitansiInvoice } from '@/Tipe/Invoice';

/**
 * Cetak A4 kuitansi pembayaran invoice (F-21 bagian 5): dokumen yang sama dengan halaman publik, tanpa kerangka aplikasi
 * supaya yang keluar di kertas hanya kuitansinya.
 */
export default function HalamanKuitansiInvoice(props: DokumenKuitansiInvoice) {
    return (
        <main className="mx-auto flex max-w-3xl flex-col gap-6 bg-permukaan p-6 print:p-0">
            <Head title={`Kuitansi ${props.Kuitansi.Nomor}`} />
            <div className="flex justify-end print:hidden">
                <Button onClick={() => window.print()}>Cetak atau simpan PDF</Button>
            </div>
            <TampilanKuitansiInvoice dokumen={props} />
        </main>
    );
}
