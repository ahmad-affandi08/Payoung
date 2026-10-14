import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import TabDeposit from '@/Komponen/Pelanggan/TabDeposit';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisIsiDeposit, PropsIsiDeposit } from '@/Tipe/Pelanggan';

const alamat = '/kelola/pelanggan/isi-deposit';

const kolom: KolomTabel<BarisIsiDeposit>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
        cell: ({ row }) => <span className="font-mono font-semibold break-all">{row.original.Nomor}</span>,
    },
    {
        id: 'DiterimaPada',
        accessorKey: 'DiterimaPada',
        header: 'Diterima',
        meta: { label: 'Diterima', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.DiterimaPada),
    },
    {
        id: 'Pelanggan',
        header: 'Pelanggan',
        enableSorting: false,
        meta: { label: 'Pelanggan', prioritas: 'penting' },
        cell: ({ row: { original: i } }) =>
            i.Pelanggan ? (
                <Link href={`/kelola/pelanggan/${i.Pelanggan.Uuid}`} className="break-words text-brand underline">
                    {i.Pelanggan.Nama}
                </Link>
            ) : (
                'Pelanggan belum dikenal'
            ),
    },
    {
        id: 'NamaMetode',
        header: 'Metode',
        enableSorting: false,
        meta: { label: 'Metode', prioritas: 'rendah' },
        cell: ({ row }) => row.original.NamaMetode,
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: i } }) => (
            <span className="flex flex-col items-start gap-1">
                <LabelStatus jenis={i.Status === 'Diterima' ? 'sukses' : 'netral'} teks={i.LabelStatus} />
                {i.PerluTinjauan ? <LabelStatus jenis="peringatan" teks="Perlu ditinjau" /> : null}
                {i.AlasanTinjauan ? (
                    <span className="text-keterangan break-words text-teks-sekunder">{i.AlasanTinjauan}</span>
                ) : null}
                {i.AlasanBatal ? (
                    <span className="text-keterangan break-words text-teks-sekunder">Batal: {i.AlasanBatal}</span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'Jumlah',
        accessorKey: 'Jumlah',
        header: 'Jumlah',
        meta: { label: 'Jumlah', prioritas: 'utama', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.Jumlah),
    },
];

const saring: DefinisiSaring[] = [
    {
        id: 'Status',
        label: 'Status',
        jenis: 'pilihanBanyak',
        opsi: [
            { nilai: 'Diterima', label: 'Diterima' },
            { nilai: 'Dibatalkan', label: 'Dibatalkan' },
        ],
    },
    {
        id: 'PerluTinjauan',
        label: 'Tinjauan',
        jenis: 'pilihanBanyak',
        opsi: [
            { nilai: 'Ya', label: 'Perlu ditinjau' },
            { nilai: 'Tidak', label: 'Tidak perlu' },
        ],
    },
];

/** F-16d bagian 1: isi deposit pelanggan dari kasir; batal isi deposit bila uangnya dikembalikan. */
export default function HalamanIsiDeposit({ IsiDeposit, Izin }: PropsIsiDeposit) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [batal, AturBatal] = useState<BarisIsiDeposit | null>(null);
    const [alasan, AturAlasan] = useState('');
    const [memproses, AturMemproses] = useState(false);

    const KirimBatal = () => {
        if (batal === null) {
            return;
        }

        router.post(
            `${alamat}/${batal.Uuid}/batal`,
            { Alasan: alasan },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: () => {
                    AturBatal(null);
                    AturAlasan('');
                },
            },
        );
    };

    return (
        <TataLetakAplikasi judul="Deposit & gift card">
            <TabDeposit aktif="isi-deposit" />
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Saldo deposit yang diisi pelanggan di kasir. Uangnya masuk kas shift. Batalkan isi deposit hanya bila
                uangnya dikembalikan dan saldo pelanggan masih cukup.
            </p>
            <DaftarGalatServer galat={props.errors} />
            <TabelData
                id="pelanggan-isi-deposit"
                label="Daftar isi deposit"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: IsiDeposit }}
                ambilIdBaris={(i) => i.Uuid}
                urutBawaan="-DiterimaPada"
                cari="Cari nomor isi deposit"
                saring={saring}
                labelBaris={(i) => `isi deposit ${i.Nomor}`}
                {...(Izin.KelolaDeposit
                    ? {
                          aksiBaris: (i: BarisIsiDeposit) =>
                              i.Status === 'Diterima' ? (
                                  <ItemAksiBaris
                                      aksi={[{ label: 'Batalkan isi deposit', saatPilih: () => AturBatal(i) }]}
                                  />
                              ) : null,
                      }
                    : {})}
                kosong={{ ilustrasi: true, judul: 'Belum ada isi deposit dari kasir.' }}
            />

            {batal ? (
                <DialogKonfirmasi
                    judul={`Batalkan isi deposit ${batal.Nomor}?`}
                    labelAksi="Batalkan isi deposit"
                    memproses={memproses}
                    nonaktif={alasan.trim().length < 5}
                    saatKonfirmasi={KirimBatal}
                    saatBatal={() => AturBatal(null)}
                >
                    <span className="flex flex-col gap-3">
                        <span>
                            Saldo deposit pelanggan berkurang {FormatRupiah(batal.Jumlah)} dan jurnalnya dibalik.
                            Kembalikan uangnya ke pelanggan dari kas/bank.
                        </span>
                        <BidangTeks
                            label="Alasan"
                            nilai={alasan}
                            saatBerubah={AturAlasan}
                            galat={props.errors.Alasan}
                            maxLength={255}
                            required
                        />
                    </span>
                </DialogKonfirmasi>
            ) : null}
        </TataLetakAplikasi>
    );
}
