import { FormatDurasi, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatJam, formatBulan, formatHari, SelisihDetik } from '@/Fitur/Absensi/FormatAbsensi';
import type { RiwayatAbsenSaya } from '@/Tipe/Karyawan';

export default function GarisWaktuRiwayat({ riwayat, sekarang }: { riwayat: RiwayatAbsenSaya[]; sekarang: Date }) {
    return (
        <section className="flex flex-col gap-3" aria-labelledby="judul-riwayat">
            <h2 id="judul-riwayat" className="text-subjudul font-semibold text-teks-utama">
                Absen terakhir
            </h2>
            <ul className="flex flex-col gap-2">
                {riwayat.map((baris) => {
                    const selesai = baris.KeluarPada !== null;

                    return (
                        <li
                            key={baris.MasukPada}
                            className="flex items-center gap-3 rounded-panel border border-garis bg-permukaan p-3"
                        >
                            <span
                                className="flex w-12 shrink-0 flex-col items-center rounded-panel bg-brand-lembut py-1.5 text-brand"
                                aria-hidden="true"
                            >
                                <span className="text-judul leading-none font-semibold tabular-nums">
                                    {formatHari.format(new Date(baris.MasukPada))}
                                </span>
                                <span className="text-keterangan uppercase">
                                    {formatBulan.format(new Date(baris.MasukPada))}
                                </span>
                            </span>
                            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span className="text-isi font-semibold text-teks-utama tabular-nums">
                                    {FormatJam(baris.MasukPada)}
                                    {selesai ? ` – ${FormatJam(baris.KeluarPada as string)}` : ' – …'}
                                    <span className="sr-only"> ({FormatTanggalWaktu(baris.MasukPada)})</span>
                                </span>
                                <span className="truncate text-keterangan text-teks-sekunder">
                                    {baris.NamaOutlet ?? 'Outlet'}
                                </span>
                            </span>
                            <span className="flex shrink-0 flex-col items-end gap-1">
                                <span className="text-keterangan font-semibold text-teks-utama tabular-nums">
                                    {FormatDurasi(SelisihDetik(baris.MasukPada, baris.KeluarPada ?? sekarang))}
                                </span>
                                <span className="text-keterangan text-teks-sekunder">
                                    {selesai ? 'Selesai' : 'Belum absen pulang'}
                                </span>
                                {baris.PerluDicek ? <LabelStatus jenis="peringatan" teks="Perlu dicek" /> : null}
                            </span>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
