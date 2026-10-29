import { Head, usePage } from '@inertiajs/react';
import { PrinterIcon } from 'lucide-react';

import KeputusanPenawaranPublik from '@/Komponen/Penawaran/KeputusanPenawaranPublik';
import TampilanDokumenPenawaran from '@/Komponen/Penawaran/TampilanDokumenPenawaran';
import KerangkaMerek from '@/Komponen/Publik/KerangkaMerek';
import { PanelHasil } from '@/Komponen/Publik/KerangkaPelanggan';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { PropsPenawaranDigital } from '@/Tipe/Penawaran';

/**
 * Halaman penawaran publik (F-21 bagian 3, `/p/{kodePenawaran}`), tanpa login: dokumen yang sama dengan cetak A4 di
 * back-office, dibungkus kepala bermerek penjual (D-242), ditambah formulir menerima atau menolak selama penawaran masih
 * berlaku. Kode yang salah, draf, atau usaha yang sudah ditutup menampilkan keadaan "tidak ditemukan" (404). Harga dan
 * angka datang dari server.
 */
export default function HalamanPenawaranDigital({ Dokumen, KodePenawaran, UrlLogo }: PropsPenawaranDigital) {
    const { props } = usePage<{ Kilat?: string | null }>();

    if (Dokumen === null || KodePenawaran === null) {
        return (
            <KerangkaMerek label="Penawaran" judul="Penawaran" skala="situs">
                <Head title="Penawaran tidak ditemukan" />
                <PanelHasil jenis="netral" judulBagian judul="Penawaran tidak ditemukan">
                    Tautan ini salah atau penawarannya sudah tidak tersedia. Hubungi penjual untuk meminta tautan yang
                    baru.
                </PanelHasil>
            </KerangkaMerek>
        );
    }

    const p = Dokumen.Penawaran;
    const sudahDiputuskan = p.Status === 'Diterima' || p.Status === 'Ditolak';

    return (
        <KerangkaMerek
            toko={{ Nama: Dokumen.Penjual.Nama, UrlLogo: UrlLogo ?? null }}
            lebar="4xl"
            label="Penawaran harga"
            judul={p.Nomor ?? 'Draf'}
            skala="situs"
            keterangan={p.StatusTampil}
        >
            <Head title={`Penawaran ${p.Nomor ?? ''}`.trim()} />
            {props.Kilat ? (
                <Pemberitahuan jenis="sukses" judul="Tanggapan Anda tercatat">
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
                <TampilanDokumenPenawaran dokumen={Dokumen} />
            </div>
            {p.BisaDiputuskan ? <KeputusanPenawaranPublik kode={KodePenawaran} /> : null}
            {sudahDiputuskan && p.DiputuskanPada !== null ? (
                <p className="text-isi text-teks-sekunder print:hidden">
                    Penawaran ini {p.Status === 'Diterima' ? 'diterima' : 'ditolak'}
                    {p.NamaPemutus ? ` oleh ${p.NamaPemutus}` : ''} pada {FormatTanggalWaktu(p.DiputuskanPada)}.
                </p>
            ) : null}
            {p.StatusTampil === 'Kedaluwarsa' ? (
                <p className="text-isi text-teks-sekunder print:hidden">
                    Masa berlaku penawaran ini sudah lewat. Hubungi penjual untuk penawaran terbaru.
                </p>
            ) : null}
        </KerangkaMerek>
    );
}
