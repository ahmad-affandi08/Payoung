import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import DialogAturHargaOjol from '@/Komponen/Katalog/DialogAturHargaOjol';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import Panel from '@/Komponen/Kelola/Panel';
import Tombol from '@/Komponen/Formulir/Tombol';
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
export default function HalamanDaftarDaftarHarga({ DaftarHarga, Kanal, Outlet, Izin }: PropsDaftarDaftarHarga) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [aturOjol, AturAturOjol] = useState(false);

    return (
        <TataLetakAplikasi judul="Daftar harga">
            {!Izin.UbahHarga ? <PesanHanyaLihat izin="produk.harga.ubah" objek="daftar harga" /> : null}
            <DaftarGalatServer galat={props.errors} />
            {Izin.UbahHarga ? (
                <Panel
                    judul="Jualan lewat GoFood, GrabFood, atau ShopeeFood?"
                    tingkat="h2"
                    keterangan="Isi harga di platform ojol dari harga biasa dalam satu langkah, misalnya 30 persen lebih mahal. Tidak perlu membuat daftar satu per satu."
                    aksi={<Tombol onClick={() => AturAturOjol(true)}>Atur harga ojol</Tombol>}
                >
                    <p className="text-keterangan text-teks-sekunder">
                        Harga biasa di kasir tidak berubah. Anda bisa melihat contohnya dulu sebelum disimpan.
                    </p>
                </Panel>
            ) : null}
            {aturOjol ? <DialogAturHargaOjol Outlet={Outlet} saatTutup={() => AturAturOjol(false)} /> : null}
            <AksiHalaman
                keterangan={
                    <p className="max-w-2xl text-isi text-teks-sekunder">
                        Pakai daftar harga kalau harga produk perlu berbeda untuk situasi tertentu, misalnya harga di
                        GoFood, harga anggota, harga satu cabang, atau harga promo bulan ini. Produk yang tidak ada di
                        daftar memakai harga jual biasa. Kalau beberapa daftar cocok sekaligus, yang prioritasnya paling
                        besar dipakai.
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
