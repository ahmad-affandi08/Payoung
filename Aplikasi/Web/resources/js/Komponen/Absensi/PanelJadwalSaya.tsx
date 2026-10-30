import { router } from '@inertiajs/react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggalPendek, NamaHariDariTanggal } from '@/Fitur/Karyawan/LogikaJadwal';
import { KirimJson } from '@/Pustaka/PermintaanJson';
import type { JadwalSaya } from '@/Tipe/Karyawan';

type Props = { jadwal: JadwalSaya; alamatDasar: string };

const TanpaBalasan = '-';

/** `Senin 21 Sep | 08:00–16:00`. */
export function LabelShiftSaya(s: { Tanggal: string; JamMulai: string; JamSelesai: string }): string {
    return `${NamaHariDariTanggal(s.Tanggal)} ${FormatTanggalPendek(s.Tanggal)} | ${s.JamMulai}–${s.JamSelesai}`;
}

/**
 * Jadwal pribadi di halaman Absen (D-251): shift 14 hari ke depan dan tukar shift. Tukar shift: pilih shift sendiri,
 * pilih rekan, boleh minta salah satu shift rekan sebagai balasan, tulis alasan. Rekan menerima atau menolak, lalu
 * pengelola yang menyetujui. Jadwal baru berubah setelah itu.
 */
