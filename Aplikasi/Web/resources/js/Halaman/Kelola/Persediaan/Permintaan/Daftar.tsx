import { Link, usePage } from '@inertiajs/react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import { LabelStatusDokumen } from '@/Komponen/Persediaan/Dokumen/KomponenDokumen';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatLabelGudang } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisDaftarPermintaanStok, PropsDaftarPermintaanStok } from '@/Tipe/DokumenPersediaan';

export const AlamatPermintaan = '/kelola/persediaan/permintaan';

function Lokasi({ nama, outlet }: { nama: string; outlet: string | null }) {
    return (
        <>
            <span className="block break-words text-teks-utama">{nama}</span>
            {outlet ? <span className="block text-keterangan text-teks-sekunder">{outlet}</span> : null}
        </>
    );
}

const kolom: KolomTabel<BarisDaftarPermintaanStok>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: p } }) => (
            <Link
                href={`${AlamatPermintaan}/${p.Uuid}`}
                className="font-mono font-semibold break-all text-brand underline"
            >
                {p.Nomor}
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
        id: 'Tujuan',
        header: 'Diminta oleh',
        enableSorting: false,
        meta: { label: 'Diminta oleh', prioritas: 'penting' },
        cell: ({ row: { original: p } }) => <Lokasi nama={p.NamaGudangTujuan} outlet={p.NamaOutletTujuan} />,
    },
    {
        id: 'Asal',
        header: 'Diminta dari',
        enableSorting: false,
        meta: { label: 'Diminta dari', prioritas: 'penting' },
        cell: ({ row: { original: p } }) => <Lokasi nama={p.NamaGudangAsal} outlet={p.NamaOutletAsal} />,
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: p } }) => (
            <span className="flex flex-wrap items-center gap-1">
                <LabelStatusDokumen status={p.Status} label={p.LabelStatus} />
                {p.PerluDikirim ? <LabelStatus jenis="peringatan" teks="Perlu dikirim" /> : null}
            </span>
        ),
    },
    {
        id: 'JumlahBaris',
        header: 'Baris',
        enableSorting: false,
        meta: { label: 'Jumlah baris', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => row.original.JumlahBaris.toLocaleString('id-ID'),
    },
];

/**
 * F-05b, D-105: daftar permintaan stok. Outlet yang butuh barang meminta ke lokasi pengirim (mis. gudang utama); pengirim
 * menjawabnya dengan transfer. Saring "Arah": masuk (diminta ke outlet saya) atau keluar (saya yang meminta).
 */
export default function HalamanDaftarPermintaanStok({
    Permintaan,
    OpsiGudang,
    OpsiStatus,
    Izin,
}: PropsDaftarPermintaanStok) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const saring: DefinisiSaring[] = [
        {
            id: 'Arah',
            label: 'Arah',
            jenis: 'pilihan',
            opsi: [
                { nilai: 'Masuk', label: 'Diminta ke outlet saya' },
                { nilai: 'Keluar', label: 'Diminta oleh outlet saya' },
            ],
        },
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
    const tombol = Izin.Kelola ? (
        <>
            <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                <Link href="/kelola/persediaan/transfer">Transfer stok</Link>
            </Button>
            <Button asChild className="h-8 pointer-coarse:h-11">
                <Link href={`${AlamatPermintaan}/buat`}>Minta stok</Link>
            </Button>
        </>
    ) : null;

    return (
        <TataLetakAplikasi judul="Permintaan stok">
            <p className="max-w-2xl text-isi text-teks-sekunder">
                Outlet yang kehabisan barang meminta stok ke gudang utama atau outlet lain. Pengirim melihat permintaan
                yang perlu dikirim, lalu menjawabnya dengan transfer; stok baru berpindah saat transfer dikirim.
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="persediaan.kelola" objek="permintaan stok" /> : null}
            <DaftarGalatServer galat={props.errors} />
            <AksiHalaman>{tombol}</AksiHalaman>
            <TabelData
                id="persediaan-permintaan"
                label="Daftar permintaan stok"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: AlamatPermintaan, awal: Permintaan }}
                ambilIdBaris={(p) => p.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor, catatan, atau nama/SKU produk"
                saring={saring}
                alamatDetail={(p) => `${AlamatPermintaan}/${p.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada permintaan stok.',
                    ...(tombol ? {} : { aksi: <span>Minta pengelola persediaan mengajukan permintaan.</span> }),
                }}
            />
        </TataLetakAplikasi>
    );
}
