import { Link } from '@inertiajs/react';

import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { KolomUang } from '@/Komponen/Laporan/KolomLaporan';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { FormatPersen, FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { Tabel } from '@/Tipe/Grosir';
import type { BarisPphDipotong, RingkasanPphDipotong } from '@/Tipe/Laporan';

const alamat = '/kelola/laporan/pajak/pph-dipotong';

const kolom: KolomTabel<BarisPphDipotong>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'No. pelunasan',
        meta: { label: 'No. pelunasan', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: d } }) => (
            <Link
                href={`/kelola/piutang/pelunasan/${d.Uuid}`}
                className="font-mono font-semibold break-all text-brand underline"
            >
                {d.Nomor}
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
        id: 'Pelanggan',
        header: 'Pelanggan (pemotong)',
        enableSorting: false,
        meta: { label: 'Pelanggan (pemotong)', prioritas: 'penting' },
        cell: ({ row: { original: d } }) => (
            <span className="break-words">
                {d.NamaPelanggan}
                <span className="block text-keterangan text-teks-sekunder">
                    {d.Npwp ? `NPWP ${d.Npwp}` : 'NPWP belum diisi'}
                </span>
            </span>
        ),
    },
    {
        id: 'Dokumen',
        header: 'Invoice',
        enableSorting: false,
        meta: { label: 'Invoice', prioritas: 'rendah' },
        cell: ({ row: { original: d } }) => (
            <span className="flex flex-col gap-0.5">
                {d.Dokumen.map((dok) => (
                    <span key={`${dok.Nomor}-${dok.PotonganPph}`} className="break-words">
                        {dok.UuidInvoice ? (
                            <Link
                                href={`/kelola/invoice/${dok.UuidInvoice}`}
                                className="font-mono text-brand underline"
                            >
                                {dok.Nomor}
                            </Link>
                        ) : (
                            <span className="font-mono">{dok.Nomor}</span>
                        )}
                        <span className="text-keterangan text-teks-sekunder">
                            {' '}
                            | {dok.JenisPph}
                            {dok.Tarif === null ? '' : ` ${FormatPersen(dok.Tarif)}`}
                        </span>
                    </span>
                ))}
            </span>
        ),
    },
    KolomUang<BarisPphDipotong>('PotonganPph', 'PPh dipotong', 'utama'),
    {
        id: 'Bukti',
        header: 'Bukti potong',
        enableSorting: false,
        meta: { label: 'Bukti potong', prioritas: 'utama' },
        cell: ({ row: { original: d } }) =>
            d.BuktiLengkap ? (
                <span className="flex flex-col gap-0.5">
                    <LabelStatus jenis="sukses" teks="Sudah ada" />
                    <span className="font-mono text-keterangan break-all">{d.NomorBuktiPotong}</span>
                </span>
            ) : (
                <LabelStatus jenis="peringatan" teks="Belum diterima" />
            ),
    },
];

const saring: DefinisiSaring[] = [
    { id: 'Tanggal', label: 'Tanggal pelunasan', jenis: 'rentangTanggal' },
    {
        id: 'Bukti',
        label: 'Bukti potong',
        jenis: 'pilihan',
        opsi: [
            { nilai: 'Belum', label: 'Belum diterima' },
            { nilai: 'Ada', label: 'Sudah ada' },
        ],
    },
];

/** Ringkasan untuk saringan yang sama dengan tabel (ikut berubah saat saringan berubah). */
function RingkasanKartu({ ringkasan }: { ringkasan: RingkasanPphDipotong | undefined }) {
    if (ringkasan === undefined) {
        return null;
    }

    return (
        <dl className="grid grid-cols-1 gap-3 rounded-panel border border-garis bg-permukaan p-4 sm:grid-cols-3">
            {(
                [
                    ['Total PPh dipotong', ringkasan.Total, `${String(ringkasan.JumlahPelunasan)} pelunasan`],
                    [
                        'Sudah ada bukti potong',
                        ringkasan.SudahBukti.Nilai,
                        `${String(ringkasan.SudahBukti.Jumlah)} pelunasan`,
                    ],
                    [
                        'Belum ada bukti potong',
                        ringkasan.BelumBukti.Nilai,
                        `${String(ringkasan.BelumBukti.Jumlah)} pelunasan | tagih dari pelanggan`,
                    ],
                ] as [string, string, string][]
            ).map(([label, nilai, keterangan]) => (
                <div key={label} className="min-w-0">
                    <dt className="text-label text-teks-sekunder">{label}</dt>
                    <dd className="text-isi font-semibold break-words text-teks-utama tabular-nums">
                        {FormatRupiah(nilai)}
                    </dd>
                    <dd className="text-keterangan text-teks-sekunder">{keterangan}</dd>
                </div>
            ))}
        </dl>
    );
}

/**
 * Laporan PPh yang dipotong pelanggan (F-21 bagian 5): pelunasan yang memotong PPh (mis. PPh 23 atas jasa) beserta nomor
 * bukti potongnya. PPh itu kredit pajak penjual dan tidak masuk kas, jadi bukti potong yang belum diterima perlu ditagih.
 * Periode = tanggal pelunasan. Angka bantu pembukuan, bukan SPT.
 */
export default function HalamanLaporanPphDipotong({ Pelunasan }: { Pelunasan: Tabel<BarisPphDipotong> }) {
    return (
        <TataLetakAplikasi
            judul="PPh dipotong pelanggan"
            jejak={[{ label: 'Laporan pajak', href: '/kelola/laporan/pajak' }]}
        >
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Pelunasan invoice yang dipotong PPh oleh pelanggan, per tanggal pelunasan. Uang PPh tidak masuk ke
                rekening Anda, tetapi menjadi kredit pajak bila Anda memegang bukti potongnya. Pelunasan yang dibatalkan
                tidak dihitung. Ini bahan bantu pembukuan, bukan SPT.
            </p>
            <AksiHalaman>
                <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                    <Link href="/kelola/laporan/pajak">Laporan pajak</Link>
                </Button>
            </AksiHalaman>
            <TabelData
                id="laporan-pph-dipotong"
                label="PPh dipotong pelanggan"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Pelunasan }}
                ambilIdBaris={(d) => d.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor pelunasan atau nomor bukti potong"
                saring={saring}
                ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true, eksporBesar: true }}
                ringkasan={(hasil) => (
                    <RingkasanKartu ringkasan={hasil?.Ringkasan as RingkasanPphDipotong | undefined} />
                )}
                alamatDetail={(d) => `/kelola/piutang/pelunasan/${d.Uuid}`}
                labelBaris={(d) => `pelunasan ${d.Nomor}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada pelunasan yang dipotong PPh.' }}
            />
        </TataLetakAplikasi>
    );
}
