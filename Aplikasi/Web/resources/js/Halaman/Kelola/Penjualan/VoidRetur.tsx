import { Link } from '@inertiajs/react';

import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatDurasi, FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisVoidRetur, PropsDaftarVoidRetur } from '@/Tipe/Penjualan';

const alamat = '/kelola/penjualan/void-retur';

/** Jeda di bawah 5 menit setelah bayar ditandai: pola void/retur segera setelah bayar (BR-09.3). */
const BATAS_JEDA_SINGKAT = 300;

const kolom: KolomTabel<BarisVoidRetur>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        enableSorting: false,
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <>
                <Link href={b.Tautan} className="font-mono font-semibold break-all text-brand underline">
                    {b.Nomor}
                </Link>
                {b.Jenis === 'Retur' ? (
                    <span className="block text-label break-all text-teks-sekunder">
                        {b.TanpaStruk ? (
                            'Tanpa struk'
                        ) : (
                            <>
                                Penjualan asal <span className="font-mono">{b.NomorPenjualan}</span>
                            </>
                        )}
                    </span>
                ) : null}
            </>
        ),
    },
    {
        id: 'Jenis',
        header: 'Jenis',
        enableSorting: false,
        meta: { label: 'Jenis', prioritas: 'penting' },
        cell: ({ row: { original: b } }) =>
            b.Jenis === 'Void' ? (
                <LabelStatus jenis="bahaya" teks="Void" />
            ) : (
                <LabelStatus jenis="netral" teks="Retur" />
            ),
    },
    {
        id: 'Waktu',
        accessorKey: 'Waktu',
        header: 'Waktu',
        meta: { label: 'Waktu', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block">{FormatTanggalWaktu(b.Waktu)}</span>
                <span className="block text-label text-teks-sekunder">
                    Hari bisnis {FormatTanggal(b.TanggalBisnis)}
                </span>
            </>
        ),
    },
    {
        id: 'Kasir',
        header: 'Kasir & penyetuju',
        enableSorting: false,
        meta: { label: 'Kasir & penyetuju', prioritas: 'rendah' },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block text-teks-utama">{b.NamaKasir}</span>
                <span className="block text-label text-teks-sekunder">Disetujui {b.NamaPenyetuju}</span>
                <span className="block text-label text-teks-sekunder">{b.NamaOutlet}</span>
            </>
        ),
    },
    {
        id: 'Alasan',
        header: 'Alasan',
        enableSorting: false,
        meta: { label: 'Alasan', prioritas: 'rendah' },
        cell: ({ row: { original: b } }) => <span className="break-words">{b.Alasan}</span>,
    },
    {
        id: 'JedaDetik',
        accessorKey: 'JedaDetik',
        header: 'Jeda sejak bayar',
        meta: { label: 'Jeda sejak bayar', angka: true, prioritas: 'rendah', kelasSel: 'whitespace-nowrap' },
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col items-end gap-1">
                <span>{FormatDurasi(b.JedaDetik)}</span>
                {b.JedaDetik < BATAS_JEDA_SINGKAT ? (
                    <LabelStatus jenis="peringatan" teks="Segera setelah bayar" />
                ) : null}
            </span>
        ),
    },
    {
        id: 'Nominal',
        accessorKey: 'Nominal',
        header: 'Nominal',
        meta: { label: 'Nominal', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block font-semibold">{FormatRupiah(b.Nominal)}</span>
                {/^0+(\.0+)?$/.test(b.RefundTunai) ? null : (
                    <span className="block text-label text-teks-sekunder">Tunai {FormatRupiah(b.RefundTunai)}</span>
                )}
            </>
        ),
    },
];

/**
 * F-09 fase 1: daftar void & retur dari aplikasi POS (baca saja), dasar laporan anti-fraud BR-09.3. Analisis pola per
 * kasir/jam menyusul (F-14).
 */
export default function HalamanDaftarVoidRetur({ VoidRetur, OpsiOutlet }: PropsDaftarVoidRetur) {
    const saring: DefinisiSaring[] = [
        {
            id: 'Jenis',
            label: 'Jenis',
            jenis: 'pilihanBanyak',
            opsi: [
                { nilai: 'Void', label: 'Void' },
                { nilai: 'Retur', label: 'Retur' },
            ],
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
        { id: 'TanggalBisnis', label: 'Hari bisnis', jenis: 'rentangTanggal' },
    ];

    return (
        <TataLetakAplikasi judul="Void & retur">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Void membatalkan seluruh transaksi di shift yang sama; retur mengembalikan sebagian atau semua barang
                dengan refund tunai atau transfer. Keduanya butuh PIN penyetuju. Perhatikan void atau retur yang terjadi
                segera setelah bayar tunai.
            </p>

            <TabelData
                id="void-retur"
                label="Daftar void dan retur"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: VoidRetur }}
                ambilIdBaris={(baris) => baris.Kunci}
                urutBawaan="-Waktu"
                cari="Cari nomor atau nama kasir"
                saring={saring}
                alamatDetail={(baris) => baris.Tautan}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada void atau retur. Void dan retur dari aplikasi POS muncul di sini setelah kasirnya tersambung internet.',
                }}
            />
        </TataLetakAplikasi>
    );
}
