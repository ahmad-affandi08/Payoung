import RingkasanAngka from '@/Komponen/Akuntansi/RingkasanAngka';
import SaringLaporan, { BuatQueryLaporan } from '@/Komponen/Akuntansi/SaringLaporan';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { cn } from '@/Komponen/Ui/utils';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisNeraca, PropsNeraca } from '@/Tipe/Akuntansi';

const alamat = '/kelola/akuntansi/laporan/neraca';

const kelasBaris: Record<BarisNeraca['Jenis'], string> = {
    Kepala: 'font-semibold text-teks-utama',
    Akun: 'pl-4 text-teks-utama',
    Laba: 'pl-4 text-teks-utama italic',
    Subtotal: 'font-semibold text-teks-utama',
    Total: 'font-semibold text-brand',
};

/**
 * F-13 neraca (FIN-07 P1): posisi aset, kewajiban, dan ekuitas pada akhir periode dibanding awal periode. Laba yang
 * belum ditutup buku tampil di ekuitas. Urutan baris tetap (laporan bertingkat), jadi tabel tidak bisa diurutkan.
 */
export default function HalamanNeraca({ Saring, Laporan, OpsiOutlet }: PropsNeraca) {
    const { Posisi, Ringkasan } = Laporan;
    const labelAkhir = FormatTanggal(Posisi.Akhir);
    const labelAwal = FormatTanggal(Posisi.Awal);
    const kolom: KolomTabel<BarisNeraca>[] = [
        {
            id: 'Label',
            header: 'Keterangan',
            enableSorting: false,
            meta: { label: 'Keterangan', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: b } }) => (
                <span className={cn('flex flex-wrap gap-2 break-words', kelasBaris[b.Jenis])}>
                    {b.Kode ? <span className="font-mono text-teks-sekunder not-italic">{b.Kode}</span> : null}
                    {b.Label}
                </span>
            ),
        },
        {
            id: 'Nilai',
            accessorKey: 'Nilai',
            header: `Per ${labelAkhir}`,
            enableSorting: false,
            meta: {
                sembunyiBilaKosong: true,
                label: `Posisi akhir (${labelAkhir})`,
                angka: true,
                prioritas: 'penting',
            },
            cell: ({ row: { original: b } }) =>
                b.Nilai === null ? null : (
                    <span className={cn(b.Jenis !== 'Akun' && b.Jenis !== 'Laba' && 'font-semibold')}>
                        {FormatRupiah(b.Nilai)}
                    </span>
                ),
        },
        {
            id: 'NilaiAwal',
            accessorKey: 'NilaiAwal',
            header: `Per ${labelAwal}`,
            enableSorting: false,
            meta: {
                sembunyiBilaKosong: true,
                label: `Posisi awal (${labelAwal})`,
                angka: true,
                prioritas: 'penting',
                kelasSel: 'text-teks-sekunder',
            },
            cell: ({ row: { original: b } }) => (b.NilaiAwal === null ? null : FormatRupiah(b.NilaiAwal)),
        },
    ];

    return (
        <TataLetakAplikasi judul="Neraca">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Posisi harta (aset), utang (kewajiban), dan modal (ekuitas) pada akhir periode, dibanding sehari sebelum
                periode dimulai. Laba yang belum ditutup buku ikut dihitung di ekuitas.
            </p>
            <SaringLaporan
                alamat={alamat}
                saring={Saring}
                opsiOutlet={OpsiOutlet}
                ekspor={`${alamat}/ekspor?${BuatQueryLaporan(Saring)}`}
            />
            {Laporan.Seimbang ? (
                <Pemberitahuan jenis="sukses" judul="Seimbang">
                    Total aset sama dengan total kewajiban dan ekuitas pada kedua posisi.
                </Pemberitahuan>
            ) : (
                <Pemberitahuan jenis="peringatan" judul="Tidak seimbang">
                    Total aset berbeda dengan total kewajiban dan ekuitas untuk filter ini, biasanya karena satu jurnal
                    memuat baris beberapa outlet. Lihat tanpa filter outlet untuk memeriksa keseimbangan penuh.
                </Pemberitahuan>
            )}
            <RingkasanAngka
                label="Ringkasan neraca"
                item={[
                    {
                        label: 'Total aset',
                        nilai: Ringkasan.Aset.Nilai,
                        keterangan: `Awal ${FormatRupiah(Ringkasan.Aset.NilaiAwal)}`,
                    },
                    {
                        label: 'Total kewajiban',
                        nilai: Ringkasan.Kewajiban.Nilai,
                        keterangan: `Awal ${FormatRupiah(Ringkasan.Kewajiban.NilaiAwal)}`,
                    },
                    {
                        label: 'Total ekuitas',
                        nilai: Ringkasan.Ekuitas.Nilai,
                        keterangan: `Awal ${FormatRupiah(Ringkasan.Ekuitas.NilaiAwal)}`,
                    },
                    {
                        label: 'Laba tahun berjalan',
                        nilai: Ringkasan.LabaBerjalan.Nilai,
                        keterangan: `Awal ${FormatRupiah(Ringkasan.LabaBerjalan.NilaiAwal)}`,
                        tebal: true,
                    },
                ]}
            />
            <TabelData
                id="akuntansi-neraca"
                label="Neraca"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Laporan.Baris }}
                ambilIdBaris={(b) => b.Id}
                cari={false}
                kosong={{ ilustrasi: true, judul: 'Belum ada jurnal sampai tanggal ini.' }}
            />
        </TataLetakAplikasi>
    );
}
