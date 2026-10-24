import { Link } from '@inertiajs/react';

import RingkasanAngka from '@/Komponen/Akuntansi/RingkasanAngka';
import SaringLaporan, { BuatQueryLaporan } from '@/Komponen/Akuntansi/SaringLaporan';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { BandingkanDesimal } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisNeracaSaldo, KolomNeracaSaldo, PropsNeracaSaldo } from '@/Tipe/Akuntansi';

const alamat = '/kelola/akuntansi/laporan/neraca-saldo';

function KolomUang(id: KolomNeracaSaldo, label: string, prioritas: 'penting' | 'rendah'): KolomTabel<BarisNeracaSaldo> {
    return {
        id,
        accessorFn: (b) => b[id],
        // Urut angka desimal tanpa float (CLAUDE.md #7).
        sortingFn: (a, b) => BandingkanDesimal(a.original[id], b.original[id]),
        header: label,
        meta: { label, angka: true, prioritas },
        cell: ({ row }) =>
            /^0+(\.0+)?$/.test(row.original[id]) ? (
                <span className="sr-only">nol</span>
            ) : (
                FormatRupiah(row.original[id])
            ),
    };
}

/** F-13a neraca saldo (FIN-06): saldo awal, mutasi, dan saldo akhir per akun; Σ debit = Σ kredit ditampilkan. */
export default function HalamanNeracaSaldo({ Saring, Laporan, OpsiOutlet }: PropsNeracaSaldo) {
    const query = BuatQueryLaporan(Saring);
    const kolom: KolomTabel<BarisNeracaSaldo>[] = [
        {
            id: 'Kode',
            accessorKey: 'Kode',
            header: 'Kode',
            meta: { label: 'Kode', prioritas: 'penting', kelasSel: 'whitespace-nowrap font-mono' },
        },
        {
            id: 'Nama',
            accessorKey: 'Nama',
            header: 'Nama akun',
            meta: { label: 'Nama akun', prioritas: 'utama', wajib: true },
            cell: ({ row }) => (
                <Link
                    href={`/kelola/akuntansi/laporan/buku-besar?${BuatQueryLaporan({ ...Saring, Akun: row.original.Uuid })}`}
                    className="break-words text-brand underline"
                >
                    {row.original.Nama}
                </Link>
            ),
        },
        { id: 'Jenis', accessorKey: 'LabelJenis', header: 'Tipe', meta: { label: 'Tipe', prioritas: 'rendah' } },
        KolomUang('SaldoAwalDebit', 'Saldo awal debit', 'rendah'),
        KolomUang('SaldoAwalKredit', 'Saldo awal kredit', 'rendah'),
        KolomUang('Debit', 'Debit', 'penting'),
        KolomUang('Kredit', 'Kredit', 'penting'),
        KolomUang('SaldoAkhirDebit', 'Saldo akhir debit', 'penting'),
        KolomUang('SaldoAkhirKredit', 'Saldo akhir kredit', 'penting'),
    ];
    const total = Laporan.Total;

    return (
        <TataLetakAplikasi judul="Neraca saldo">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Saldo setiap akun dari jurnal: saldo awal sebelum periode, mutasi debit & kredit selama periode, dan
                saldo akhir. Karena setiap jurnal seimbang, total debit selalu sama dengan total kredit.
            </p>
            <SaringLaporan
                alamat={alamat}
                saring={Saring}
                opsiOutlet={OpsiOutlet}
                ekspor={`${alamat}/ekspor?${query}`}
            />

            {Laporan.Seimbang ? (
                <Pemberitahuan jenis="sukses" judul="Seimbang">
                    Total debit sama dengan total kredit untuk mutasi dan saldo akhir.
                </Pemberitahuan>
            ) : (
                <Pemberitahuan jenis="peringatan" judul="Tidak seimbang">
                    Total debit dan kredit berbeda untuk filter ini, biasanya karena satu jurnal memuat baris beberapa
                    outlet. Lihat tanpa filter outlet untuk memeriksa keseimbangan penuh.
                </Pemberitahuan>
            )}
            <RingkasanAngka
                label="Total neraca saldo"
                item={[
                    { label: 'Total mutasi debit', nilai: total.Debit },
                    { label: 'Total mutasi kredit', nilai: total.Kredit },
                    { label: 'Total saldo akhir debit', nilai: total.SaldoAkhirDebit, tebal: true },
                    { label: 'Total saldo akhir kredit', nilai: total.SaldoAkhirKredit, tebal: true },
                ]}
            />

            <TabelData
                id="akuntansi-neraca-saldo"
                label="Neraca saldo"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Laporan.Baris }}
                ambilIdBaris={(b) => b.Uuid}
                cari="Cari kode atau nama akun"
                kosong={{ ilustrasi: true, judul: 'Belum ada jurnal pada periode dan outlet yang dipilih.' }}
            />
        </TataLetakAplikasi>
    );
}
