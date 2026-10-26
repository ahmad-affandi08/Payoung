import { Link, usePage } from '@inertiajs/react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import LabelStatusStokAwal from '@/Komponen/Persediaan/LabelStatusStokAwal';
import PanelKesiapanAkun from '@/Komponen/Persediaan/PanelKesiapanAkun';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { FormatLabelGudang, FormatNilai } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisDaftarStokAwal, PropsDaftarStokAwal } from '@/Tipe/Persediaan';

export const AlamatStokAwal = '/kelola/persediaan/stok-awal';

const kolom: KolomTabel<BarisDaftarStokAwal>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: dokumen } }) => (
            <>
                <Link
                    href={`${AlamatStokAwal}/${dokumen.Uuid}`}
                    className="font-mono font-semibold break-all text-brand underline"
                >
                    {dokumen.Nomor ?? 'Draf tanpa nomor'}
                </Link>
                {dokumen.Sumber === 'Impor' ? (
                    <span className="block text-keterangan font-normal text-teks-sekunder">Dari impor Excel</span>
                ) : null}
            </>
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
        id: 'Gudang',
        header: 'Lokasi stok',
        enableSorting: false,
        meta: { label: 'Lokasi stok', prioritas: 'penting' },
        cell: ({ row: { original: dokumen } }) => (
            <>
                <span className="block break-words text-teks-utama">{dokumen.NamaGudang}</span>
                {dokumen.NamaOutlet ? (
                    <span className="block text-keterangan text-teks-sekunder">{dokumen.NamaOutlet}</span>
                ) : null}
            </>
        ),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => <LabelStatusStokAwal status={row.original.Status} label={row.original.LabelStatus} />,
    },
    {
        id: 'JumlahBaris',
        header: 'Baris',
        enableSorting: false,
        meta: { label: 'Jumlah baris', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => row.original.JumlahBaris.toLocaleString('id-ID'),
    },
    {
        id: 'TotalNilai',
        accessorKey: 'TotalNilai',
        header: 'Total nilai',
        meta: { label: 'Total nilai', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatNilai(row.original.TotalNilai),
    },
    {
        id: 'DiubahPada',
        header: 'Terakhir diubah',
        enableSorting: false,
        meta: { label: 'Terakhir diubah', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: dokumen } }) => (
            <>
                {FormatTanggalWaktu(dokumen.DiubahPada)}
                {dokumen.DibuatOleh ? <span className="block text-keterangan">Dibuat {dokumen.DibuatOleh}</span> : null}
            </>
        ),
    },
];

/** F-05a: daftar dokumen stok awal (TabelData D-16) dengan saring status/lokasi dan ajakan buat/impor. */
export default function HalamanDaftarStokAwal({
    StokAwal,
    OpsiGudang,
    OpsiStatus,
    Izin,
    KesiapanAkun,
}: PropsDaftarStokAwal) {
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
            opsi: OpsiGudang.map((gudang) => ({ nilai: gudang.Uuid, label: FormatLabelGudang(gudang) })),
        },
    ];
    const tombolBuat = Izin.Kelola ? (
        <>
            <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                <Link href={`${AlamatStokAwal}/impor`}>Impor dari Excel</Link>
            </Button>
            <Button asChild className="h-8 pointer-coarse:h-11">
                <Link href={`${AlamatStokAwal}/buat`}>Buat stok awal</Link>
            </Button>
        </>
    ) : null;

    return (
        <TataLetakAplikasi judul="Stok awal">
            <p className="max-w-2xl text-isi text-teks-sekunder">
                Catat jumlah dan harga modal barang yang sudah ada saat mulai memakai aplikasi. Setelah dicatat, stok
                dan HPP tercatat serta jurnal saldo awal dibuat otomatis.
            </p>

            {Izin.PostingStokAwal ? <PanelKesiapanAkun kesiapan={KesiapanAkun} /> : null}
            {!Izin.Kelola ? <PesanHanyaLihat izin="persediaan.kelola" objek="dokumen stok awal" /> : null}
            <DaftarGalatServer galat={props.errors} />

            <AksiHalaman>{tombolBuat}</AksiHalaman>

            <TabelData
                id="persediaan-stok-awal"
                label="Daftar stok awal"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: AlamatStokAwal, awal: StokAwal }}
                ambilIdBaris={(dokumen) => dokumen.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor, catatan, atau nama/SKU produk"
                saring={saring}
                alamatDetail={(dokumen) => `${AlamatStokAwal}/${dokumen.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada stok awal. Isi stok awal agar saldo stok dan HPP benar sejak hari pertama.',
                    ...(Izin.Kelola ? {} : { aksi: <span>Minta pengelola persediaan mengisi stok awal.</span> }),
                }}
            />
        </TataLetakAplikasi>
    );
}
