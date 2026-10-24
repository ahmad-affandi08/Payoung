import { KelasBilahStatus, TitikBilahStatus } from '@/Komponen/Umpan/Pemberitahuan';

/** Hitung galat isian (selain `Umum`, yang sudah tampil di tata letak). */
export function HitungGalatIsian(galat: Record<string, string | undefined>): number {
    return Object.entries(galat).filter(([kunci, pesan]) => kunci !== 'Umum' && Boolean(pesan)).length;
}

/** Pindahkan fokus ke isian pertama yang ditandai tidak valid setelah server menolak formulir. */
export function FokusGalatPertama(formulir: HTMLElement | null): void {
    window.requestAnimationFrame(() => {
        formulir?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
    });
}

/**
 * Ringkasan galat yang diumumkan pembaca layar (aria-live). Pesan rinci tetap di bawah tiap isian.
 */
export default function RingkasanGalatFormulir({ galat }: { galat: Record<string, string | undefined> }) {
    const jumlah = HitungGalatIsian(galat);

    return (
        <div aria-live="polite" aria-atomic="true">
            {jumlah > 0 ? (
                <div className={KelasBilahStatus()}>
                    <TitikBilahStatus jenis="bahaya" />
                    <p className="min-w-0 flex-1 font-semibold">
                        {jumlah === 1
                            ? 'Ada 1 isian yang perlu diperbaiki. Lihat pesan di bawah isian tersebut.'
                            : `Ada ${String(jumlah)} isian yang perlu diperbaiki. Lihat pesan di bawah masing-masing isian.`}
                    </p>
                </div>
            ) : null}
        </div>
    );
}
