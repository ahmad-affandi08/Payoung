import { Link } from '@inertiajs/react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import TabTenant from '@/Komponen/Pengelola/Tenant/TabTenant';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { Button } from '@/Komponen/Ui/button';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';

export type BarisSiaran = {
    Uuid: string;
    Judul: string;
    Kanal: 'WhatsApp' | 'Email';
    Status: 'Terjadwal' | 'Mengirim' | 'Selesai' | 'Dibatalkan';
    LabelStatus: string;
    JadwalKirimPada: string;
    SelesaiPada: string | null;
    JumlahPenerima: number;
    JumlahTerkirim: number;
    JumlahGagal: number;
    JumlahDilewati: number;
};

export function JenisStatusSiaran(status: BarisSiaran['Status']): 'sukses' | 'peringatan' | 'netral' {
    return status === 'Selesai' ? 'sukses' : status === 'Dibatalkan' ? 'netral' : 'peringatan';
}

const kolom: KolomTabel<BarisSiaran>[] = [
    {
        id: 'Judul',
        accessorKey: 'Judul',
        header: 'Siaran',
        meta: { label: 'Siaran', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: siaran } }) => (
            <>
                <Link href={`/komunikasi/${siaran.Uuid}`} className="font-semibold text-brand underline">
                    {siaran.Judul}
                </Link>
                <span className="block text-keterangan font-normal text-teks-sekunder">{siaran.Kanal}</span>
            </>
        ),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'utama' },
        cell: ({ row: { original: siaran } }) => (
            <LabelStatus jenis={JenisStatusSiaran(siaran.Status)} teks={siaran.LabelStatus} />
        ),
    },
    {
        id: 'Hasil',
        header: 'Hasil kirim',
        enableSorting: false,
        meta: { label: 'Hasil kirim', prioritas: 'penting', kelasSel: 'tabular-nums text-teks-sekunder' },
        cell: ({ row: { original: siaran } }) =>
            siaran.JumlahPenerima === 0
                ? '—'
                : `${String(siaran.JumlahTerkirim)} dari ${String(siaran.JumlahPenerima)} terkirim` +
                  (siaran.JumlahGagal > 0 ? ` | ${String(siaran.JumlahGagal)} gagal` : '') +
                  (siaran.JumlahDilewati > 0 ? ` | ${String(siaran.JumlahDilewati)} dilewati` : ''),
    },
    {
        id: 'JadwalKirimPada',
        accessorKey: 'JadwalKirimPada',
        header: 'Jadwal kirim',
        meta: { label: 'Jadwal kirim', prioritas: 'rendah', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.JadwalKirimPada),
    },
];

/**
 * Komunikasi (D-210): siaran WhatsApp atau email dari Payoung ke pemilik tenant terpilih, dijadwalkan dan dikirim
 * bertahap. Hanya Super Admin.
 */
export default function Daftar({ Siaran }: { Siaran: BarisSiaran[] }) {
    return (
        <TataLetakPengelola judul="Tenant">
            <TabTenant />
            <AksiHalaman keterangan="Kirim kabar produk, pemeliharaan, atau promo ke pemilik tenant dengan segmen yang Anda pilih.">
                <Button asChild>
                    <Link href="/komunikasi/buat">Buat siaran</Link>
                </Button>
            </AksiHalaman>
            <TabelData
                id="pengelola-siaran"
                label="Siaran"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Siaran }}
                ambilIdBaris={(siaran) => siaran.Uuid}
                cari="Cari judul siaran"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: ['Terjadwal', 'Mengirim', 'Selesai', 'Dibatalkan'].map((nilai) => ({
                            nilai,
                            label: nilai,
                        })),
                    },
                ]}
                alamatDetail={(siaran) => `/komunikasi/${siaran.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada siaran. Buat siaran pertama untuk mengabari pemilik tenant.',
                }}
            />
        </TataLetakPengelola>
    );
}
