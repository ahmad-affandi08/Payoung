import { Link } from '@inertiajs/react';

import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Badge } from '@/Komponen/Ui/badge';
import { Button } from '@/Komponen/Ui/button';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisSaldoKasBank, BarisTransaksiKasBank, PropsDaftarTransaksiKasBank } from '@/Tipe/Akuntansi';

const alamat = '/kelola/akuntansi/kas-bank';

const kolomSaldo: KolomTabel<BarisSaldoKasBank>[] = [
    {
        id: 'Akun',
        accessorFn: (a) => `${a.Kode} ${a.Nama}`,
        header: 'Akun kas/bank',
        meta: { label: 'Akun kas/bank', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: a } }) => (
            <span className="flex flex-wrap items-center gap-1.5">
                <span className="font-mono">{a.Kode}</span>
                <span className="break-words">{a.Nama}</span>
                {a.Aktif ? null : <Badge variant="outline">Nonaktif</Badge>}
            </span>
        ),
    },
    {
        id: 'Saldo',
        accessorKey: 'Saldo',
        header: 'Saldo',
        enableSorting: false,
        meta: { label: 'Saldo', angka: true, prioritas: 'utama' },
        cell: ({ row }) => FormatRupiah(row.original.Saldo),
    },
];

const kolom: KolomTabel<BarisTransaksiKasBank>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
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
        id: 'Jenis',
        header: 'Jenis',
        enableSorting: false,
        meta: { label: 'Jenis', prioritas: 'penting' },
        cell: ({ row: { original: t } }) => (
            <span className="flex flex-wrap items-center gap-1.5">
                {t.LabelJenis}
                {t.Pembalik ? <Badge variant="outline">Pembalik</Badge> : null}
                {t.Dibalik ? <Badge variant="outline">Sudah dibalik</Badge> : null}
            </span>
        ),
    },
    {
        id: 'Akun',
        header: 'Akun',
        enableSorting: false,
        meta: { label: 'Akun', prioritas: 'rendah', kelasSel: 'text-label' },
        cell: ({ row: { original: t } }) => (
            <span className="break-words">
                {t.AkunSumber} → {t.AkunTujuan}
            </span>
        ),
    },
    {
        id: 'Keterangan',
        header: 'Keterangan',
        enableSorting: false,
        meta: { label: 'Keterangan', prioritas: 'rendah' },
        cell: ({ row }) => <span className="break-words">{row.original.Keterangan}</span>,
    },
    {
        id: 'Outlet',
        header: 'Outlet',
        enableSorting: false,
        meta: { label: 'Outlet', prioritas: 'rendah' },
        cell: ({ row }) => row.original.NamaOutlet ?? 'Tingkat usaha',
    },
    {
        id: 'Jumlah',
        accessorKey: 'Jumlah',
        header: 'Jumlah',
        meta: { label: 'Jumlah', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.Jumlah),
    },
];

/** F-13a transaksi kas & bank (FIN-03): saldo per akun kas/bank dan daftar transaksi; catat baru di halaman penuh `/buat`. */
export default function HalamanTransaksiKasBank({
    Transaksi,
    Saldo,
    OpsiJenis,
    OpsiOutlet,
    Izin,
}: PropsDaftarTransaksiKasBank) {
    const saring: DefinisiSaring[] = [
        { id: 'Tanggal', label: 'Tanggal', jenis: 'rentangTanggal' },
        {
            id: 'Jenis',
            label: 'Jenis',
            jenis: 'pilihanBanyak',
            opsi: OpsiJenis.map((j) => ({ nilai: j.Nilai, label: j.Label })),
        },
        ...(OpsiOutlet.length > 1
            ? [
                  {
                      id: 'Outlet',
                      label: 'Outlet',
                      jenis: 'pilihanBanyak' as const,
                      opsi: OpsiOutlet.map((o) => ({ nilai: o.Uuid, label: o.Nama })),
                  },
              ]
            : []),
    ];

    return (
        <TataLetakAplikasi judul="Kas & bank">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Catat pengeluaran operasional, penerimaan di luar penjualan, dan transfer antar kas/bank. Setiap
                transaksi langsung dijurnal. Transaksi yang sudah disimpan tidak bisa diubah. Koreksi dengan dokumen
                pembalik.
            </p>

            <TabelData
                id="akuntansi-saldo-kas-bank"
                label="Saldo kas & bank"
                kolom={kolomSaldo}
                sumber={{ mode: 'lokal', data: Saldo }}
                ambilIdBaris={(a) => a.Uuid}
                cari={false}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada akun kas/bank. Tandai akun kas atau bank di Bagan akun.',
                }}
            />

            {Izin.Kelola ? (
                <div className="flex flex-wrap gap-2">
                    <Button asChild>
                        <Link href={`${alamat}/buat`}>Catat transaksi kas & bank</Link>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href={`${alamat}/berulang`}>Transaksi berulang</Link>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href="/kelola/akuntansi/rekonsiliasi">Rekonsiliasi bank</Link>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href="/kelola/akuntansi/giro">Giro & cek mundur</Link>
                    </Button>
                </div>
            ) : (
                <>
                    <PesanHanyaLihat izin="akuntansi.kelola" objek="transaksi kas & bank" />
                    <div>
                        <Button asChild variant="outline">
                            <Link href="/kelola/akuntansi/rekonsiliasi">Rekonsiliasi bank</Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/kelola/akuntansi/giro">Giro & cek mundur</Link>
                        </Button>
                    </div>
                </>
            )}

            <TabelData
                id="akuntansi-kas-bank"
                label="Daftar transaksi kas & bank"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Transaksi }}
                ambilIdBaris={(t) => t.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor atau keterangan"
                saring={saring}
                alamatDetail={(t) => `${alamat}/${t.Uuid}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada transaksi kas & bank.' }}
            />
        </TataLetakAplikasi>
    );
}
