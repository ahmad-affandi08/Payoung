import { Link, router, usePage } from '@inertiajs/react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
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
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisDaftarHarga, PropsDaftarDaftarHarga } from '@/Tipe/Katalog';

/** Ringkasan periode berlaku: "Selalu", "Mulai …", "Sampai …", atau "… – …". */
export function RingkasPeriode(baris: Pick<BarisDaftarHarga, 'MulaiPada' | 'SelesaiPada'>): string {
    if (baris.MulaiPada === null && baris.SelesaiPada === null) {
        return 'Selalu';
    }

    if (baris.SelesaiPada === null) {
        return `Mulai ${FormatTanggalWaktu(baris.MulaiPada)}`;
    }

    if (baris.MulaiPada === null) {
        return `Sampai ${FormatTanggalWaktu(baris.SelesaiPada)}`;
    }

    return `${FormatTanggalWaktu(baris.MulaiPada)} – ${FormatTanggalWaktu(baris.SelesaiPada)}`;
}

function UbahStatusDaftar(daftar: BarisDaftarHarga) {
    router.post(
        `/kelola/daftar-harga/${daftar.Uuid}/${daftar.Aktif ? 'nonaktifkan' : 'aktifkan'}`,
        {},
        { preserveScroll: true },
    );
}

/** Aktifkan langsung; nonaktifkan lewat konfirmasi karena harga di daftar ini berhenti dipakai kasir. */
function TombolStatusDaftar({ daftar }: { daftar: BarisDaftarHarga }) {
    if (!daftar.Aktif) {
        return (
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => UbahStatusDaftar(daftar)}
                aria-label={`Aktifkan ${daftar.Nama}`}
            >
                Aktifkan
            </Button>
        );
    }

    return (
        <AlertDialog>
            <AlertDialogTrigger asChild>
                <Button type="button" variant="outline" size="sm" aria-label={`Nonaktifkan ${daftar.Nama}`}>
                    Nonaktifkan
                </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Nonaktifkan {daftar.Nama}?</AlertDialogTitle>
                    <AlertDialogDescription>
                        Kasir berhenti memakai harga di daftar ini dan kembali ke harga jual biasa atau daftar lain yang
                        cocok. Daftar bisa diaktifkan lagi kapan saja.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Batal</AlertDialogCancel>
                    <AlertDialogAction variant="destructive" onClick={() => UbahStatusDaftar(daftar)}>
                        Nonaktifkan daftar
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

const kolom: KolomTabel<BarisDaftarHarga>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Nama',
        meta: { label: 'Nama', prioritas: 'utama', wajib: true },
        cell: ({ row }) => (
            <Link
                href={`/kelola/daftar-harga/${row.original.Uuid}`}
                className="font-semibold break-words text-brand underline"
            >
                {row.original.Nama}
            </Link>
        ),
    },
    {
        id: 'BerlakuUntuk',
        header: 'Berlaku untuk',
        enableSorting: false,
        meta: { label: 'Berlaku untuk', prioritas: 'penting', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: daftar } }) =>
            [
                daftar.NamaOutlet === null ? 'Semua outlet' : daftar.NamaOutlet.join(', '),
                daftar.LabelKanal ?? 'Semua kanal',
                daftar.TierPelanggan ? `Pelanggan ${daftar.TierPelanggan}` : null,
            ]
                .filter(Boolean)
                .join(' | '),
    },
    {
        id: 'Periode',
        header: 'Periode',
        enableSorting: false,
        meta: { label: 'Periode', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row }) => RingkasPeriode(row.original),
    },
    {
        id: 'Prioritas',
        accessorKey: 'Prioritas',
        header: 'Prioritas',
        meta: { label: 'Prioritas', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => row.original.Prioritas,
    },
    {
        id: 'JumlahProduk',
        header: 'Produk',
        enableSorting: false,
        meta: { label: 'Jumlah produk', angka: true, prioritas: 'penting' },
        cell: ({ row }) => row.original.JumlahProduk,
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => (
            <LabelStatus
                jenis={row.original.Aktif ? 'sukses' : 'netral'}
                teks={row.original.Aktif ? 'Aktif' : 'Nonaktif'}
            />
        ),
    },
];

const kolomAksi: KolomTabel<BarisDaftarHarga> = {
    id: 'UbahStatus',
    header: () => <span className="sr-only">Ubah status</span>,
    enableSorting: false,
    meta: { label: 'Ubah status', wajib: true, kelasSel: 'text-right' },
    cell: ({ row }) => <TombolStatusDaftar daftar={row.original} />,
};

/** F-03 daftar harga per outlet, kanal, tingkat pelanggan, dan periode. Tidak pernah dihapus, hanya dinonaktifkan. */
export default function HalamanDaftarDaftarHarga({ DaftarHarga, Kanal, Izin }: PropsDaftarDaftarHarga) {
    const { props } = usePage<PropsBersamaAplikasi>();

    return (
        <TataLetakAplikasi judul="Daftar harga">
            {!Izin.UbahHarga ? <PesanHanyaLihat izin="produk.harga.ubah" objek="daftar harga" /> : null}
            <DaftarGalatServer galat={props.errors} />
            <AksiHalaman
                keterangan={
                    <p className="max-w-2xl text-isi text-teks-sekunder">
                        Harga khusus untuk outlet, kanal (misal online), tingkat pelanggan, atau periode tertentu. Bila
                        beberapa daftar cocok, prioritas terbesar dipakai. Bila sama, yang syaratnya lebih spesifik.
                        Produk yang tidak ada di daftar ini memakai harga jual biasa.
                    </p>
                }
            >
                {Izin.UbahHarga ? (
                    <Button asChild>
                        <Link href="/kelola/daftar-harga/buat">Buat daftar harga</Link>
                    </Button>
                ) : null}
            </AksiHalaman>

            <TabelData
                id="katalog-daftar-harga"
                label="Daftar harga"
                kolom={Izin.UbahHarga ? [...kolom, kolomAksi] : kolom}
                sumber={{ mode: 'server', alamat: '/kelola/daftar-harga', awal: DaftarHarga }}
                ambilIdBaris={(daftar) => daftar.Uuid}
                urutBawaan="-Prioritas"
                cari="Cari nama daftar harga"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihan',
                        opsi: [
                            { nilai: 'Aktif', label: 'Aktif' },
                            { nilai: 'Nonaktif', label: 'Nonaktif' },
                        ],
                    },
                    {
                        id: 'Kanal',
                        label: 'Kanal',
                        jenis: 'pilihanBanyak',
                        opsi: Kanal.map((k) => ({ nilai: k.Nilai, label: k.Label })),
                    },
                ]}
                alamatDetail={(daftar) => `/kelola/daftar-harga/${daftar.Uuid}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada daftar harga. Semua produk memakai harga jual biasa.' }}
            />
        </TataLetakAplikasi>
    );
}
