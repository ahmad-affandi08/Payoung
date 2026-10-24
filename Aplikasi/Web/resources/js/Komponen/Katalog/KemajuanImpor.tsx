import { router } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';

import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { Progress } from '@/Komponen/Ui/progress';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import type { RingkasanImpor, StatusImpor, StatusImporProduk } from '@/Tipe/Katalog';

import Panel from '@/Komponen/Kelola/Panel';

/** Status yang masih berjalan di antrean: halaman menanyakan status tiap 3 detik (DesainF03 E.10). */
export const StatusBerjalan: StatusImporProduk[] = ['Diunggah', 'Memvalidasi', 'Menerapkan'];
export const JedaPollingMs = 3000;

async function AmbilStatusImpor(uuid: string, sinyal: AbortSignal): Promise<StatusImpor> {
    const respons = await fetch(`/kelola/produk/impor/${uuid}/status`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: sinyal,
    });

    if (!respons.ok) {
        throw new Error(`Status impor gagal dibaca (${String(respons.status)})`);
    }

    return (await respons.json()) as StatusImpor;
}

/** Progres 0–100 yang aman untuk atribut aria (bilangan bulat, bukan uang). */
export function BatasiProgres(progres: number): number {
    return Math.min(100, Math.max(0, Math.round(progres)));
}

/**
 * Kemajuan validasi/penerapan impor dengan polling TanStack Query (KunciKueri.Impor.Status).
 * Saat status berubah, halaman Inertia dimuat ulang (`router.reload()`) untuk menampilkan langkah berikutnya.
 */
export default function KemajuanImpor({ impor }: { impor: RingkasanImpor }) {
    const berjalan = StatusBerjalan.includes(impor.Status);
    const kueri = useQuery({
        queryKey: KunciKueri.Impor.Status(impor.Uuid),
        queryFn: ({ signal }) => AmbilStatusImpor(impor.Uuid, signal),
        enabled: berjalan,
        refetchInterval: berjalan ? JedaPollingMs : false,
        staleTime: 0,
    });
    const status = kueri.data;
    const progres = BatasiProgres(status?.Progres ?? impor.Progres);
    const label = status?.LabelStatus ?? impor.LabelStatus;

    useEffect(() => {
        if (status && status.Status !== impor.Status) {
            router.reload();
        }
    }, [status, impor.Status]);

    return (
        <Panel
            judul={impor.Status === 'Menerapkan' ? 'Mengimpor produk' : 'Memeriksa data'}
            idJudul="judul-kemajuan-impor"
        >
            <Progress
                value={progres}
                aria-label={label}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={progres}
                aria-valuetext={`${String(progres)}%`}
                className="h-3 rounded-kontrol"
            />
            <p aria-live="polite" className="text-isi text-teks-utama tabular-nums">
                {label}: {progres}%
                {impor.Status === 'Menerapkan'
                    ? ` | ${String(status?.JumlahDiterapkan ?? impor.JumlahDiterapkan)} baris diterapkan, ${String(status?.JumlahGagal ?? impor.JumlahGagal)} gagal`
                    : ''}
            </p>
            {kueri.isError ? (
                <Pemberitahuan jenis="peringatan">
                    Status belum bisa dibaca. Proses tetap berjalan di server; kami coba lagi otomatis.
                </Pemberitahuan>
            ) : null}
            <p className="text-keterangan text-teks-sekunder">
                Anda boleh meninggalkan halaman ini. Proses tetap berjalan dan hasilnya tersimpan di riwayat impor.
            </p>
        </Panel>
    );
}
