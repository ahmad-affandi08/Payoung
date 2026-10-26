import { router } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';

import { Spinner } from '@/Komponen/Ui/spinner';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import type { StatusPostingStokAwal, StatusStokAwal } from '@/Tipe/Persediaan';

/** Jeda polling status posting stok awal di antrean (DesainF05a E: tiap 3 detik). */
export const JedaPollingPostingMs = 3000;

/** URL JSON status posting (`GET /kelola/persediaan/stok-awal/{uuid}/status`). */
export function BuatUrlStatusPosting(uuid: string): string {
    return `/kelola/persediaan/stok-awal/${uuid}/status`;
}

async function AmbilStatusPosting(uuid: string, sinyal: AbortSignal): Promise<StatusPostingStokAwal> {
    const respons = await fetch(BuatUrlStatusPosting(uuid), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: sinyal,
    });

    if (!respons.ok) {
        throw new Error(`Status pencatatan gagal dibaca (${String(respons.status)})`);
    }

    return (await respons.json()) as StatusPostingStokAwal;
}

/**
 * Pemantau posting stok awal besar yang berjalan di antrean (status Memproses). Menanyakan status tiap 3 detik lewat
 * TanStack Query (`KunciKueri.Persediaan.StatusPosting`); saat status berubah (Diposting, atau kembali ke Draf karena
 * gagal), halaman Inertia dimuat ulang agar nomor, jurnal, atau pesan galat tampil.
 */
export default function PemantauPosting({ uuid, status }: { uuid: string; status: StatusStokAwal }) {
    const berjalan = status === 'Memproses';
    const kueri = useQuery({
        queryKey: KunciKueri.Persediaan.StatusPosting(uuid),
        queryFn: ({ signal }) => AmbilStatusPosting(uuid, signal),
        enabled: berjalan,
        refetchInterval: berjalan ? JedaPollingPostingMs : false,
        staleTime: 0,
    });
    const terbaru = kueri.data;

    useEffect(() => {
        if (terbaru && terbaru.Status !== status) {
            router.reload();
        }
    }, [terbaru, status]);

    if (!berjalan) {
        return null;
    }

    return (
        <Pemberitahuan jenis="info" judul="Stok awal sedang dicatat">
            <p className="flex items-center gap-2" aria-live="polite">
                <Spinner role={undefined} aria-label={undefined} aria-hidden="true" />
                {terbaru?.LabelStatus ?? 'Memproses'}: stok, HPP, dan jurnal sedang dicatat.
            </p>
            <p className="mt-1">
                Halaman ini diperbarui otomatis. Anda boleh meninggalkan halaman ini; proses tetap berjalan.
            </p>
            {kueri.isError ? (
                <p className="mt-1 font-semibold text-peringatan">
                    Status belum bisa dibaca. Proses tetap berjalan di server; kami coba lagi otomatis.
                </p>
            ) : null}
        </Pemberitahuan>
    );
}
