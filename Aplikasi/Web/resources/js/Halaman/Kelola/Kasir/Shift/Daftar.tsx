import { Link } from '@inertiajs/react';

import LencanaShift from '@/Komponen/Kasir/LencanaShift';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { FormatRupiah } from '@/Pustaka/Format';
import { AmbilTandaDesimal } from '@/Pustaka/HitungDesimal';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisShift, PropsDaftarShift } from '@/Tipe/Kasir';

const alamat = '/kelola/kasir/shift';

const kolom: KolomTabel<BarisShift>[] = [
    {
        id: 'DibukaPada',
        accessorKey: 'DibukaPada',
        header: 'Dibuka',
        meta: { label: 'Dibuka', prioritas: 'utama', wajib: true },
        cell: ({ row }) => (
            <>
                <Link href={`${alamat}/${row.original.Uuid}`} className="font-semibold text-brand underline">
                    {FormatTanggalWaktu(row.original.DibukaPada)}
                </Link>
                <span className="block text-label text-teks-sekunder">{row.original.NamaOutlet}</span>
            </>
        ),
    },
    {
        id: 'TanggalBisnis',
        accessorKey: 'TanggalBisnis',
        header: 'Hari bisnis',
        meta: { label: 'Hari bisnis', prioritas: 'rendah' },
        cell: ({ row }) => <span className="whitespace-nowrap">{FormatTanggal(row.original.TanggalBisnis)}</span>,
    },
    {
        id: 'Kasir',
        header: 'Kasir & perangkat',
        enableSorting: false,
        meta: { label: 'Kasir & perangkat', prioritas: 'penting' },
        cell: ({ row }) => (
            <>
                <span className="block text-teks-utama">{row.original.NamaKasir}</span>
                <span className="block font-mono text-label text-teks-sekunder">{row.original.Perangkat}</span>
            </>
        ),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => (
            <LencanaShift
                status={row.original.Status}
                label={row.original.LabelStatus}
                perluTinjauan={row.original.PerluTinjauan}
                bersama={row.original.Bersama}
            />
        ),
    },
    {
        id: 'KasAwal',
        accessorKey: 'KasAwal',
        header: 'Kas awal',
        meta: { label: 'Kas awal', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.KasAwal),
    },
    {
        id: 'TotalMasuk',
        header: 'Masuk',
        enableSorting: false,
        meta: { label: 'Kas masuk', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatRupiah(row.original.TotalMasuk),
    },
    {
        id: 'TotalKeluar',
        header: 'Keluar',
        enableSorting: false,
        meta: { label: 'Kas keluar', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatRupiah(row.original.TotalKeluar),
    },
    {
        id: 'TotalSetoran',
        header: 'Setoran',
        enableSorting: false,
        meta: { label: 'Setoran', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatRupiah(row.original.TotalSetoran),
    },
    {
        id: 'Selisih',
        accessorKey: 'Selisih',
        header: 'Selisih kas',
        meta: { label: 'Selisih kas', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: s } }) =>
            s.Selisih === null ? (
                <span className="text-teks-sekunder">Belum ditutup</span>
            ) : (
                <span className={AmbilTandaDesimal(s.Selisih) === 0 ? 'text-teks-utama' : 'font-semibold text-bahaya'}>
                    {AmbilTandaDesimal(s.Selisih) > 0 ? '+' : ''}
                    {FormatRupiah(s.Selisih)}
                </span>
            ),
    },
];

/** F-06: daftar shift kasir (baca saja). Shift dibuka, diisi, dan ditutup (F-11) dari aplikasi kasir. */
export default function HalamanDaftarShift({ Shift, OpsiOutlet, OpsiStatus }: PropsDaftarShift) {
    const saring: DefinisiSaring[] = [
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
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        { id: 'TanggalBisnis', label: 'Hari bisnis', jenis: 'rentangTanggal' },
        { id: 'PerluTinjauan', label: 'Perlu ditinjau', jenis: 'ya', labelAktif: 'Hanya yang perlu ditinjau' },
    ];

    return (
        <TataLetakAplikasi judul="Shift kasir">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Shift dibuka kasir di aplikasi POS, termasuk saat offline, lalu terkirim ke sini begitu perangkat
                online. Kas masuk, kas keluar, setoran, dan selisih kas saat tutup shift tercatat per shift beserta
                jurnalnya.
            </p>

            <TabelData
                id="kasir-shift"
                label="Daftar shift"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Shift }}
                ambilIdBaris={(baris) => baris.Uuid}
                urutBawaan="-DibukaPada"
                cari="Cari nama kasir"
                saring={saring}
                alamatDetail={(baris) => `${alamat}/${baris.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada shift. Shift muncul di sini setelah kasir membuka shift di aplikasi POS dan kasirnya tersambung internet.',
                }}
            />
        </TataLetakAplikasi>
    );
}
