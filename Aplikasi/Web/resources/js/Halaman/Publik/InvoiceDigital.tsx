import { Head, usePage } from '@inertiajs/react';
import { PrinterIcon } from 'lucide-react';

import KonfirmasiBayarInvoicePublik from '@/Komponen/Invoice/KonfirmasiBayarInvoicePublik';
import TampilanDokumenInvoice from '@/Komponen/Invoice/TampilanDokumenInvoice';
import KerangkaMerek from '@/Komponen/Publik/KerangkaMerek';
import { PanelHasil } from '@/Komponen/Publik/KerangkaPelanggan';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import type { PropsInvoiceDigital } from '@/Tipe/Invoice';

/**
 * Halaman invoice publik (F-21, `/i/{kodeInvoice}`), tanpa login: dokumen yang sama dengan cetak A4 di back-office,
 * dibungkus kepala bermerek penjual (D-242) yang tidak ikut tercetak. Kode yang salah, draf, atau usaha yang sudah
 * ditutup menampilkan keadaan "tidak ditemukan" (404). Halaman ini hanya membaca; harga dan angka datang dari server.
 */
export default function HalamanInvoiceDigital({ Dokumen, KodeInvoice, UrlLogo }: PropsInvoiceDigital) {
    const { props } = usePage<{ Kilat?: string | null }>();

    if (Dokumen === null || KodeInvoice === null) {
        return (
            <KerangkaMerek label="Invoice" judul="Invoice" skala="situs">
                <Head title="Invoice tidak ditemukan" />
                <PanelHasil jenis="netral" judulBagian judul="Invoice tidak ditemukan">
                    Tautan ini salah atau invoicenya sudah tidak tersedia. Hubungi penerbit invoice untuk meminta tautan
                    yang baru.
                </PanelHasil>
            </KerangkaMerek>
        );
    }

    const nomor = Dokumen.Invoice.Nomor ?? 'Draf';

    return (
        <KerangkaMerek
            toko={{ Nama: Dokumen.Penjual.Nama, UrlLogo: UrlLogo ?? null }}
            lebar="4xl"
            label={Dokumen.Invoice.LabelJenis}
            judul={nomor}
            skala="situs"
            keterangan={Dokumen.Invoice.LabelStatusBayar}
        >
            <Head title={`Invoice ${Dokumen.Invoice.Nomor ?? ''}`.trim()} />
            {props.Kilat ? (
                <Pemberitahuan jenis="sukses" judul="Konfirmasi terkirim">
                    {props.Kilat}
                </Pemberitahuan>
            ) : null}
            <div className="flex justify-end print:hidden">
                <Button variant="outline" onClick={() => window.print()}>
                    <PrinterIcon aria-hidden="true" className="size-4" />
                    Cetak atau simpan PDF
                </Button>
            </div>
            <div className="rounded-3xl bg-permukaan p-4 sm:p-6 print:rounded-none print:p-0">
                <TampilanDokumenInvoice
                    dokumen={Dokumen}
                    alamatKuitansi={(uuid) => `/i/${KodeInvoice}/kuitansi/${uuid}`}
                />
            </div>
            {Dokumen.Konfirmasi?.Bisa ? (
                <KonfirmasiBayarInvoicePublik kode={KodeInvoice} konfirmasi={Dokumen.Konfirmasi} />
            ) : null}
        </KerangkaMerek>
    );
}
