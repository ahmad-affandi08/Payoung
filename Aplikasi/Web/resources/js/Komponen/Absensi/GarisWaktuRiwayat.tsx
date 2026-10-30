import { FormatDurasi, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatJam, formatBulan, formatHari, SelisihDetik } from '@/Fitur/Absensi/FormatAbsensi';
import type { RiwayatAbsenSaya } from '@/Tipe/Karyawan';

/** Tanggal kalender WIB (`TTTT-BB-HH`) dari waktu ISO, untuk membandingkan hari masuk dan keluar. */
function TanggalHari(iso: string): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date(iso));
}

export default function GarisWaktuRiwayat({ riwayat, sekarang }: { riwayat: RiwayatAbsenSaya[]; sekarang: Date }) {
    return (
        <section className="flex flex-col gap-3" aria-labelledby="judul-riwayat">
            <h2 id="judul-riwayat" className="text-subjudul font-semibold text-teks-utama">
                Absen terakhir
            </h2>
            <ul className="flex flex-col gap-2">
                {riwayat.map((baris) => {
                    const selesai = baris.KeluarPada !== null;
                    // D-250: keluar di hari kalender lain tampil dengan tanggalnya, supaya catatan yang melintasi hari tidak
                    // terlihat seperti shift biasa.
                    const keluarHariLain =
                        selesai && TanggalHari(baris.KeluarPada as string) !== TanggalHari(baris.MasukPada);

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
                                    {selesai
                                        ? ` – ${keluarHariLain ? `${formatHari.format(new Date(baris.KeluarPada as string))} ${formatBulan.format(new Date(baris.KeluarPada as string))} ` : ''}${FormatJam(baris.KeluarPada as string)}`
                                        : ' – …'}
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
                                    {baris.KeluarOtomatis
                                        ? 'Lupa absen pulang'
                                        : selesai
                                          ? 'Selesai'
                                          : 'Belum absen pulang'}
                                </span>
                                {baris.KeluarOtomatis ? (
                                    <LabelStatus jenis="peringatan" teks="Ditutup otomatis" />
                                ) : baris.PerluDicek ? (
                                    <LabelStatus jenis="peringatan" teks="Perlu dicek" />
                                ) : null}
                            </span>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
