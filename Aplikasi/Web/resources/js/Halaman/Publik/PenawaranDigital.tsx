import { Head, usePage } from '@inertiajs/react';

import KeputusanPenawaranPublik from '@/Komponen/Penawaran/KeputusanPenawaranPublik';
import TampilanDokumenPenawaran from '@/Komponen/Penawaran/TampilanDokumenPenawaran';
import { Button } from '@/Komponen/Ui/button';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { PropsPenawaranDigital } from '@/Tipe/Penawaran';

/**
 * Halaman penawaran publik (F-21 bagian 3, `/p/{kodePenawaran}`), tanpa login: dokumen yang sama dengan cetak A4 di
 * back-office, ditambah formulir menerima atau menolak selama penawaran masih berlaku. Kode yang salah, draf, atau usaha
 * yang sudah ditutup menampilkan keadaan "tidak ditemukan" (404). Harga dan angka datang dari server.
 */
export default function HalamanPenawaranDigital({ Dokumen, KodePenawaran }: PropsPenawaranDigital) {
    const { props } = usePage<{ Kilat?: string | null }>();

    if (Dokumen === null || KodePenawaran === null) {
        return (
            <>
                <Head title="Penawaran tidak ditemukan" />
                <main className="mx-auto flex min-h-screen max-w-md flex-col justify-center gap-2 px-4 py-10">
                    <JudulHalaman skala="ringkas">Penawaran tidak ditemukan</JudulHalaman>
                    <p className="text-isi text-teks-sekunder">
                        Tautan ini salah atau penawarannya sudah tidak tersedia. Hubungi penjual untuk meminta tautan
                        yang baru.
                    </p>
                </main>
            </>
        );
    }

    const p = Dokumen.Penawaran;
    const sudahDiputuskan = p.Status === 'Diterima' || p.Status === 'Ditolak';

    return (
        <>
            <Head title={`Penawaran ${p.Nomor ?? ''}`.trim()} />
            <main className="mx-auto flex max-w-4xl flex-col gap-6 px-4 py-8 print:p-0">
                {props.Kilat ? (
                    <Pemberitahuan jenis="sukses" judul="Tanggapan Anda tercatat">
                        {props.Kilat}
                    </Pemberitahuan>
                ) : null}
                <div className="flex justify-end print:hidden">
                    <Button variant="outline" onClick={() => window.print()}>
                        Cetak atau simpan PDF
                    </Button>
                </div>
                <div className="rounded-panel border border-garis bg-permukaan p-4 sm:p-6 print:border-0 print:p-0">
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
            </main>
        </>
    );
}
