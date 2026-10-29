import { Head, Link } from '@inertiajs/react';
import { PrinterIcon } from 'lucide-react';

import TampilanKuitansiInvoice from '@/Komponen/Invoice/TampilanKuitansiInvoice';
import KerangkaMerek from '@/Komponen/Publik/KerangkaMerek';
import { PanelHasil } from '@/Komponen/Publik/KerangkaPelanggan';
import { Button } from '@/Komponen/Ui/button';
import type { PropsKuitansiInvoiceDigital } from '@/Tipe/Invoice';

/**
 * Halaman kuitansi publik (F-21 bagian 5, `/i/{kodeInvoice}/kuitansi/{pembayaran}`), tanpa login: dokumen yang sama
 * dengan cetak A4 di back-office, dibungkus kepala bermerek penjual (D-242). Kode atau pembayaran yang salah, atau
 * invoice yang masih draf, menampilkan keadaan "tidak ditemukan" (404). Halaman ini hanya membaca.
 */
export default function HalamanKuitansiInvoiceDigital({ Dokumen, KodeInvoice, UrlLogo }: PropsKuitansiInvoiceDigital) {
    if (Dokumen === null) {
        return (
            <KerangkaMerek label="Kuitansi" judul="Kuitansi" skala="situs">
                <Head title="Kuitansi tidak ditemukan" />
                <PanelHasil jenis="netral" judulBagian judul="Kuitansi tidak ditemukan">
                    Tautan ini salah atau kuitansinya sudah tidak tersedia. Hubungi penjual untuk meminta tautan yang
                    baru.
                </PanelHasil>
            </KerangkaMerek>
        );
    }

    return (
        <KerangkaMerek
            toko={{ Nama: Dokumen.Penjual.Nama, UrlLogo: UrlLogo ?? null }}
            lebar="3xl"
            label="Kuitansi"
            judul={Dokumen.Kuitansi.Nomor}
            skala="situs"
        >
            <Head title={`Kuitansi ${Dokumen.Kuitansi.Nomor}`} />
            <div className="flex flex-wrap justify-between gap-2 print:hidden">
                <Button asChild variant="outline">
                    <Link href={`/i/${KodeInvoice}`}>Kembali ke invoice</Link>
                </Button>
                <Button variant="outline" onClick={() => window.print()}>
                    <PrinterIcon aria-hidden="true" className="size-4" />
                    Cetak atau simpan PDF
                </Button>
            </div>
            <div className="rounded-3xl bg-permukaan p-4 sm:p-6 print:rounded-none print:p-0">
                <TampilanKuitansiInvoice dokumen={Dokumen} />
            </div>
        </KerangkaMerek>
    );
}
