import { Link, usePage } from '@inertiajs/react';

import PenandaJurnal from '@/Komponen/Akuntansi/PenandaJurnal';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisDaftarJurnal, PropsDaftarJurnal } from '@/Tipe/Akuntansi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

const alamat = '/kelola/akuntansi/jurnal';

const kolom: KolomTabel<BarisDaftarJurnal>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor jurnal', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => (
            <Link href={`${alamat}/${row.original.Uuid}`} className="font-mono font-semibold text-brand underline">
                {row.original.Nomor}
            </Link>
        ),
    },
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'penting', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
    },
    {
        id: 'JenisSumber',
        header: 'Sumber',
        enableSorting: false,
        meta: { label: 'Sumber', prioritas: 'penting' },
        cell: ({ row: { original: jurnal } }) => (
            <>
                <span className="block text-teks-utama">{jurnal.LabelJenisSumber}</span>
                {jurnal.NomorSumber ? (
                    jurnal.TautanSumber ? (
                        <Link
                            href={jurnal.TautanSumber}
                            className="font-mono text-label break-all text-brand underline"
                        >
                            {jurnal.NomorSumber}
                        </Link>
                    ) : (
                        <span className="font-mono text-label break-all text-teks-sekunder">{jurnal.NomorSumber}</span>
                    )
                ) : null}
            </>
        ),
    },
    {
        id: 'Keterangan',
        header: 'Keterangan',
        enableSorting: false,
        meta: { label: 'Keterangan', prioritas: 'rendah' },
        cell: ({ row }) => (
            <>
                <span className="block break-words text-teks-utama">{row.original.Keterangan}</span>
                <PenandaJurnal jurnal={row.original} />
            </>
        ),
    },
    {
        id: 'TotalDebit',
        header: 'Nilai',
        enableSorting: false,
        meta: { label: 'Nilai', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.TotalDebit),
    },
];

/**
 * F-05a: daftar jurnal (TabelData D-16), terbaru di atas. Jurnal dibuat sistem dari dokumen, atau oleh Akuntan/Pemilik
 * lewat jurnal umum manual (FIN-05); koreksi selalu lewat pembalik.
 */
export default function HalamanDaftarJurnal({ Jurnal, OpsiJenisSumber, Izin }: PropsDaftarJurnal) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const saring: DefinisiSaring[] = [
        { id: 'Tanggal', label: 'Tanggal', jenis: 'rentangTanggal' },
        {
            id: 'JenisSumber',
            label: 'Sumber',
            jenis: 'pilihanBanyak',
            opsi: OpsiJenisSumber.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
    ];

    return (
        <TataLetakAplikasi judul="Jurnal">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Jurnal dibuat otomatis dari dokumen seperti penjualan, pembelian, dan stok awal. Jurnal yang sudah
                diposting tidak bisa diubah; koreksi dilakukan dengan membatalkan dokumen sumbernya sehingga terbentuk
                jurnal pembalik. Untuk pencatatan yang tidak punya dokumen sendiri, misalnya penyesuaian akhir bulan,
                catat jurnal umum.
            </p>
            {Izin.Kelola ? (
                <AksiHalaman>
                    <Button asChild>
                        <Link href="/kelola/akuntansi/jurnal-umum/buat">Catat jurnal umum</Link>
                    </Button>
                </AksiHalaman>
            ) : null}
            <DaftarGalatServer galat={props.errors} />

            <TabelData
                id="akuntansi-jurnal"
                label="Daftar jurnal"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Jurnal }}
                ambilIdBaris={(jurnal) => jurnal.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor jurnal, nomor dokumen, atau keterangan"
                saring={saring}
                alamatDetail={(jurnal) => `${alamat}/${jurnal.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada jurnal. Jurnal terbentuk otomatis saat stok awal diposting.',
                    aksi: (
                        <Button asChild variant="outline">
                            <Link href="/kelola/persediaan/stok-awal">Buka stok awal</Link>
                        </Button>
                    ),
                }}
            />
        </TataLetakAplikasi>
    );
}
