import RincianPerubahanAudit, { type PerubahanAudit } from '@/Komponen/Audit/RincianPerubahanAudit';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { HasilTabel, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';

type Log = {
    Id: number;
    Peristiwa: string;
    Kalimat: string;
    Pelaku: string;
    JenisObjek: string | null;
    IdObjek: number | null;
    Objek: string | null;
    Perubahan: PerubahanAudit[];
    NilaiLama: Record<string, unknown> | null;
    NilaiBaru: Record<string, unknown> | null;
    Ip: string | null;
    DibuatPada: string;
};

type PropsDaftar = { Log: HasilTabel<Log> };

const kolom: KolomTabel<Log>[] = [
    {
        id: 'DibuatPada',
        accessorKey: 'DibuatPada',
        header: 'Waktu',
        meta: { label: 'Waktu', prioritas: 'penting', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.DibuatPada),
    },
    {
        id: 'Peristiwa',
        accessorKey: 'Peristiwa',
        header: 'Peristiwa',
        meta: { label: 'Peristiwa', prioritas: 'utama', wajib: true, kelasSel: 'text-label' },
        cell: ({ row }) => (
            <>
                <span className="block">{row.original.Kalimat}</span>
                <span className="block font-mono text-keterangan font-normal text-teks-sekunder">
                    {row.original.Peristiwa}
                </span>
            </>
        ),
    },
    {
        id: 'Pelaku',
        header: 'Pelaku',
        enableSorting: false,
        meta: { label: 'Pelaku', prioritas: 'penting' },
        cell: ({ row }) => row.original.Pelaku,
    },
    {
        id: 'Objek',
        header: 'Objek',
        enableSorting: false,
        meta: { label: 'Objek', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row }) => row.original.Objek ?? '—',
    },
    {
        id: 'Perubahan',
        header: 'Perubahan',
        enableSorting: false,
        meta: { label: 'Perubahan', prioritas: 'rendah', kelasSel: 'text-keterangan text-teks-sekunder' },
        cell: ({ row: { original: log } }) => (
            <RincianPerubahanAudit Perubahan={log.Perubahan} NilaiLama={log.NilaiLama} NilaiBaru={log.NilaiBaru} />
        ),
    },
    {
        id: 'Ip',
        header: 'IP',
        enableSorting: false,
        meta: { label: 'IP', prioritas: 'rendah', kelasSel: 'font-mono text-keterangan text-teks-sekunder' },
        cell: ({ row }) => row.original.Ip ?? '—',
    },
];

/** Log audit usaha, hanya baca (aturan LogAudit §13.2, TabelData D-16): siapa melakukan apa, kapan, dari mana. */
export default function HalamanLogAudit({ Log }: PropsDaftar) {
    return (
        <TataLetakAplikasi judul="Log audit">
            <TabelData
                id="kelola-log-audit"
                label="Log audit usaha"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: '/kelola/log-audit', awal: Log }}
                ambilIdBaris={(log) => String(log.Id)}
                urutBawaan="-DibuatPada"
                cari="Cari kejadian, misal masuk, produk, atau harga"
                saring={[{ id: 'Tanggal', label: 'Tanggal', jenis: 'rentangTanggal' }]}
                kosong={{ ilustrasi: true, judul: 'Belum ada aktivitas yang tercatat.' }}
            />
        </TataLetakAplikasi>
    );
}
