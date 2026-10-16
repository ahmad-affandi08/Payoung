import { Head } from '@inertiajs/react';

import TampilanDokumenPenawaran from '@/Komponen/Penawaran/TampilanDokumenPenawaran';
import { Button } from '@/Komponen/Ui/button';
import type { PropsCetakPenawaran } from '@/Tipe/Penawaran';

/**
 * Cetak A4 penawaran (F-21 bagian 3): dokumen yang sama dengan halaman publik, tanpa kerangka aplikasi supaya yang keluar
 * di kertas hanya penawarannya. "Cetak atau simpan PDF" memakai dialog cetak peramban (Simpan sebagai PDF).
 */
export default function HalamanCetakPenawaran(props: PropsCetakPenawaran) {
    return (
        <main className="mx-auto flex max-w-4xl flex-col gap-6 bg-permukaan p-6 print:p-0">
            <Head title={`Penawaran ${props.Penawaran.Nomor ?? 'draf'}`} />
            <div className="flex justify-end print:hidden">
                <Button onClick={() => window.print()}>Cetak atau simpan PDF</Button>
            </div>
            <TampilanDokumenPenawaran dokumen={props} />
        </main>
    );
}
