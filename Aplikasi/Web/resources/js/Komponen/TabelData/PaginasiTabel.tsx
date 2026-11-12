import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';

import PilihanCari from '@/Komponen/Formulir/PilihanCari';
import { Label } from '@/Komponen/Ui/label';
import { cn } from '@/Komponen/Ui/utils';

import { UKURAN_HALAMAN, type MetaTabel } from './Tipe';

type PropsPaginasi = {
    label: string;
    meta: MetaTabel;
    jumlahBaris: number;
    AturHalaman: (halaman: number) => void;
    AturPerHalaman: (perHalaman: number) => void;
};

const angka = new Intl.NumberFormat('id-ID');

/**
 * Nomor halaman yang ditampilkan (D-276): halaman pertama dan terakhir selalu ada, ditambah halaman aktif beserta satu
 * tetangga di kiri dan kanannya. Celah dua halaman atau lebih jadi `'…'`, celah satu halaman diisi nomornya (lebih
 * jelas daripada elipsis yang hanya menyembunyikan satu angka). Sampai tujuh halaman semuanya ditampilkan.
 */
export function DaftarHalaman(sekarang: number, jumlah: number): (number | '…')[] {
    if (jumlah <= 7) {
        return Array.from({ length: jumlah }, (_, i) => i + 1);
    }

    const dipilih = new Set([1, jumlah, sekarang - 1, sekarang, sekarang + 1]);
    const urut = [...dipilih].filter((n) => n >= 1 && n <= jumlah).sort((a, b) => a - b);
    const hasil: (number | '…')[] = [];

    urut.forEach((nomor, indeks) => {
        const sebelumnya = urut[indeks - 1];

        if (sebelumnya !== undefined && nomor - sebelumnya === 2) {
            hasil.push(sebelumnya + 1);
        } else if (sebelumnya !== undefined && nomor - sebelumnya > 2) {
            hasil.push('…');
        }

        hasil.push(nomor);
    });

    return hasil;
}

const kelasAngka =
    'inline-flex h-8 min-w-8 items-center justify-center rounded-kontrol px-2 text-label font-semibold tabular-nums pointer-coarse:h-11 pointer-coarse:min-w-11';

/**
 * Paginasi server `TabelData` (D-276): ukuran halaman (25/50/100), ringkasan "Menampilkan x sampai y dari N", dan nomor
 * halaman bergaya kartu (1 2 3 … 33 Berikutnya). Di HP nomor diganti "Halaman x dari y" dengan panah sebelumnya dan
 * berikutnya, karena deret nomor tidak muat. Berada di dasar kartu tabel, dipisah garis tipis dari barisnya.
 */
export default function PaginasiTabel({ label, meta, jumlahBaris, AturHalaman, AturPerHalaman }: PropsPaginasi) {
    const awal = meta.Total === 0 ? 0 : (meta.Halaman - 1) * meta.PerHalaman + 1;
    const akhir = meta.Total === 0 ? 0 : awal + jumlahBaris - 1;
    const adaSebelumnya = meta.Halaman > 1;
    const adaBerikutnya = meta.Halaman < meta.JumlahHalaman;
    const kelasPanah = cn(
        kelasAngka,
        'text-teks-utama hover:bg-brand-lembut disabled:cursor-not-allowed disabled:text-teks-sekunder/50 disabled:hover:bg-transparent',
    );

    return (
        <nav
            aria-label={`Halaman ${label}`}
            className="flex flex-col gap-3 text-label text-teks-sekunder sm:flex-row sm:items-center sm:justify-between"
        >
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <div className="flex items-center gap-2">
                    <Label htmlFor={`per-halaman-${label}`} className="text-label font-normal text-teks-sekunder">
                        Tampilkan
                    </Label>
                    <PilihanCari
                        id={`per-halaman-${label}`}
                        label="Baris per halaman"
                        aria-label="Tampilkan baris per halaman"
                        nilai={String(meta.PerHalaman)}
                        opsi={UKURAN_HALAMAN.map((ukuran) => ({ Nilai: String(ukuran), Label: String(ukuran) }))}
                        saatBerubah={(nilai) => AturPerHalaman(Number(nilai))}
                        className="h-11 w-20 sm:h-8"
                    />
                </div>
                <p aria-live="polite">
                    Menampilkan <span className="font-semibold text-teks-utama tabular-nums">{angka.format(awal)}</span>{' '}
                    sampai <span className="font-semibold text-teks-utama tabular-nums">{angka.format(akhir)}</span>{' '}
                    dari <span className="font-semibold text-teks-utama tabular-nums">{angka.format(meta.Total)}</span>
                </p>
            </div>
            <div className="flex items-center gap-1">
                <button
                    type="button"
                    className={kelasPanah}
                    disabled={!adaSebelumnya}
                    onClick={() => AturHalaman(meta.Halaman - 1)}
                    aria-label="Halaman sebelumnya"
                >
                    <ChevronLeftIcon aria-hidden="true" className="size-4" />
                </button>
                <span className="px-2 whitespace-nowrap text-teks-utama tabular-nums sm:hidden">
                    Halaman {angka.format(meta.Halaman)} dari {angka.format(meta.JumlahHalaman)}
                </span>
                <ol className="hidden items-center gap-1 sm:flex">
                    {DaftarHalaman(meta.Halaman, meta.JumlahHalaman).map((nomor, indeks) => (
                        <li key={`${String(nomor)}-${String(indeks)}`}>
                            {nomor === '…' ? (
                                <span aria-hidden="true" className={cn(kelasAngka, 'text-teks-sekunder')}>
                                    …
                                </span>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => AturHalaman(nomor)}
                                    aria-label={`Halaman ${angka.format(nomor)}`}
                                    aria-current={nomor === meta.Halaman ? 'page' : undefined}
                                    className={cn(
                                        kelasAngka,
                                        nomor === meta.Halaman
                                            ? 'bg-brand text-brand-teks'
                                            : 'text-teks-utama hover:bg-brand-lembut',
                                    )}
                                >
                                    {angka.format(nomor)}
                                </button>
                            )}
                        </li>
                    ))}
                </ol>
                <button
                    type="button"
                    className={cn(kelasPanah, 'gap-1 sm:pr-2')}
                    disabled={!adaBerikutnya}
                    onClick={() => AturHalaman(meta.Halaman + 1)}
                    aria-label="Halaman berikutnya"
                >
                    <span className="hidden sm:inline">Berikutnya</span>
                    <ChevronRightIcon aria-hidden="true" className="size-4" />
                </button>
            </div>
        </nav>
    );
}
