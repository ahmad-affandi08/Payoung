import { Link } from '@inertiajs/react';

import { jenisLabelStatusTiket, type StatusTiket } from '@/Komponen/Dukungan/StatusTiket';
import AksiMassalTiket from '@/Komponen/Pengelola/AksiMassalTiket';
import TabelData, { type KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { HasilTabel, KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import type { Pilihan } from '@/Tipe/Pengelola';

type BarisAntrean = {
    Uuid: string;
    Nomor: string;
    Judul: string;
    NamaTenant: string;
    Kategori: string;
    Prioritas: 'Mendesak' | 'Tinggi' | 'Normal' | 'Rendah';
    LabelPrioritas: string;
    Status: StatusTiket;
    LabelStatus: string;
    PenanggungJawab: string | null;
    BatasSlaPada: string;
    ResponsPertamaPada: string | null;
    LewatSla: boolean;
    TingkatEskalasi: 'L1' | 'L2' | 'Teknis';
    LabelEskalasi: string;
    PesanTerakhirPada: string | null;
};

type PropsAntrean = {
    Tiket: HasilTabel<BarisAntrean>;
    PilihanStatus: Pilihan[];
    PilihanPrioritas: Pilihan[];
    BolehTangani?: boolean;
};

const jenisLabelPrioritas = { Mendesak: 'bahaya', Tinggi: 'peringatan', Normal: 'netral', Rendah: 'netral' } as const;

const kolom: KolomTabel<BarisAntrean>[] = [
    {
        id: 'Nomor',
        header: 'Nomor',
        enableSorting: false,
        meta: { label: 'Nomor', prioritas: 'penting', kelasSel: 'font-mono text-label whitespace-nowrap' },
        cell: ({ row }) => (
            <Link href={`/dukungan/tiket/${row.original.Uuid}`} className="font-semibold text-brand underline">
                {row.original.Nomor}
            </Link>
        ),
    },
    {
        id: 'Judul',
        header: 'Judul & tenant',
        enableSorting: false,
        meta: { label: 'Judul & tenant', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: tiket } }) => (
            <>
                <span className="block break-words text-teks-utama">{tiket.Judul}</span>
                <span className="text-keterangan font-normal text-teks-sekunder">
                    {tiket.NamaTenant} | {tiket.Kategori}
                </span>
            </>
        ),
    },
    {
        id: 'Prioritas',
        header: 'Prioritas',
        enableSorting: false,
        meta: { label: 'Prioritas', prioritas: 'penting' },
        cell: ({ row }) => (
            <LabelStatus jenis={jenisLabelPrioritas[row.original.Prioritas]} teks={row.original.LabelPrioritas} />
        ),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => (
            <LabelStatus jenis={jenisLabelStatusTiket[row.original.Status]} teks={row.original.LabelStatus} />
        ),
    },
    {
        id: 'PenanggungJawab',
        header: 'Penanggung jawab',
        enableSorting: false,
        meta: { label: 'Penanggung jawab', prioritas: 'rendah' },
        cell: ({ row }) => row.original.PenanggungJawab ?? 'Belum ada',
    },
    {
        id: 'BatasSlaPada',
        accessorKey: 'BatasSlaPada',
        header: 'Batas respons (SLA)',
        meta: { label: 'Batas respons (SLA)', prioritas: 'penting', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: tiket } }) => (
            <>
                <span className="block">{FormatTanggalWaktu(tiket.BatasSlaPada)}</span>
                {tiket.LewatSla ? (
                    <LabelStatus jenis="bahaya" teks="Lewat SLA" />
                ) : tiket.ResponsPertamaPada ? (
                    <span className="text-keterangan">Sudah direspons</span>
                ) : null}
                {tiket.TingkatEskalasi !== 'L1' ? (
                    <LabelStatus jenis="peringatan" teks={`Eskalasi ${tiket.LabelEskalasi}`} />
                ) : null}
            </>
        ),
    },
    {
        id: 'DibuatPada',
        accessorKey: 'PesanTerakhirPada',
        header: 'Pesan terakhir',
        meta: { label: 'Pesan terakhir', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.PesanTerakhirPada),
    },
];

/** Antrean tiket dukungan semua tenant (P-09, TabelData D-16). Tiket terbuka diurutkan dari batas SLA terdekat. */
export default function AntreanTiket({ Tiket, PilihanStatus, PilihanPrioritas, BolehTangani = false }: PropsAntrean) {
    return (
        <TataLetakPengelola judul="Tiket dukungan">
            <TabelData
                id="pengelola-antrean-tiket"
                label="Antrean tiket dukungan"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: '/dukungan/tiket', awal: Tiket }}
                ambilIdBaris={(tiket) => tiket.Uuid}
                {...(BolehTangani
                    ? {
                          aksiMassal: (konteks: KonteksAksiMassal<BarisAntrean>) => (
                              <AksiMassalTiket konteks={konteks} />
                          ),
                      }
                    : {})}
                cari="Cari nomor atau judul tiket"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihan',
                        nilaiBawaan: 'Terbuka',
                        opsi: [
                            { nilai: 'Terbuka', label: 'Semua yang terbuka' },
                            { nilai: 'Semua', label: 'Semua status' },
                            ...PilihanStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
                        ],
                    },
                    {
                        id: 'Prioritas',
                        label: 'Prioritas',
                        jenis: 'pilihanBanyak',
                        opsi: PilihanPrioritas.map((o) => ({ nilai: o.Nilai, label: o.Label })),
                    },
                    {
                        id: 'Milik',
                        label: 'Penanggung jawab',
                        jenis: 'pilihan',
                        opsi: [
                            { nilai: 'Saya', label: 'Tiket saya' },
                            { nilai: 'Belum', label: 'Belum ada' },
                        ],
                    },
                    { id: 'LewatSla', label: 'Lewat SLA', jenis: 'ya', labelAktif: 'Hanya lewat SLA' },
                ]}
                alamatDetail={(tiket) => `/dukungan/tiket/${tiket.Uuid}`}
                kosong={{ ilustrasi: true, judul: 'Tidak ada tiket yang masih terbuka.' }}
            />
        </TataLetakPengelola>
    );
}
