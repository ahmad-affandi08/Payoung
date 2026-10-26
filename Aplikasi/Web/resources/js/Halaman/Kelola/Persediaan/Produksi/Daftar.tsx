import { Link, usePage } from '@inertiajs/react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import { LabelStatusDokumen } from '@/Komponen/Persediaan/Dokumen/KomponenDokumen';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { FormatHppSatuan, FormatJumlahStok, FormatLabelGudang, FormatNilai } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisDaftarOrderProduksi, PropsDaftarOrderProduksi } from '@/Tipe/Produksi';

export const AlamatProduksi = '/kelola/persediaan/produksi';

const kolom: KolomTabel<BarisDaftarOrderProduksi>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: o } }) => (
            <Link
                href={`${AlamatProduksi}/${o.Uuid}`}
                className="font-mono font-semibold break-all text-brand underline"
            >
                {o.Nomor ?? 'Draf tanpa nomor'}
            </Link>
        ),
    },
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
    },
    {
        id: 'Produk',
        header: 'Hasil produksi',
        enableSorting: false,
        meta: { label: 'Hasil produksi', prioritas: 'penting' },
        cell: ({ row: { original: o } }) => (
            <>
                <span className="block break-words text-teks-utama">{o.NamaProduk}</span>
                <span className="block text-keterangan text-teks-sekunder">
                    {FormatJumlahStok(o.JumlahHasil)}
                    {o.NomorBatch ? ` | batch ${o.NomorBatch}` : ''}
                </span>
            </>
        ),
    },
    {
        id: 'Gudang',
        header: 'Lokasi stok',
        enableSorting: false,
        meta: { label: 'Lokasi stok', prioritas: 'rendah' },
        cell: ({ row: { original: o } }) => (
            <>
                <span className="block break-words text-teks-utama">{o.NamaGudang}</span>
                {o.NamaOutlet ? <span className="block text-keterangan text-teks-sekunder">{o.NamaOutlet}</span> : null}
            </>
        ),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => <LabelStatusDokumen status={row.original.Status} label={row.original.LabelStatus} />,
    },
    {
        id: 'NilaiHasil',
        accessorKey: 'NilaiHasil',
        header: 'Nilai hasil',
        meta: { label: 'Nilai hasil (HPP per satuan)', angka: true, prioritas: 'rendah' },
        cell: ({ row: { original: o } }) =>
            o.Status === 'Diposting' ? (
                <>
                    <span className="block">{FormatNilai(o.NilaiHasil)}</span>
                    <span className="block text-keterangan text-teks-sekunder">
                        {FormatHppSatuan(o.HppSatuanHasil)}
                    </span>
                </>
            ) : (
                'Saat dicatat'
            ),
    },
];

/** F-05e: daftar order produksi (TabelData D-16) dengan saring status & lokasi. */
export default function HalamanDaftarOrderProduksi({ Order, OpsiGudang, OpsiStatus, Izin }: PropsDaftarOrderProduksi) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const saring: DefinisiSaring[] = [
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        {
            id: 'Gudang',
            label: 'Lokasi stok',
            jenis: 'pilihan',
            opsi: OpsiGudang.map((g) => ({ nilai: g.Uuid, label: FormatLabelGudang(g) })),
        },
    ];
    const tombolBuat = Izin.Kelola ? (
        <Button asChild className="h-8 pointer-coarse:h-11">
            <Link href={`${AlamatProduksi}/buat`}>Buat order produksi</Link>
        </Button>
    ) : null;

    return (
        <TataLetakAplikasi judul="Produksi">
            <p className="max-w-2xl text-isi text-teks-sekunder">
                Catat produksi roti, kue, atau barang rakitan: bahan dikurangi dari stok sesuai resep, hasil bertambah
                dengan HPP = nilai bahan + biaya overhead.
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="persediaan.kelola" objek="order produksi" /> : null}
            <DaftarGalatServer galat={props.errors} />
            <AksiHalaman>{tombolBuat}</AksiHalaman>
            <TabelData
                id="persediaan-produksi"
                label="Daftar order produksi"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: AlamatProduksi, awal: Order }}
                ambilIdBaris={(o) => o.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor, produk, SKU, atau batch"
                saring={saring}
                alamatDetail={(o) => `${AlamatProduksi}/${o.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada order produksi.',
                    ...(tombolBuat ? {} : { aksi: <span>Minta pengelola persediaan mencatat produksi.</span> }),
                }}
            />
        </TataLetakAplikasi>
    );
}
