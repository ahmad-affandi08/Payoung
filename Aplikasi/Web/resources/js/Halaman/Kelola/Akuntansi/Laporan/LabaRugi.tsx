import { Link } from '@inertiajs/react';

import RingkasanAngka from '@/Komponen/Akuntansi/RingkasanAngka';
import SaringLaporan, { BuatQueryLaporan } from '@/Komponen/Akuntansi/SaringLaporan';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { cn } from '@/Komponen/Ui/utils';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisLabaRugi, PropsLabaRugi } from '@/Tipe/Akuntansi';

const alamat = '/kelola/akuntansi/laporan/laba-rugi';

function Rentang(dari: string, sampai: string): string {
    return dari === sampai ? FormatTanggal(dari) : `${FormatTanggal(dari)} – ${FormatTanggal(sampai)}`;
}

const kelasBaris: Record<BarisLabaRugi['Jenis'], string> = {
    Kepala: 'font-semibold text-teks-utama',
    Akun: 'pl-4 text-teks-utama',
    Subtotal: 'font-semibold text-teks-utama',
    Laba: 'font-semibold text-brand',
};

/**
 * F-13a laba rugi (FIN-07): pendapatan − HPP = laba kotor − beban = laba bersih per kelompok tipe akun, dibanding
 * periode sebelumnya yang sama panjang. Urutan baris tetap (laporan bertingkat), jadi tabel tidak bisa diurutkan.
 */
export default function HalamanLabaRugi({ Saring, Laporan, OpsiOutlet }: PropsLabaRugi) {
    const { Periode, Ringkasan } = Laporan;
    const labelKini = Rentang(Periode.Dari, Periode.Sampai);
    const labelLalu = Rentang(Periode.DariSebelumnya, Periode.SampaiSebelumnya);
    const kolom: KolomTabel<BarisLabaRugi>[] = [
        {
            id: 'Label',
            header: 'Keterangan',
            enableSorting: false,
            meta: { label: 'Keterangan', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: b } }) => (
                <span className={cn('flex flex-wrap gap-2 break-words', kelasBaris[b.Jenis])}>
                    {b.Kode ? <span className="font-mono text-teks-sekunder">{b.Kode}</span> : null}
                    {b.Label}
                </span>
            ),
        },
        {
            id: 'Nilai',
            accessorKey: 'Nilai',
            header: labelKini,
            enableSorting: false,
            meta: {
                sembunyiBilaKosong: true,
                label: `Periode ini (${labelKini})`,
                angka: true,
                prioritas: 'penting',
            },
            cell: ({ row: { original: b } }) =>
                b.Nilai === null ? null : (
                    <span className={cn(b.Jenis !== 'Akun' && 'font-semibold')}>{FormatRupiah(b.Nilai)}</span>
                ),
        },
        {
            id: 'NilaiSebelumnya',
            accessorKey: 'NilaiSebelumnya',
            header: labelLalu,
            enableSorting: false,
            meta: {
                sembunyiBilaKosong: true,
                label: `Periode sebelumnya (${labelLalu})`,
                angka: true,
                prioritas: 'penting',
                kelasSel: 'text-teks-sekunder',
            },
            cell: ({ row: { original: b } }) => (b.NilaiSebelumnya === null ? null : FormatRupiah(b.NilaiSebelumnya)),
        },
    ];

    return (
        <TataLetakAplikasi judul="Laba rugi">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Pendapatan dikurangi harga pokok penjualan (HPP) menjadi laba kotor, lalu dikurangi beban menjadi laba
                bersih. Kolom kanan membandingkan dengan periode sebelumnya yang sama panjang. Untuk membandingkan
                dengan target, buka{' '}
                <Link
                    href="/kelola/akuntansi/anggaran"
                    className="font-semibold text-brand underline underline-offset-2"
                >
                    Anggaran vs realisasi
                </Link>
                .
            </p>
            <SaringLaporan
                alamat={alamat}
                saring={Saring}
                opsiOutlet={OpsiOutlet}
                ekspor={`${alamat}/ekspor?${BuatQueryLaporan(Saring)}`}
            />
            <RingkasanAngka
                label="Ringkasan laba rugi"
                item={[
                    {
                        label: 'Pendapatan',
                        nilai: Ringkasan.Pendapatan.Nilai,
                        keterangan: `Sebelumnya ${FormatRupiah(Ringkasan.Pendapatan.NilaiSebelumnya)}`,
                    },
                    {
                        label: 'Laba kotor',
                        nilai: Ringkasan.LabaKotor.Nilai,
                        keterangan: `Sebelumnya ${FormatRupiah(Ringkasan.LabaKotor.NilaiSebelumnya)}`,
                    },
                    {
                        label: 'Beban',
                        nilai: Ringkasan.Beban.Nilai,
                        keterangan: `Sebelumnya ${FormatRupiah(Ringkasan.Beban.NilaiSebelumnya)}`,
                    },
                    {
                        label: 'Laba bersih',
                        nilai: Ringkasan.LabaBersih.Nilai,
                        keterangan: `Sebelumnya ${FormatRupiah(Ringkasan.LabaBersih.NilaiSebelumnya)}`,
                        tebal: true,
                    },
                ]}
            />
            <TabelData
                id="akuntansi-laba-rugi"
                label="Laba rugi"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Laporan.Baris }}
                ambilIdBaris={(b) => b.Id}
                cari={false}
                kosong={{ ilustrasi: true, judul: 'Belum ada jurnal pendapatan, HPP, atau beban.' }}
            />
        </TataLetakAplikasi>
    );
}
