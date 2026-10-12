import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import PanelTransferManual, { type RekeningTujuan } from '@/Komponen/Langganan/PanelTransferManual';
import RincianTagihan from '@/Komponen/Langganan/RincianTagihan';
import TombolBayarOnline from '@/Komponen/Langganan/TombolBayarOnline';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/Komponen/Ui/alert-dialog';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import { JenisLabelPembayaran, type PembayaranLangganan, type TagihanLangganan } from '@/Tipe/TagihanLangganan';

type PropsTagihan = {
    Tagihan: TagihanLangganan;
    Pembayaran: PembayaranLangganan[];
    BolehBayarOnline: boolean;
    BolehBatalkan: boolean;
    BolehUnggahBukti?: boolean;
    RekeningTujuan?: RekeningTujuan[];
};

/** Detail tagihan dan pembayaran online lewat DOKU Checkout (gerbang billing platform). */
export default function HalamanTagihanLangganan({
    Tagihan,
    Pembayaran,
    BolehBayarOnline,
    BolehBatalkan,
    BolehUnggahBukti = false,
    RekeningTujuan = [],
}: PropsTagihan) {
    const [membatalkan, AturMembatalkan] = useState(false);

    const Batalkan = () =>
        router.post(
            `/kelola/langganan/tagihan/${Tagihan.Uuid}/batalkan`,
            {},
            { onStart: () => AturMembatalkan(true), onFinish: () => AturMembatalkan(false) },
        );

    return (
        <TataLetakAplikasi judul={`Tagihan ${Tagihan.Nomor}`}>
            <p>
                <Link href="/kelola/langganan" className="text-label font-semibold text-brand underline">
                    Kembali ke langganan
                </Link>
            </p>
            {Tagihan.Status === 'Lunas' ? (
                <Pemberitahuan jenis="sukses" judul="Tagihan lunas">
                    Terima kasih. Langganan Anda sudah aktif sesuai periode di bawah.
                </Pemberitahuan>
            ) : null}
            <RincianTagihan tagihan={Tagihan} />
            {BolehBayarOnline ? <TombolBayarOnline uuidTagihan={Tagihan.Uuid} /> : null}
            {Tagihan.Manual &&
            RekeningTujuan.length > 0 &&
            (Tagihan.Status === 'Terbit' || Tagihan.Status === 'JatuhTempo') ? (
                <PanelTransferManual
                    uuidTagihan={Tagihan.Uuid}
                    total={Tagihan.Total}
                    rekening={RekeningTujuan}
                    bolehUnggah={BolehUnggahBukti}
                />
            ) : null}
            <RiwayatPembayaran pembayaran={Pembayaran} />
            {BolehBatalkan ? (
                <div>
                    <AlertDialog>
                        <AlertDialogTrigger asChild>
                            <Button
                                type="button"
                                variant="outline"
                                className="border-destructive text-destructive"
                                disabled={membatalkan}
                                aria-busy={membatalkan || undefined}
                            >
                                {membatalkan ? 'Memproses…' : 'Batalkan tagihan'}
                            </Button>
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>Batalkan tagihan {Tagihan.Nomor}?</AlertDialogTitle>
                                <AlertDialogDescription>
                                    Anda bisa membuat tagihan baru setelahnya.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Jangan batalkan</AlertDialogCancel>
                                <AlertDialogAction variant="destructive" onClick={Batalkan}>
                                    Batalkan tagihan
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                </div>
            ) : null}
        </TataLetakAplikasi>
    );
}

const kolomPembayaran: KolomTabel<PembayaranLangganan>[] = [
    {
        id: 'DiunggahPada',
        accessorKey: 'DiunggahPada',
        header: 'Waktu',
        meta: { label: 'Waktu', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.DiunggahPada),
    },
    {
        id: 'Metode',
        accessorKey: 'LabelMetode',
        header: 'Cara bayar',
        enableSorting: false,
        meta: { label: 'Cara bayar', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => row.original.LabelMetode,
    },
    {
        id: 'Jumlah',
        header: 'Jumlah',
        enableSorting: false,
        meta: { label: 'Jumlah', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.Jumlah),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: baris } }) => (
            <>
                <LabelStatus jenis={JenisLabelPembayaran(baris.Status)} teks={baris.LabelStatus} />
                {baris.AlasanTolak ? (
                    <span className="mt-1 block text-keterangan text-teks-sekunder">Alasan: {baris.AlasanTolak}</span>
                ) : null}
            </>
        ),
    },
];

function RiwayatPembayaran({ pembayaran }: { pembayaran: PembayaranLangganan[] }) {
    if (pembayaran.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="judul-pembayaran" className="flex flex-col gap-2">
            <h2 id="judul-pembayaran" className="text-subjudul font-semibold text-teks-utama">
                Riwayat pembayaran
            </h2>
            <TabelData
                id="langganan-riwayat-pembayaran"
                label="Riwayat pembayaran tagihan"
                kolom={kolomPembayaran}
                sumber={{ mode: 'lokal', data: pembayaran }}
                ambilIdBaris={(baris) => baris.Uuid}
                urutBawaan="-DiunggahPada"
                kosong={{ ilustrasi: true, judul: 'Belum ada pembayaran.' }}
            />
        </section>
    );
}
