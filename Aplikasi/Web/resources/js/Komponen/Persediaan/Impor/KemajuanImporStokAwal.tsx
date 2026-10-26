import { router } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';

import Panel from '@/Komponen/Kelola/Panel';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { Progress } from '@/Komponen/Ui/progress';
import { Skeleton } from '@/Komponen/Ui/skeleton';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import type { RingkasanImporStokAwal, StatusImporStokAwal, StatusPollingImporStokAwal } from '@/Tipe/Persediaan';

/** Status yang masih diproses server: halaman menanyakan status tiap 3 detik. */
export const StatusBerjalanStokAwal: StatusImporStokAwal[] = ['Diunggah', 'Memvalidasi', 'Menerapkan'];
export const JedaPollingStokAwalMs = 3000;

export function BuatUrlStatusImporStokAwal(uuid: string): string {
    return `/kelola/persediaan/stok-awal/impor/${uuid}/status`;
}

async function AmbilStatus(uuid: string, sinyal: AbortSignal): Promise<StatusPollingImporStokAwal> {
    const respons = await fetch(BuatUrlStatusImporStokAwal(uuid), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: sinyal,
    });

    if (!respons.ok) {
        throw new Error(`Status impor stok awal gagal dibaca (${String(respons.status)})`);
    }

    return (await respons.json()) as StatusPollingImporStokAwal;
}

/** Progres 0–100 yang aman untuk atribut aria (bilangan bulat, bukan uang). */
export function BatasiProgresStokAwal(progres: number): number {
    return Math.min(100, Math.max(0, Math.round(progres)));
}

/**
 * Kemajuan pemeriksaan data / pembuatan draf impor stok awal dengan polling TanStack Query
 * (`KunciKueri.Persediaan.StatusImpor`). Saat status berubah, halaman Inertia dimuat ulang.
 */
export default function KemajuanImporStokAwal({ impor }: { impor: RingkasanImporStokAwal }) {
    const berjalan = StatusBerjalanStokAwal.includes(impor.Status);
    const kueri = useQuery({
        queryKey: KunciKueri.Persediaan.StatusImpor(impor.Uuid),
        queryFn: ({ signal }) => AmbilStatus(impor.Uuid, signal),
        enabled: berjalan,
        refetchInterval: berjalan ? JedaPollingStokAwalMs : false,
        staleTime: 0,
    });
    const status = kueri.data;
    const progres = BatasiProgresStokAwal(status?.Progres ?? impor.Progres);
    const label = status?.LabelStatus ?? impor.LabelStatus;
    const membuatDraf = impor.Status === 'Menerapkan';

    useEffect(() => {
        if (status && status.Status !== impor.Status) {
            router.reload();
        }
    }, [status, impor.Status]);

    return (
        <Panel
            judul={membuatDraf ? 'Membuat draf stok awal' : 'Memeriksa data'}
            idJudul="judul-kemajuan-impor-stok-awal"
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
            {kueri.isPending && berjalan ? (
                <Skeleton className="h-5 w-64" aria-hidden="true" />
            ) : (
                <p aria-live="polite" className="text-isi text-teks-utama tabular-nums">
                    {label}: {progres}%
                    {membuatDraf
                        ? ` | ${(status?.JumlahDokumen ?? impor.JumlahDokumen).toLocaleString('id-ID')} draf dibuat`
                        : ''}
                </p>
            )}
            {kueri.isError ? (
                <Pemberitahuan jenis="peringatan">
                    Status belum bisa dibaca. Proses tetap berjalan di server. Kami coba lagi otomatis.
                </Pemberitahuan>
            ) : null}
            <p className="text-keterangan text-teks-sekunder">
                Anda boleh meninggalkan halaman ini. Proses tetap berjalan dan hasilnya tersimpan di riwayat impor.
            </p>
        </Panel>
    );
}
