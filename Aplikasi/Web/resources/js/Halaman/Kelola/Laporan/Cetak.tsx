import { Head } from '@inertiajs/react';

import Tombol from '@/Komponen/Formulir/Tombol';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';

type PropsCetak = {
    Judul: string;
    NamaUsaha: string;
    Cakupan: string;
    Logo: string | null;
    Saringan: { Label: string; Nilai: string }[];
    Ringkasan: { Label: string; Nilai: string }[];
    Kolom: { Judul: string; Rata: 'kiri' | 'kanan' | 'tengah' }[];
    Baris: string[][];
    Jumlah: string[] | null;
    Terpotong: boolean;
    MaksBaris: number;
    DibuatPada: string;
    DataTerakhir: string | null;
};

const kelasRata = { kiri: 'text-left', kanan: 'text-right tabular-nums', tengah: 'text-center' } as const;

/**
 * Halaman cetak/PDF semua laporan (D-43): kop, saringan, ringkasan, tabel, dan kaki sama dengan berkas Excel.
 * Tanpa kerangka aplikasi supaya yang keluar di kertas hanya laporannya; header tabel berulang tiap halaman.
 */
export default function Cetak(props: PropsCetak) {
    return (
        <main className="mx-auto flex max-w-[1200px] flex-col gap-4 bg-permukaan p-6 text-keterangan text-teks-utama print:max-w-none print:p-0">
            <Head title={props.Judul} />
            <style>{'@page { size: A4 landscape; margin: 10mm; }'}</style>
            <div className="flex flex-wrap items-center gap-3 print:hidden">
                <p className="text-label text-teks-sekunder">
                    Pratinjau cetak. Pilih &quot;Simpan sebagai PDF&quot; di jendela cetak untuk membuat PDF.
                </p>
                <div className="ml-auto flex gap-2">
                    <Tombol varian="sekunder" onClick={() => window.close()}>
                        Tutup
                    </Tombol>
                    <Tombol onClick={() => window.print()}>Cetak atau simpan PDF</Tombol>
                </div>
            </div>

            <header className="flex items-start justify-between gap-4 border-b-2 border-brand-gelap pb-3">
                <JudulHalaman className="text-brand-gelap">{props.Judul}</JudulHalaman>
                <div className="flex items-center gap-3 text-right">
                    <div>
                        <p className="text-subjudul font-bold">{props.NamaUsaha}</p>
                        <p className="text-teks-sekunder">{props.Cakupan}</p>
                    </div>
                    {props.Logo ? <img src={props.Logo} alt="" className="max-h-12 max-w-24 object-contain" /> : null}
                </div>
            </header>

            <section className="grid grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-2 print:grid-cols-2">
                <dl className="flex flex-col gap-0.5">
                    {props.Saringan.map((s) => (
                        <div key={s.Label} className="flex gap-3">
                            <dt className="w-32 shrink-0 font-semibold text-teks-sekunder">{s.Label}</dt>
                            <dd>{s.Nilai}</dd>
                        </div>
                    ))}
                </dl>
                <dl className="flex flex-col gap-0.5 sm:items-end print:items-end">
                    {props.Ringkasan.map((r) => (
                        <div key={r.Label} className="flex gap-3">
                            <dt className="font-semibold text-teks-sekunder">{r.Label}</dt>
                            <dd className="min-w-32 text-right font-bold tabular-nums">{r.Nilai}</dd>
                        </div>
                    ))}
                </dl>
            </section>

            <p className="text-right text-keterangan text-teks-sekunder italic">
                Dibuat {props.DibuatPada}
                {props.DataTerakhir ? ` | Data terakhir diperbarui ${props.DataTerakhir}` : ''}
            </p>

            {props.Terpotong ? (
                <p className="rounded-kontrol border border-peringatan p-2 text-peringatan print:hidden">
                    Menampilkan {props.MaksBaris.toLocaleString('id-ID')} baris pertama. Jumlah di kaki tetap menghitung
                    semua baris. Untuk seluruh baris, unduh Excel atau CSV.
                </p>
            ) : null}

            <div className="overflow-x-auto print:overflow-visible">
                <table className="w-full border-collapse text-keterangan">
                    <thead className="table-header-group">
                        <tr className="bg-brand-gelap text-permukaan">
                            {props.Kolom.map((k) => (
                                <th key={k.Judul} className={`px-2 py-1.5 font-bold ${kelasRata[k.Rata]}`}>
                                    {k.Judul}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {props.Baris.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={Math.max(1, props.Kolom.length)}
                                    className="px-2 py-4 text-center text-teks-sekunder"
                                >
                                    Tidak ada data pada filter ini.
                                </td>
                            </tr>
                        ) : (
                            props.Baris.map((baris, i) => (
                                <tr key={i} className="break-inside-avoid border-b border-garis">
                                    {baris.map((sel, j) => (
                                        <td
                                            key={j}
                                            className={`px-2 py-1 ${kelasRata[props.Kolom[j]?.Rata ?? 'kiri']}`}
                                        >
                                            {sel}
                                        </td>
                                    ))}
                                </tr>
                            ))
                        )}
                    </tbody>
                    {props.Jumlah ? (
                        <tfoot>
                            <tr className="border-y-2 border-teks-utama bg-latar font-bold">
                                {props.Jumlah.map((sel, j) => (
                                    <td key={j} className={`px-2 py-1.5 ${kelasRata[props.Kolom[j]?.Rata ?? 'kiri']}`}>
                                        {sel}
                                    </td>
                                ))}
                            </tr>
                        </tfoot>
                    ) : null}
                </table>
            </div>

            <footer className="mt-2 text-right text-keterangan text-teks-sekunder">
                Dibuat dengan <span className="font-bold text-brand-gelap">Payoung</span> | payoung.id
            </footer>
        </main>
    );
}
