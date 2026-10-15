import RincianPerubahanAudit, { type PerubahanAudit } from '@/Komponen/Audit/RincianPerubahanAudit';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { HasilTabel, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';

type Log = {
    Id: number;
    Aksi: string;
    Kalimat: string;
    Pelaku: string;
    JenisObjek: string | null;
    IdObjek: number | null;
    Objek: string | null;
    IdTenant: number | null;
    NamaTenant: string | null;
    Perubahan: PerubahanAudit[];
    NilaiLama: Record<string, unknown> | null;
    NilaiBaru: Record<string, unknown> | null;
    Alasan: string | null;
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
        id: 'Pelaku',
        header: 'Pelaku',
        enableSorting: false,
        meta: { label: 'Pelaku', prioritas: 'penting' },
        cell: ({ row }) => row.original.Pelaku,
    },
    {
        id: 'Aksi',
        accessorKey: 'Aksi',
        header: 'Aksi',
        meta: { label: 'Aksi', prioritas: 'utama', wajib: true, kelasSel: 'text-label' },
        cell: ({ row }) => (
            <>
                <span className="block">{row.original.Kalimat}</span>
                <span className="block font-mono text-keterangan font-normal text-teks-sekunder">
                    {row.original.Aksi}
                </span>
            </>
        ),
    },
    {
        id: 'Objek',
        header: 'Objek',
        enableSorting: false,
        meta: { label: 'Objek', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: log } }) => (
            <>
                {log.Objek ?? '—'}
                {log.NamaTenant !== null ? <span className="block">Tenant: {log.NamaTenant}</span> : null}
            </>
        ),
    },
    {
        id: 'Perubahan',
        header: 'Perubahan',
        enableSorting: false,
        meta: { label: 'Perubahan', prioritas: 'rendah', kelasSel: 'text-keterangan text-teks-sekunder' },
        cell: ({ row: { original: log } }) => (
            <RincianPerubahanAudit
                Perubahan={log.Perubahan}
                NilaiLama={log.NilaiLama}
                NilaiBaru={log.NilaiBaru}
                Alasan={log.Alasan}
            />
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

/** Log audit Platform Pengelola, hanya baca (BR-P01.3, TabelData D-16). */
export default function Daftar({ Log }: PropsDaftar) {
    return (
        <TataLetakPengelola judul="Log audit">
            <TabelData
                id="pengelola-log-audit"
                label="Log audit Platform Pengelola"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: '/log-audit', awal: Log }}
                ambilIdBaris={(log) => String(log.Id)}
                urutBawaan="-DibuatPada"
                cari="Cari kejadian, misal tenant ditangguhkan atau tarif pajak"
                saring={[{ id: 'Tanggal', label: 'Tanggal', jenis: 'rentangTanggal' }]}
                kosong={{ ilustrasi: true, judul: 'Belum ada aktivitas yang tercatat.' }}
            />
        </TataLetakPengelola>
    );
}
