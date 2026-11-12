import { router } from '@inertiajs/react';

import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisJadwalKasBank, PropsJadwalKasBank } from '@/Tipe/Akuntansi';

const alamat = '/kelola/akuntansi/kas-bank/berulang';

const kolom: KolomTabel<BarisJadwalKasBank>[] = [
    {
        id: 'Keterangan',
        accessorKey: 'Keterangan',
        header: 'Keterangan',
        enableSorting: false,
        meta: { label: 'Keterangan', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: j } }) => (
            <span className="flex flex-col gap-0.5 break-words">
                <span className="font-semibold text-teks-utama">{j.Keterangan}</span>
                <span className="text-label text-teks-sekunder">
                    {j.LabelJenis}: {j.AkunSumber} → {j.AkunTujuan}
                </span>
                {j.GalatTerakhir ? <span className="text-label text-bahaya">Gagal: {j.GalatTerakhir}</span> : null}
            </span>
        ),
    },
    {
        id: 'Frekuensi',
        header: 'Ulangi',
        enableSorting: false,
        meta: { label: 'Ulangi', prioritas: 'penting' },
        cell: ({ row }) => row.original.LabelFrekuensi,
    },
    {
        id: 'TanggalBerikutnya',
        accessorKey: 'TanggalBerikutnya',
        header: 'Berikutnya',
        meta: { label: 'Berikutnya', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => (row.original.Aktif ? FormatTanggal(row.original.TanggalBerikutnya) : '—'),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: j } }) =>
            j.Aktif ? (
                <LabelStatus jenis={j.GalatTerakhir ? 'bahaya' : 'sukses'} teks={j.GalatTerakhir ? 'Gagal' : 'Aktif'} />
            ) : (
                <LabelStatus jenis="netral" teks="Berhenti" />
            ),
    },
    {
        id: 'JumlahDicatat',
        header: 'Sudah dicatat',
        enableSorting: false,
        meta: { label: 'Sudah dicatat', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => `${String(row.original.JumlahDicatat)} kali`,
    },
    {
        id: 'Jumlah',
        accessorKey: 'Jumlah',
        header: 'Jumlah',
        meta: { label: 'Jumlah', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.Jumlah),
    },
];

/**
 * D-23 D: transaksi kas & bank berulang. Dicatat otomatis setiap pagi pada tanggal jatuh temponya (langsung dijurnal).
 * Jadwal dibuat dari formulir "Catat transaksi kas & bank" dengan pilihan "Ulangi otomatis".
 */
export default function HalamanJadwalKasBank({ Jadwal, Izin }: PropsJadwalKasBank) {
    const UbahStatus = (jadwal: BarisJadwalKasBank, aktif: boolean) =>
        router.put(`${alamat}/${jadwal.Uuid}`, { Aktif: aktif }, { preserveScroll: true });

    return (
        <TataLetakAplikasi judul="Transaksi kas & bank berulang">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Transaksi rutin seperti sewa dan listrik dicatat otomatis pada tanggalnya. Buat lewat pilihan Ulangi
                otomatis saat mencatat.
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="akuntansi.kelola" objek="jadwal transaksi berulang" /> : null}
            <TabelData
                id="akuntansi-kas-bank-berulang"
                label="Daftar transaksi berulang"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Jadwal }}
                ambilIdBaris={(j) => j.Uuid}
                urutBawaan="TanggalBerikutnya"
                cari="Cari keterangan"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: [
                            { nilai: 'Aktif', label: 'Aktif' },
                            { nilai: 'Berhenti', label: 'Berhenti' },
                        ],
                    },
                ]}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (j: BarisJadwalKasBank) =>
                              j.Aktif ? (
                                  <DropdownMenuItem variant="destructive" onSelect={() => UbahStatus(j, false)}>
                                      Hentikan jadwal
                                  </DropdownMenuItem>
                              ) : (
                                  <DropdownMenuItem onSelect={() => UbahStatus(j, true)}>
                                      Aktifkan lagi
                                  </DropdownMenuItem>
                              ),
                      }
                    : {})}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada transaksi berulang.',
                }}
            />
        </TataLetakAplikasi>
    );
}
