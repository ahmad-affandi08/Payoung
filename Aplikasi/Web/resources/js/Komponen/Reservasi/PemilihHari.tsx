import { cn } from '@/Komponen/Ui/utils';

type PropsPemilihHari = {
    /** Hari pertama yang boleh dipilih, `TTTT-BB-HH`. */
    awal: string;
    /** Hari terakhir yang boleh dipilih, `TTTT-BB-HH`. */
    akhir: string;
    nilai: string;
    saatPilih: (tanggal: string) => void;
    galat?: string | undefined;
};

const NAMA_HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'] as const;
const NAMA_BULAN = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
] as const;
/** Batas wajar jajaran hari supaya halaman tidak memuat ratusan tombol bila toko mengatur rentang sangat panjang. */
const MAKS_HARI = 62;

type Hari = { Nilai: string; NamaHari: string; Tanggal: number; Bulan: string; Penuh: string };

/** Jajaran hari dari `awal` sampai `akhir` dihitung dengan UTC, jadi tidak bergeser oleh zona waktu peramban. */
export function SusunJajaranHari(awal: string, akhir: string): Hari[] {
    const [t1, b1, h1] = awal.split('-').map(Number);
    const [t2, b2, h2] = akhir.split('-').map(Number);

    if (!t1 || !b1 || !h1 || !t2 || !b2 || !h2) {
        return [];
    }

    const mulai = Date.UTC(t1, b1 - 1, h1);
    const selesai = Date.UTC(t2, b2 - 1, h2);
    const hasil: Hari[] = [];

    for (let ms = mulai; ms <= selesai && hasil.length < MAKS_HARI; ms += 86_400_000) {
        const d = new Date(ms);
        const nama = NAMA_HARI[d.getUTCDay()] ?? '';
        const bulan = NAMA_BULAN[d.getUTCMonth()] ?? '';
        const tanggal = d.getUTCDate();

        hasil.push({
            Nilai: d.toISOString().slice(0, 10),
            NamaHari: nama.slice(0, 3),
            Tanggal: tanggal,
            Bulan: bulan.slice(0, 3),
            Penuh: `${nama}, ${String(tanggal)} ${bulan} ${String(d.getUTCFullYear())}`,
        });
    }

    return hasil;
}

/**
 * Jajaran hari yang bisa digeser ke samping (D-240): satu ketukan memilih tanggal, tanpa membuka kalender. Aturan hari
 * yang bisa dipesan tetap milik server, di sini hanya rentang awal sampai akhir yang ditawarkan.
 */
export default function PemilihHari({ awal, akhir, nilai, saatPilih, galat }: PropsPemilihHari) {
    const hari = SusunJajaranHari(awal, akhir);

    return (
        <fieldset className="flex min-w-0 flex-col gap-2">
            <legend className="sr-only">Tanggal</legend>
            <div className="-mx-5 flex snap-x gap-2 overflow-x-auto px-5 pb-2">
                {hari.map((h) => {
                    const dipilih = nilai === h.Nilai;

                    return (
                        <button
                            key={h.Nilai}
                            type="button"
                            aria-pressed={dipilih}
                            aria-label={h.Penuh}
                            onClick={() => saatPilih(h.Nilai)}
                            className={cn(
                                'flex min-h-20 w-16 shrink-0 snap-start flex-col items-center justify-center gap-0.5 rounded-2xl border-2 py-2 has-focus-visible:ring-2',
                                dipilih
                                    ? 'border-brand-gelap bg-brand-gelap text-permukaan'
                                    : 'border-garis bg-permukaan text-teks-utama hover:bg-permukaan-sorot',
                            )}
                        >
                            <span className="text-keterangan font-semibold">{h.NamaHari}</span>
                            <span className="text-judul font-extrabold tabular-nums">{h.Tanggal}</span>
                            <span className="text-keterangan">{h.Bulan}</span>
                        </button>
                    );
                })}
            </div>
            {galat ? <p className="text-keterangan text-bahaya">{galat}</p> : null}
        </fieldset>
    );
}
