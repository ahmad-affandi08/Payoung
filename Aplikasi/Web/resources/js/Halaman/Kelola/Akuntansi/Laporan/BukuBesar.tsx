import { Link } from '@inertiajs/react';

import RingkasanAngka from '@/Komponen/Akuntansi/RingkasanAngka';
import SaringLaporan, { BuatQueryLaporan } from '@/Komponen/Akuntansi/SaringLaporan';
import KeadaanKosong from '@/Komponen/Katalog/KeadaanKosong';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisBukuBesar, PropsBukuBesar, RingkasanBukuBesar } from '@/Tipe/Akuntansi';

const alamat = '/kelola/akuntansi/laporan/buku-besar';

/** Sisi nol ditampilkan kosong agar sisi yang terisi mudah dibaca. */
function Uang({ nilai }: { nilai: string }) {
    return /^-?0+(\.0+)?$/.test(nilai) ? <span className="sr-only">nol</span> : <>{FormatRupiah(nilai)}</>;
}

const kolom: KolomTabel<BarisBukuBesar>[] = [
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal',
        enableSorting: false,
        meta: { label: 'Tanggal', prioritas: 'penting', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
    },
    {
        id: 'NomorJurnal',
        accessorKey: 'NomorJurnal',
        header: 'Jurnal',
        enableSorting: false,
        meta: { label: 'Jurnal', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => (
            <Link
                href={`/kelola/akuntansi/jurnal/${row.original.UuidJurnal}`}
                className="font-mono font-semibold text-brand underline"
            >
                {row.original.NomorJurnal}
            </Link>
        ),
    },
    {
        id: 'Keterangan',
        header: 'Keterangan',
        enableSorting: false,
        meta: { label: 'Keterangan', prioritas: 'rendah' },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block break-words text-teks-utama">{b.Memo ?? b.Keterangan}</span>
                <span className="text-label text-teks-sekunder">
                    {b.LabelSumber}
                    {b.NomorSumber ? ' | ' : ''}
                    {b.NomorSumber && b.TautanSumber ? (
                        <Link href={b.TautanSumber} className="font-mono break-all text-brand underline">
                            {b.NomorSumber}
                        </Link>
                    ) : (
                        <span className="font-mono">{b.NomorSumber}</span>
                    )}
                </span>
            </>
        ),
    },
    {
        id: 'Outlet',
        header: 'Outlet',
        enableSorting: false,
        meta: { label: 'Outlet', prioritas: 'rendah' },
        cell: ({ row }) => row.original.NamaOutlet ?? 'Tingkat usaha',
    },
    {
        id: 'Debit',
        header: 'Debit',
        enableSorting: false,
        meta: { label: 'Debit', angka: true, prioritas: 'penting' },
        cell: ({ row }) => <Uang nilai={row.original.Debit} />,
    },
    {
        id: 'Kredit',
        header: 'Kredit',
        enableSorting: false,
        meta: { label: 'Kredit', angka: true, prioritas: 'penting' },
        cell: ({ row }) => <Uang nilai={row.original.Kredit} />,
    },
    {
        id: 'Saldo',
        header: 'Saldo',
        enableSorting: false,
        meta: { label: 'Saldo', angka: true, prioritas: 'penting', kelasSel: 'font-semibold' },
        cell: ({ row }) => FormatRupiah(row.original.Saldo),
    },
];

/** F-13a buku besar (FIN-06): saldo awal, mutasi, dan saldo berjalan satu akun per periode & outlet. */
export default function HalamanBukuBesar({ Saring, Akun, Mutasi, OpsiAkun, OpsiOutlet }: PropsBukuBesar) {
    return (
        <TataLetakAplikasi judul={Akun ? `Buku besar ${Akun.Kode} ${Akun.Nama}` : 'Buku besar'}>
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Semua jurnal satu akun dengan saldo berjalan. Saldo positif berarti saldo di sisi normal akun (
                {Akun ? Akun.SaldoNormal.toLowerCase() : 'debit atau kredit'}).
            </p>
            <SaringLaporan
                alamat={alamat}
                saring={Saring}
                opsiOutlet={OpsiOutlet}
                opsiAkun={OpsiAkun}
                ekspor={Akun ? `${alamat}/ekspor?${BuatQueryLaporan(Saring)}` : null}
                eksporBesar
            />

            {Akun === null ? (
                <KeadaanKosong judul="Pilih akun untuk melihat buku besarnya." />
            ) : (
                <TabelData
                    id="akuntansi-buku-besar"
                    label={`Buku besar ${Akun.Kode}`}
                    kolom={kolom}
                    sumber={{ mode: 'server', alamat, awal: Mutasi }}
                    ambilIdBaris={(b) => b.Id}
                    cari={false}
                    ringkasan={(hasil) => {
                        const r = (hasil?.Ringkasan ?? Mutasi.Ringkasan) as RingkasanBukuBesar | undefined;

                        return r ? (
                            <RingkasanAngka
                                label="Ringkasan buku besar"
                                item={[
                                    { label: 'Saldo awal', nilai: r.SaldoAwal },
                                    { label: 'Total debit', nilai: r.TotalDebit },
                                    { label: 'Total kredit', nilai: r.TotalKredit },
                                    { label: 'Saldo akhir', nilai: r.SaldoAkhir, tebal: true },
                                ]}
                            />
                        ) : null;
                    }}
                    kosong={{
                        ilustrasi: true,
                        judul: 'Tidak ada jurnal akun ini pada periode dan outlet yang dipilih.',
                    }}
                />
            )}
        </TataLetakAplikasi>
    );
}
