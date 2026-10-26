import { useQuery } from '@tanstack/react-query';
import { useRef, type ReactNode } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import { AmbilHasilCari } from '@/Komponen/Katalog/PemilihProduk';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { Skeleton } from '@/Komponen/Ui/skeleton';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import type { GalatGa, LaporanGa } from '@/Tipe/AnalitikGoogle';

function CekGalat(hasil: LaporanGa | GalatGa): hasil is GalatGa {
    return 'Galat' in hasil;
}

const MENIT = 60_000;

/**
 * Mengambil satu laporan Google Analytics lewat `/analitik/situs/data` dan menampilkan keadaannya: memuat (kerangka),
 * ditolak Google (pesan yang bisa ditindaklanjuti + Coba lagi), atau isi laporan. Tiap tab punya panelnya sendiri,
 * jadi satu laporan yang lambat atau ditolak tidak menahan tab lain. Tombol Segarkan melewati cache server.
 */
export default function PanelLaporanGa({
    laporan,
    dari,
    sampai,
    segarkanOtomatisDetik,
    children,
}: {
    laporan: string;
    dari: string;
    sampai: string;
    /** Real-time: ambil ulang berkala selama tab terbuka. */
    segarkanOtomatisDetik?: number;
    children: (data: LaporanGa) => ReactNode;
}) {
    const segarkanBerikut = useRef(false);
    const kueri = useQuery({
        queryKey: KunciKueri.AnalitikGoogle(laporan, dari, sampai),
        queryFn: ({ signal }) => {
            const parameter = new URLSearchParams({ laporan, dari, sampai });

            if (segarkanBerikut.current) {
                parameter.set('segarkan', '1');
                segarkanBerikut.current = false;
            }

            return AmbilHasilCari<LaporanGa | GalatGa>(`/analitik/situs/data?${parameter.toString()}`, signal);
        },
        staleTime: 5 * MENIT,
        retry: false,
        refetchOnWindowFocus: false,
        ...(segarkanOtomatisDetik !== undefined ? { refetchInterval: segarkanOtomatisDetik * 1000 } : {}),
    });

    const Segarkan = () => {
        segarkanBerikut.current = true;
        void kueri.refetch();
    };

    if (kueri.isPending) {
        return (
            <div className="flex flex-col gap-3" role="status" aria-label="Memuat laporan Google Analytics">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {[0, 1, 2, 3].map((nomor) => (
                        <Skeleton key={nomor} className="h-24 w-full rounded-panel" />
                    ))}
                </div>
                <Skeleton className="h-56 w-full rounded-panel" />
            </div>
        );
    }

    const hasil = kueri.data;

    if (kueri.isError || hasil === undefined || CekGalat(hasil)) {
        const pesan =
            hasil !== undefined && CekGalat(hasil)
                ? hasil.Galat
                : 'Laporan tidak bisa dimuat. Periksa sambungan, lalu coba lagi.';

        return (
            <div className="flex flex-col items-start gap-3">
                <Pemberitahuan jenis="bahaya" judul="Laporan Google Analytics tidak tersedia">
                    {pesan}
                </Pemberitahuan>
                <Tombol varian="sekunder" onClick={Segarkan} memproses={kueri.isFetching}>
                    Coba lagi
                </Tombol>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-keterangan text-teks-sekunder">
                    Diambil{' '}
                    {new Date(kueri.dataUpdatedAt).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}{' '}
                    | disimpan sementara agar kuota Google tidak habis.
                </p>
                <Tombol varian="sekunder" onClick={Segarkan} memproses={kueri.isFetching}>
                    Segarkan data
                </Tombol>
            </div>
            {children(hasil)}
        </div>
    );
}
