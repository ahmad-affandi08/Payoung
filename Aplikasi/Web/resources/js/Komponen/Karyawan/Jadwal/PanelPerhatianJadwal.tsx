import { router } from '@inertiajs/react';

import Panel from '@/Komponen/Kelola/Panel';
import Tombol from '@/Komponen/Formulir/Tombol';
import { FormatTanggalPendek, NamaHariDariTanggal } from '@/Fitur/Karyawan/LogikaJadwal';
import type { KekuranganStaf, TukarShiftMenunggu } from '@/Tipe/Karyawan';

type Props = { alamat: string; kekurangan: KekuranganStaf[]; tukarShift: TukarShiftMenunggu[]; bolehKelola: boolean };

/** Kalimat satu kekurangan staf, misal `Senin 21 Sep 10:00–14:00: kurang 1 orang (terjadwal 1 dari minimal 2)`. */
export function KalimatKekurangan(k: KekuranganStaf): string {
    return `${NamaHariDariTanggal(k.Tanggal)} ${FormatTanggalPendek(k.Tanggal)} ${k.JamMulai}–${k.JamSelesai}: kurang ${k.Kurang} orang (terjadwal ${k.Terjadwal} dari minimal ${k.Minimal})`;
}

/**
 * Dua hal yang perlu perhatian pengelola di atas jadwal (D-251): jam yang kurang staf menurut kebutuhan staf outlet, dan
 * permintaan tukar shift yang sudah disetujui rekan dan menunggu keputusan.
 */
export default function PanelPerhatianJadwal({ alamat, kekurangan, tukarShift, bolehKelola }: Props) {
    if (kekurangan.length === 0 && tukarShift.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            {kekurangan.length > 0 ? (
                <Panel
                    judul={`Kurang staf (${kekurangan.length})`}
                    keterangan="Jam berikut belum memenuhi kebutuhan staf minimal outlet."
                >
                    <ul className="flex flex-col gap-1" aria-label="Kekurangan staf">
                        {kekurangan.map((k) => (
                            <li key={`${k.Tanggal}-${k.JamMulai}`} className="text-isi text-teks-utama">
                                {KalimatKekurangan(k)}
                            </li>
                        ))}
                    </ul>
                </Panel>
            ) : null}
            {tukarShift.length > 0 ? (
                <Panel
                    judul={`Tukar shift menunggu keputusan (${tukarShift.length})`}
                    keterangan="Rekan sudah setuju. Jadwal berubah setelah Anda menyetujui."
                >
                    <ul className="flex flex-col gap-3" aria-label="Permintaan tukar shift">
                        {tukarShift.map((t) => (
                            <li key={t.Uuid} className="flex flex-col gap-2 rounded-panel border border-garis p-3">
                                <p className="text-isi text-teks-utama">
                                    <span className="font-semibold">{t.Pemohon}</span> menyerahkan{' '}
                                    {NamaHariDariTanggal(t.Tanggal)} {FormatTanggalPendek(t.Tanggal)} {t.JamMulai}–
                                    {t.JamSelesai} kepada <span className="font-semibold">{t.Rekan}</span>
                                    {t.TukarDengan ? `, ditukar dengan shift ${t.TukarDengan}` : ''}.
                                </p>
                                {t.Alasan !== '' ? (
                                    <p className="text-keterangan text-teks-sekunder">Alasan: {t.Alasan}</p>
                                ) : null}
                                {bolehKelola ? (
                                    <div className="flex flex-wrap gap-2">
                                        <Tombol
                                            onClick={() =>
                                                router.post(
                                                    `${alamat}/tukar-shift/${t.Uuid}/putuskan`,
                                                    { Setuju: true },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Setujui
                                        </Tombol>
                                        <Tombol
                                            varian="sekunder"
                                            onClick={() =>
                                                router.post(
                                                    `${alamat}/tukar-shift/${t.Uuid}/putuskan`,
                                                    { Setuju: false },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Tolak
                                        </Tombol>
                                    </div>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </Panel>
            ) : null}
        </div>
    );
}
