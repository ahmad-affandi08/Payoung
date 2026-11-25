import { Store, Users, type LucideIcon } from 'lucide-react';

import Panel from '@/Komponen/Kelola/Panel';
import { Progress } from '@/Komponen/Ui/progress';
import type { BatasPemakaian, KapasitasLangganan } from '@/Tipe/Langganan';

/** Mulai "hampir penuh" pada 90 persen, supaya pemilik sempat menambah sebelum penuh. */
export const AMBANG_HAMPIR_PENUH = 90;

export function PersenPemakaian(pemakaian: BatasPemakaian): number | null {
    if (pemakaian.Batas === null || pemakaian.Batas <= 0) {
        return null;
    }

    return Math.min(100, Math.round((pemakaian.Terpakai / pemakaian.Batas) * 100));
}

export function KeteranganPemakaian(pemakaian: BatasPemakaian, satuan: string): string {
    const persen = PersenPemakaian(pemakaian);

    if (pemakaian.Batas === null || persen === null) {
        return 'Tanpa batas di paket Anda';
    }

    const sisa = Math.max(0, pemakaian.Batas - pemakaian.Terpakai);

    if (sisa === 0) {
        return `Penuh, naik paket untuk menambah ${satuan}`;
    }

    return persen >= AMBANG_HAMPIR_PENUH
        ? `Hampir penuh, sisa ${String(sisa)} ${satuan}`
        : `Sisa ${String(sisa)} ${satuan}`;
}

function MeteranPemakaian({
    judul,
    ikon: Ikon,
    pemakaian,
    satuan,
}: {
    judul: string;
    ikon: LucideIcon;
    pemakaian: BatasPemakaian;
    satuan: string;
}) {
    const persen = PersenPemakaian(pemakaian);
    const penuh = persen !== null && persen >= AMBANG_HAMPIR_PENUH;

    return (
        <div className="flex flex-col gap-3 rounded-panel border border-garis bg-permukaan p-4">
            <div className="flex items-center gap-3">
                <span className="grid size-9 shrink-0 place-items-center rounded-panel bg-brand-lembut text-brand">
                    <Ikon aria-hidden="true" className="size-5" />
                </span>
                <div className="flex min-w-0 flex-1 flex-col">
                    <span className="text-isi font-semibold text-teks-utama">{judul}</span>
                    <span className="text-keterangan text-teks-sekunder">{KeteranganPemakaian(pemakaian, satuan)}</span>
                </div>
                <span className="text-subjudul font-semibold tabular-nums text-teks-utama">
                    {pemakaian.Terpakai.toLocaleString('id-ID')}
                    <span className="text-isi font-normal text-teks-sekunder">
                        {pemakaian.Batas === null ? '' : ` / ${pemakaian.Batas.toLocaleString('id-ID')}`}
                    </span>
                </span>
            </div>
            {persen !== null ? (
                <Progress
                    value={persen}
                    aria-label={`${judul} terpakai ${String(persen)} persen`}
                    className={penuh ? 'bg-aksen-lembut [&_[data-slot=progress-indicator]]:bg-aksen' : ''}
                />
            ) : null}
        </div>
    );
}

/** Pemakaian outlet dan pengguna terhadap batas paket (D-332). Kuota transaksi punya panelnya sendiri. */
export default function PemakaianPaket({ kapasitas }: { kapasitas: KapasitasLangganan | undefined }) {
    if (kapasitas === undefined) {
        return null;
    }

    return (
        <Panel judul="Pemakaian paket" keterangan="Batas yang berlaku di paket Anda saat ini.">
            <div className="grid gap-3 sm:grid-cols-2">
                <MeteranPemakaian judul="Outlet" ikon={Store} pemakaian={kapasitas.Outlet} satuan="outlet" />
                <MeteranPemakaian judul="Pengguna" ikon={Users} pemakaian={kapasitas.Pengguna} satuan="pengguna" />
            </div>
        </Panel>
    );
}
