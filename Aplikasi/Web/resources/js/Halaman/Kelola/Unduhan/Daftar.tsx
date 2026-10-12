import { router, usePage } from '@inertiajs/react';
import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';

import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatUkuranBerkas } from '@/Pustaka/FormatUkuran';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisUnduhan, PropsDaftarUnduhan, StatusBerkasUnduhan } from '@/Tipe/Unduhan';

const alamat = '/kelola/unduhan';
const ID_TABEL = 'unduhan';

const jenisStatus: Record<StatusBerkasUnduhan, 'peringatan' | 'sukses' | 'bahaya' | 'netral'> = {
    Menunggu: 'peringatan',
    Diproses: 'peringatan',
    Siap: 'sukses',
    Gagal: 'bahaya',
    Kedaluwarsa: 'netral',
};

const kolom: KolomTabel<BarisUnduhan>[] = [
    {
        id: 'Judul',
        accessorKey: 'Judul',
        header: 'Laporan',
        meta: { label: 'Laporan', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: d } }) => (
            <div className="flex min-w-0 flex-col gap-0.5">
                <span className="font-semibold break-words">{d.Judul}</span>
                {d.Status === 'Gagal' && d.Galat ? (
                    <span className="text-keterangan text-bahaya break-words">{d.Galat}</span>
                ) : null}
            </div>
        ),
    },
    {
        id: 'Format',
        header: 'Format',
        enableSorting: false,
        meta: { label: 'Format', prioritas: 'penting' },
        cell: ({ row: { original: d } }) => (d.Format === 'xlsx' ? 'Excel (.xlsx)' : 'CSV'),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: d } }) => <LabelStatus jenis={jenisStatus[d.Status]} teks={d.LabelStatus} />,
    },
    {
        id: 'UkuranByte',
        accessorKey: 'UkuranByte',
        header: 'Ukuran',
        meta: { label: 'Ukuran', prioritas: 'rendah', angka: true },
        cell: ({ row: { original: d } }) =>
            d.Status === 'Siap' || d.Status === 'Kedaluwarsa' ? FormatUkuranBerkas(d.UkuranByte) : '-',
    },
    {
        id: 'DibuatPada',
        accessorKey: 'DibuatPada',
        header: 'Diminta',
        meta: { label: 'Diminta', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row: { original: d } }) => FormatTanggalWaktu(d.DibuatPada),
    },
    {
        id: 'KedaluwarsaPada',
        header: 'Tersedia sampai',
        enableSorting: false,
        meta: { label: 'Tersedia sampai', prioritas: 'rendah', kelasSel: 'whitespace-nowrap' },
        cell: ({ row: { original: d } }) => (d.BisaDiunduh ? FormatTanggalWaktu(d.KedaluwarsaPada) : '-'),
    },
];

/**
 * D-124 Pusat Unduhan: ekspor laporan besar yang disiapkan di latar belakang. Halaman memuat ulang sendiri selama masih
 * ada berkas yang disiapkan, dan tiap pengguna hanya melihat berkasnya sendiri.
 */
export default function HalamanDaftarUnduhan({ Unduhan, OpsiStatus, HariBerlaku }: PropsDaftarUnduhan) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const klienKueri = useQueryClient();
    const [dihapus, AturDihapus] = useState<BarisUnduhan | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const [adaAktif, AturAdaAktif] = useState(() =>
        Unduhan.Data.some((d) => d.Status === 'Menunggu' || d.Status === 'Diproses'),
    );

    useEffect(() => {
        if (!adaAktif) {
            return undefined;
        }

        const pewaktu = window.setInterval(
            () => void klienKueri.invalidateQueries({ queryKey: KunciKueri.TabelSemua(ID_TABEL) }),
            4000,
        );

        return () => window.clearInterval(pewaktu);
    }, [adaAktif, klienKueri]);

    const saring: DefinisiSaring[] = [
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
    ];

    return (
        <TataLetakAplikasi judul="Pusat Unduhan">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Laporan yang besar disiapkan di latar belakang, jadi Anda tidak perlu menunggu di halaman laporan. Pilih
                &quot;Ekspor besar&quot; di tombol Ekspor laporan, lalu unduh hasilnya di sini begitu statusnya Siap.
            </p>
            <Pemberitahuan jenis="info" judul="Yang perlu Anda tahu">
                Setiap berkas tersedia {HariBerlaku} hari, lalu dihapus otomatis. Berkas berisi data usaha dan hanya
                bisa diunduh oleh Anda sendiri, sesuai izin dan outlet yang Anda kelola saat meminta.
            </Pemberitahuan>
            <DaftarGalatServer galat={props.errors} />
            <TabelData
                id={ID_TABEL}
                label="Daftar berkas unduhan"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Unduhan }}
                ambilIdBaris={(d) => d.Uuid}
                urutBawaan="-DibuatPada"
                cari="Cari nama laporan"
                saring={saring}
                saatData={(baris) =>
                    AturAdaAktif(baris.some((d) => d.Status === 'Menunggu' || d.Status === 'Diproses'))
                }
                labelBaris={(d) => `unduhan ${d.Judul}`}
                aksiBaris={(d: BarisUnduhan) => (
                    <ItemAksiBaris
                        aksi={[
                            ...(d.BisaDiunduh
                                ? [
                                      {
                                          label: 'Unduh',
                                          saatPilih: () => {
                                              window.location.href = `${alamat}/${d.Uuid}/unduh`;
                                          },
                                      },
                                  ]
                                : []),
                            ...(d.Status === 'Menunggu' || d.Status === 'Diproses'
                                ? []
                                : [{ label: 'Hapus', bahaya: true, saatPilih: () => AturDihapus(d) }]),
                        ]}
                    />
                )}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada berkas unduhan. Ekspor laporan besar akan muncul di sini.',
                }}
            />
            {dihapus ? (
                <DialogKonfirmasi
                    judul={`Hapus ${dihapus.Judul}?`}
                    labelAksi="Hapus berkas"
                    memproses={memproses}
                    saatBatal={() => AturDihapus(null)}
                    saatKonfirmasi={() => {
                        AturMemproses(true);
                        router.delete(`${alamat}/${dihapus.Uuid}`, {
                            preserveScroll: true,
                            onSuccess: () => AturDihapus(null),
                            onFinish: () => AturMemproses(false),
                        });
                    }}
                >
                    <p>
                        Berkasnya dihapus dari Pusat Unduhan dan tidak bisa diunduh lagi. Laporan bisa diekspor ulang
                        kapan saja.
                    </p>
                </DialogKonfirmasi>
            ) : null}
        </TataLetakAplikasi>
    );
}
