import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, HasilTabel, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { FormatRupiah } from '@/Pustaka/Format';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisLaporanKomisi, PropsLaporanKomisi } from '@/Tipe/Karyawan';

const alamat = '/kelola/karyawan/komisi/laporan';

function KolomUang(
    id: string,
    label: string,
    ambil: (b: BarisLaporanKomisi) => string,
    urut = false,
): KolomTabel<BarisLaporanKomisi> {
    return {
        id,
        header: label,
        enableSorting: urut,
        meta: { label, angka: true, prioritas: id === 'Bersih' ? 'penting' : 'rendah' },
        cell: ({ row }) => FormatRupiah(ambil(row.original)),
    };
}

const kolom: KolomTabel<BarisLaporanKomisi>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Karyawan',
        meta: { label: 'Karyawan', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: k } }) => (
            <span className="flex flex-col">
                <span className="font-semibold break-words">{k.Nama}</span>
                {k.Jabatan ? <span className="text-keterangan text-teks-sekunder">{k.Jabatan}</span> : null}
            </span>
        ),
    },
    {
        id: 'JumlahBaris',
        header: 'Baris dilayani',
        enableSorting: false,
        meta: { label: 'Baris dilayani', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => row.original.JumlahBaris.toLocaleString('id-ID'),
    },
    KolomUang('TotalDasar', 'Nilai penjualan', (k) => k.TotalDasar),
    KolomUang('Kotor', 'Komisi', (k) => k.Kotor),
    KolomUang('Dibatalkan', 'Batal (void/retur)', (k) => k.Dibatalkan),
    KolomUang('Bersih', 'Komisi bersih', (k) => k.Bersih, true),
];

/** F-18 EMP-04: laporan komisi per karyawan per periode (belum dijurnal; dibayar lewat rekap gaji). */
export default function HalamanLaporanKomisi({ Komisi, OpsiOutlet }: PropsLaporanKomisi) {
    const saring: DefinisiSaring[] = [
        { id: 'TanggalBisnis', label: 'Tanggal', jenis: 'rentangTanggal' },
        {
            id: 'Outlet',
            label: 'Outlet',
            jenis: 'pilihanBanyak',
            opsi: OpsiOutlet.map((o) => ({ nilai: o.Uuid, label: o.Nama })),
        },
    ];

    return (
        <TataLetakAplikasi judul="Laporan komisi">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Komisi per karyawan dari baris penjualan yang dilayaninya. Void membatalkan komisi, retur memotongnya
                sebanding jumlah yang diretur. Komisi belum dijurnal sampai rekap gaji.
            </p>
            <TabelData
                id="laporan-komisi"
                label="Laporan komisi per karyawan"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Komisi }}
                ambilIdBaris={(k) => k.Uuid}
                urutBawaan="-Bersih"
                ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true, eksporBesar: true }}
                cari="Cari nama karyawan"
                saring={saring}
                ringkasan={(hasil) => (
                    <p className="text-isi">
                        Total komisi bersih:{' '}
                        <span className="font-semibold tabular-nums">
                            {FormatRupiah(
                                (hasil as HasilTabel<BarisLaporanKomisi, { Bersih: string }> | undefined)?.Ringkasan
                                    ?.Bersih ?? Komisi.Ringkasan.Bersih,
                            )}
                        </span>
                    </p>
                )}
                kosong={{ ilustrasi: true, judul: 'Belum ada komisi pada periode ini.' }}
            />
        </TataLetakAplikasi>
    );
}
