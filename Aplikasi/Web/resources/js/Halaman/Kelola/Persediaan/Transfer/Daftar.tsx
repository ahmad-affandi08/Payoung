import { Link, usePage } from '@inertiajs/react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import { LabelStatusDokumen } from '@/Komponen/Persediaan/Dokumen/KomponenDokumen';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { FormatLabelGudang, FormatNilai } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisDaftarTransferStok, PropsDaftarTransferStok } from '@/Tipe/DokumenPersediaan';

export const AlamatTransfer = '/kelola/persediaan/transfer';

function Lokasi({ nama, outlet }: { nama: string; outlet: string | null }) {
    return (
        <>
            <span className="block break-words text-teks-utama">{nama}</span>
            {outlet ? <span className="block text-keterangan text-teks-sekunder">{outlet}</span> : null}
        </>
    );
}

const kolom: KolomTabel<BarisDaftarTransferStok>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: t } }) => (
            <Link
                href={`${AlamatTransfer}/${t.Uuid}`}
                className="font-mono font-semibold break-all text-brand underline"
            >
                {t.Nomor ?? 'Draf tanpa nomor'}
            </Link>
        ),
    },
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal kirim',
        meta: { label: 'Tanggal kirim', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
    },
    {
        id: 'Asal',
        header: 'Dari',
        enableSorting: false,
        meta: { label: 'Lokasi asal', prioritas: 'penting' },
        cell: ({ row: { original: t } }) => <Lokasi nama={t.NamaGudangAsal} outlet={t.NamaOutletAsal} />,
    },
    {
        id: 'Tujuan',
        header: 'Ke',
        enableSorting: false,
        meta: { label: 'Lokasi tujuan', prioritas: 'penting' },
        cell: ({ row: { original: t } }) => <Lokasi nama={t.NamaGudangTujuan} outlet={t.NamaOutletTujuan} />,
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => <LabelStatusDokumen status={row.original.Status} label={row.original.LabelStatus} />,
    },
    {
        id: 'JumlahBaris',
        header: 'Baris',
        enableSorting: false,
        meta: { label: 'Jumlah baris', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => row.original.JumlahBaris.toLocaleString('id-ID'),
    },
    {
        id: 'TotalNilaiKirim',
        accessorKey: 'TotalNilaiKirim',
        header: 'Nilai kirim',
        meta: { label: 'Nilai kirim', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => (row.original.Status === 'Draf' ? '—' : FormatNilai(row.original.TotalNilaiKirim)),
    },
];

/** F-05b: daftar transfer stok antar lokasi (TabelData D-16) dengan saring status/lokasi. */
export default function HalamanDaftarTransferStok({ Transfer, OpsiGudang, OpsiStatus, Izin }: PropsDaftarTransferStok) {
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
        <>
            <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                <Link href="/kelola/persediaan/permintaan">Permintaan stok</Link>
            </Button>
            <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                <Link href={`${AlamatTransfer}/buat?arah=retur-ke-gudang`}>Retur ke Gudang</Link>
            </Button>
            <Button asChild className="h-8 pointer-coarse:h-11">
                <Link href={`${AlamatTransfer}/buat`}>Buat transfer</Link>
            </Button>
        </>
    ) : null;

    return (
        <TataLetakAplikasi judul="Transfer stok">
            <p className="max-w-2xl text-isi text-teks-sekunder">
                Pindahkan stok antar lokasi atau antar outlet. Barang yang dikirim tercatat di lokasi dalam perjalanan
                sampai diterima. Selisih kirim dan terima dicatat sebagai susut dengan alasan.
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="persediaan.kelola" objek="transfer stok" /> : null}
            <DaftarGalatServer galat={props.errors} />
            <AksiHalaman>{tombolBuat}</AksiHalaman>
            <TabelData
                id="persediaan-transfer"
                label="Daftar transfer stok"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: AlamatTransfer, awal: Transfer }}
                ambilIdBaris={(t) => t.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor, catatan, atau nama/SKU produk"
                saring={saring}
                alamatDetail={(t) => `${AlamatTransfer}/${t.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada transfer stok.',
                    ...(tombolBuat ? {} : { aksi: <span>Minta pengelola persediaan membuat transfer.</span> }),
                }}
            />
        </TataLetakAplikasi>
    );
}