export default function PanelJadwalSaya({ jadwal, alamatDasar }: Props) {
    const [uuidShift, AturShift] = useState<string | null>(null);
    const [uuidRekan, AturRekan] = useState('');
    const [uuidBalasan, AturBalasan] = useState(TanpaBalasan);
    const [alasan, AturAlasan] = useState('');
    const [mengirim, AturMengirim] = useState(false);
    const [galat, AturGalat] = useState<string | null>(null);
    const rekan = jadwal.Rekan.find((r) => r.Uuid === uuidRekan);
    const Segarkan = () => router.reload({ only: ['JadwalSaya'] });
    const Tutup = () => {
        AturShift(null);
        AturRekan('');
        AturBalasan(TanpaBalasan);
        AturAlasan('');
        AturGalat(null);
    };
    const Jalankan = async (jalur: string, badan: Record<string, unknown>, sukses?: () => void) => {
        AturMengirim(true);
        AturGalat(null);

        try {
            await KirimJson<unknown>(`${alamatDasar}${jalur}`, badan);
            sukses?.();
            Segarkan();
        } catch (kesalahan) {
            AturGalat(kesalahan instanceof Error ? kesalahan.message : 'Permintaan gagal dikirim. Coba lagi.');
        } finally {
            AturMengirim(false);
        }
    };

    if (jadwal.Shift.length === 0 && jadwal.Masuk.length === 0 && jadwal.Diajukan.length === 0) {
        return (
            <section className="flex flex-col gap-2" aria-labelledby="judul-jadwal-saya">
                <h2 id="judul-jadwal-saya" className="text-subjudul font-semibold text-teks-utama">
                    Jadwal saya
                </h2>
                <p className="text-isi text-teks-sekunder">Belum ada shift untuk 14 hari ke depan.</p>
            </section>
        );
    }

    return (
        <section className="flex flex-col gap-3" aria-labelledby="judul-jadwal-saya">
            <h2 id="judul-jadwal-saya" className="text-subjudul font-semibold text-teks-utama">
                Jadwal saya
            </h2>
            {galat ? <p className="text-keterangan font-semibold text-bahaya">{galat}</p> : null}

            {jadwal.Masuk.length > 0 ? (
                <ul className="flex flex-col gap-2" aria-label="Permintaan tukar untuk Anda">
                    {jadwal.Masuk.map((m) => (
                        <li
                            key={m.Uuid}
                            className="flex flex-col gap-2 rounded-panel border border-garis bg-permukaan p-3"
                        >
                            <p className="text-isi text-teks-utama">
                                <span className="font-semibold">{m.Pemohon}</span> minta Anda menggantikan{' '}
                                {LabelShiftSaya(m)}
                                {m.TukarDengan ? `, dan Anda menyerahkan ${m.TukarDengan}` : ''}.
                            </p>
                            {m.Alasan !== '' ? (
                                <p className="text-keterangan text-teks-sekunder">Alasan: {m.Alasan}</p>
                            ) : null}
                            <div className="flex flex-wrap gap-2">
                                <Tombol
                                    memproses={mengirim}
                                    onClick={() => void Jalankan(`/tukar-shift/${m.Uuid}/tanggapi`, { Terima: true })}
                                >
                                    Terima
                                </Tombol>
                                <Tombol
                                    varian="sekunder"
                                    memproses={mengirim}
                                    onClick={() => void Jalankan(`/tukar-shift/${m.Uuid}/tanggapi`, { Terima: false })}
                                >
                                    Tolak
                                </Tombol>
                            </div>
                        </li>
                    ))}
                </ul>
            ) : null}

            <ul className="flex flex-col gap-2" aria-label="Shift 14 hari ke depan">
                {jadwal.Shift.map((s) => (
                    <li key={s.Uuid} className="rounded-panel border border-garis bg-permukaan p-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="min-w-0 flex-1 text-isi text-teks-utama">
                                {LabelShiftSaya(s)}
                                {s.NamaOutlet ? <span className="text-teks-sekunder"> | {s.NamaOutlet}</span> : null}
                            </span>
                            {uuidShift !== s.Uuid ? (
                                <Tombol
                                    varian="sekunder"
                                    onClick={() => {
                                        Tutup();
                                        AturShift(s.Uuid);
                                    }}
                                >
                                    Tukar
                                </Tombol>
                            ) : null}
                        </div>
                        {uuidShift === s.Uuid ? (
                            <div className="mt-3 flex flex-col gap-3 rounded-panel bg-permukaan-redup p-3">
                                <BidangPilihan
                                    label="Tukar dengan"
                                    nilai={uuidRekan}
                                    opsi={jadwal.Rekan.map((r) => ({ Nilai: r.Uuid, Label: r.Nama }))}
                                    saatBerubah={(n) => {
                                        AturRekan(n);
                                        AturBalasan(TanpaBalasan);
                                    }}
                                    kosong="Pilih rekan"
                                    required
                                />
                                {rekan ? (
                                    <BidangPilihan
                                        label="Shift rekan sebagai balasan"
                                        nilai={uuidBalasan}
                                        opsi={[
                                            { Nilai: TanpaBalasan, Label: 'Tanpa balasan, rekan mengambil alih' },
                                            ...rekan.Shift.map((x) => ({ Nilai: x.Uuid, Label: LabelShiftSaya(x) })),
                                        ]}
                                        saatBerubah={AturBalasan}
                                    />
                                ) : null}
                                <BidangTeksPanjang
                                    label="Alasan (opsional)"
                                    nilai={alasan}
                                    saatBerubah={AturAlasan}
                                    baris={2}
                                    maksimal={200}
                                />
                                <div className="flex flex-wrap gap-2">
                                    <Tombol
                                        memproses={mengirim}
                                        disabled={uuidRekan === ''}
                                        onClick={() =>
                                            void Jalankan(
                                                '/tukar-shift',
                                                {
                                                    UuidJadwal: s.Uuid,
                                                    UuidRekan: uuidRekan,
                                                    UuidJadwalRekan: uuidBalasan === TanpaBalasan ? null : uuidBalasan,
                                                    Alasan: alasan.trim(),
                                                },
                                                Tutup,
                                            )
                                        }
                                    >
                                        Kirim permintaan
                                    </Tombol>
                                    <Tombol varian="sekunder" onClick={Tutup}>
                                        Batal
                                    </Tombol>
                                </div>
                            </div>
                        ) : null}
                    </li>
                ))}
            </ul>

            {jadwal.Diajukan.length > 0 ? (
                <ul className="flex flex-col gap-2" aria-label="Permintaan tukar yang Anda ajukan">
                    {jadwal.Diajukan.map((d) => (
                        <li
                            key={d.Uuid}
                            className="flex flex-wrap items-center gap-2 rounded-panel border border-garis p-3"
                        >
                            <span className="min-w-0 flex-1 text-isi text-teks-utama">
                                {LabelShiftSaya(d)} ke {d.Rekan}
                                {d.TukarDengan ? `, ditukar dengan ${d.TukarDengan}` : ''}
                                {d.AlasanKeputusan ? (
                                    <span className="text-teks-sekunder"> | {d.AlasanKeputusan}</span>
                                ) : null}
                            </span>
                            <LabelStatus
                                jenis={
                                    d.Status === 'Disetujui' ? 'sukses' : d.Status === 'Ditolak' ? 'bahaya' : 'netral'
                                }
                                teks={d.Label}
                            />
                            {d.Status === 'MenungguRekan' || d.Status === 'MenungguPengelola' ? (
                                <Tombol
                                    varian="sekunder"
                                    memproses={mengirim}
                                    onClick={() => void Jalankan(`/tukar-shift/${d.Uuid}/batal`, {})}
                                >
                                    Batalkan
                                </Tombol>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : null}
        </section>
    );
}
