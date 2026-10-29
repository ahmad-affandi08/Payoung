import { useQuery } from '@tanstack/react-query';

import { Skeleton } from '@/Komponen/Ui/skeleton';
import { cn } from '@/Komponen/Ui/utils';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import type { SlotReservasi } from '@/Tipe/Reservasi';

type PropsPemilihSlot = {
    /** Alamat JSON slot (back-office `/kelola/reservasi/slot` atau publik `/{slug}/reservasi/slot`). */
    alamat: string;
    outlet: string;
    layanan: string;
    tanggal: string;
    staf: string;
    nilai: string;
    saatPilih: (jam: string) => void;
    galat?: string | undefined;
    /** `pil` = tombol bulat besar untuk halaman pelanggan (D-240), bawaan `baku` untuk back-office. */
    gaya?: 'baku' | 'pil';
    /** Sembunyikan judul "Jam" bila halaman sudah memberinya judul langkah sendiri. */
    judulTersembunyi?: boolean;
};

/** Ambil slot kosong dari server (jadwal staf, reservasi lain, jeda). */
export async function AmbilSlot(alamat: string, outlet: string, layanan: string, tanggal: string, staf: string) {
    const query = new URLSearchParams({ Outlet: outlet, Layanan: layanan, Tanggal: tanggal, Staf: staf });
    const respons = await fetch(`${alamat}?${query.toString()}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });

    if (!respons.ok) {
        throw new Error('Slot gagal dimuat.');
    }

    return ((await respons.json()) as { Slot: SlotReservasi[] }).Slot;
}

/**
 * F-07 mode service: pilihan jam reservasi. Slot dihitung server; jam yang tampil = ada staf kosong (sesuai staf yang
 * dipilih). Pilih outlet, layanan, dan tanggal dulu.
 */
export default function PemilihSlot({
    alamat,
    outlet,
    layanan,
    tanggal,
    staf,
    nilai,
    saatPilih,
    galat,
    gaya = 'baku',
    judulTersembunyi = false,
}: PropsPemilihSlot) {
    const siap = outlet !== '' && layanan !== '' && tanggal !== '';
    const kueri = useQuery({
        queryKey: KunciKueri.Reservasi.Slot(alamat, outlet, layanan, tanggal, staf),
        queryFn: () => AmbilSlot(alamat, outlet, layanan, tanggal, staf),
        enabled: siap,
        staleTime: 15_000,
    });

    return (
        <fieldset className="flex flex-col gap-2">
            <legend className={cn('mb-1 text-label font-semibold text-teks-utama', judulTersembunyi && 'sr-only')}>
                Jam
            </legend>
            {!siap ? (
                <p className="text-keterangan text-teks-sekunder">
                    Pilih layanan dan tanggal untuk melihat jam kosong.
                </p>
            ) : kueri.isPending ? (
                <div className="flex flex-wrap gap-2" aria-label="Memuat jam kosong">
                    {[0, 1, 2, 3].map((i) => (
                        <Skeleton key={i} className="h-9 w-16" />
                    ))}
                </div>
            ) : kueri.isError ? (
                <p role="alert" className="text-keterangan text-bahaya">
                    Jam kosong gagal dimuat. Coba pilih tanggal lagi.
                </p>
            ) : kueri.data.length === 0 ? (
                <p className="text-keterangan text-teks-sekunder">
                    Tidak ada jam kosong di tanggal ini. Pilih tanggal atau staf lain, atau pastikan jadwal kerja staf
                    sudah diisi.
                </p>
            ) : (
                <div className="flex flex-wrap gap-2">
                    {kueri.data.map((s) => (
                        <button
                            key={s.Jam}
                            type="button"
                            aria-pressed={nilai === s.Jam}
                            onClick={() => saatPilih(s.Jam)}
                            className={cn(
                                gaya === 'pil'
                                    ? 'h-11 min-w-20 rounded-full border-2 px-4 text-subjudul tabular-nums'
                                    : 'h-9 min-w-16 rounded-kontrol border px-3 text-isi tabular-nums pointer-coarse:h-11',
                                nilai === s.Jam
                                    ? gaya === 'pil'
                                        ? 'border-brand-gelap bg-brand-gelap font-bold text-permukaan'
                                        : 'border-brand bg-brand font-semibold text-brand-teks'
                                    : gaya === 'pil'
                                      ? 'border-garis bg-permukaan text-teks-utama hover:bg-permukaan-sorot'
                                      : 'border-garis-input bg-permukaan text-teks-utama hover:bg-permukaan-sorot',
                            )}
                        >
                            {s.Jam}
                        </button>
                    ))}
                </div>
            )}
            {galat ? <p className="text-keterangan text-bahaya">{galat}</p> : null}
        </fieldset>
    );
}
