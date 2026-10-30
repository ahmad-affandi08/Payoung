import { router } from '@inertiajs/react';
import { useState } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import PemilihJam from '@/Komponen/Tanggal/PemilihJam';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { CekJam, LabelShift } from '@/Fitur/Karyawan/LogikaJadwal';
import type { ShiftKerja } from '@/Tipe/Karyawan';

type Props = { alamat: string; shift: ShiftKerja[]; saatTutup: () => void };

type Isian = { Nama: string; JamMulai: string; JamSelesai: string; Aktif: boolean };

const Kosong: Isian = { Nama: '', JamMulai: '', JamSelesai: '', Aktif: true };

/**
 * Kelola shift bernama (D-251): Pagi, Siang, Malam, dst. Shift dipakai di sel jadwal, isi massal, dan pola. Jam disalin
 * ke jadwal saat dipakai, jadi mengubah atau menghapus shift tidak mengubah jadwal yang sudah dibuat.
 */
export default function DialogShiftKerja({ alamat, shift, saatTutup }: Props) {
    const [uuidUbah, AturUuidUbah] = useState<string | null>(null);
    const [isian, AturIsian] = useState<Isian>(Kosong);
    const [galat, AturGalat] = useState<Record<string, string>>({});
    const [memproses, AturMemproses] = useState(false);
    const valid = isian.Nama.trim() !== '' && CekJam(isian.JamMulai) && CekJam(isian.JamSelesai);
    const Ubah = (kunci: keyof Isian, nilai: string | boolean) => AturIsian((lama) => ({ ...lama, [kunci]: nilai }));
    const Mulai = (s: ShiftKerja | null) => {
        AturUuidUbah(s?.Uuid ?? null);
        AturIsian(
            s === null ? Kosong : { Nama: s.Nama, JamMulai: s.JamMulai, JamSelesai: s.JamSelesai, Aktif: s.Aktif },
        );
        AturGalat({});
    };
    const opsi = {
        preserveScroll: true,
        onStart: () => AturMemproses(true),
        onFinish: () => AturMemproses(false),
        onError: (errors: Record<string, string>) => AturGalat(errors),
    };
    const Simpan = () => {
        const badan = { ...isian, Nama: isian.Nama.trim(), Urutan: 0 };
        const sukses = {
            onSuccess: () => {
                Mulai(null);
            },
        };

        if (uuidUbah === null) {
            router.post(`${alamat}/shift`, badan, { ...opsi, ...sukses });
        } else {
            router.put(`${alamat}/shift/${uuidUbah}`, badan, { ...opsi, ...sukses });
        }
    };

    return (
        <DialogFormulir
            judul="Shift kerja"
            keterangan="Buat shift sekali (misal Pagi 08:00–16:00), lalu pasang ke jadwal dengan satu klik. Mengubah shift tidak mengubah jadwal yang sudah dibuat."
            galatUmum={galat.Umum}
            saatTutup={saatTutup}
            lebar="lebar"
        >
            {shift.length === 0 ? (
                <p className="text-isi text-teks-sekunder">Belum ada shift. Buat yang pertama di bawah.</p>
            ) : (
                <ul className="flex flex-col gap-2" aria-label="Daftar shift">
                    {shift.map((s) => (
                        <li
                            key={s.Uuid}
                            className="flex flex-wrap items-center gap-2 rounded-panel border border-garis px-3 py-2"
                        >
                            <span className="min-w-0 flex-1 text-isi break-words text-teks-utama">
                                {LabelShift(s)}
                                {s.Aktif ? '' : ' | nonaktif'}
                            </span>
                            <Tombol varian="sekunder" onClick={() => Mulai(s)}>
                                Ubah
                            </Tombol>
                            <Tombol
                                varian="bahaya"
                                onClick={() => router.delete(`${alamat}/shift/${s.Uuid}`, { preserveScroll: true })}
                            >
                                Hapus
                            </Tombol>
                        </li>
                    ))}
                </ul>
            )}
            <form
                noValidate
                className="mt-3 flex flex-col gap-3 border-t border-garis pt-3"
                onSubmit={(peristiwa) => {
                    peristiwa.preventDefault();
                    Simpan();
                }}
            >
                <p className="text-subjudul font-semibold text-teks-utama">
                    {uuidUbah === null ? 'Shift baru' : 'Ubah shift'}
                </p>
                <BidangTeks
                    label="Nama shift"
                    nilai={isian.Nama}
                    saatBerubah={(n) => Ubah('Nama', n)}
                    galat={galat.Nama}
                    maxLength={40}
                    required
                />
                <div className="grid gap-3 sm:grid-cols-2">
                    <PemilihJam
                        label="Jam mulai"
                        contoh="08:00"
                        nilai={isian.JamMulai}
                        saatBerubah={(n) => Ubah('JamMulai', n)}
                        galat={galat.JamMulai}
                        required
                    />
                    <PemilihJam
                        label="Jam selesai"
                        contoh="16:00"
                        nilai={isian.JamSelesai}
                        saatBerubah={(n) => Ubah('JamSelesai', n)}
                        galat={galat.JamSelesai}
                        keterangan="Lebih kecil dari jam mulai berarti shift lewat tengah malam."
                        required
                    />
                </div>
                <KotakCentang
                    label="Aktif (ditawarkan di jadwal)"
                    nilai={isian.Aktif}
                    saatBerubah={(n) => Ubah('Aktif', n)}
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={memproses} disabled={!valid}>
                        {uuidUbah === null ? 'Tambah shift' : 'Simpan shift'}
                    </Tombol>
                    {uuidUbah !== null ? (
                        <Tombol varian="sekunder" onClick={() => Mulai(null)}>
                            Batal ubah
                        </Tombol>
                    ) : null}
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Tutup
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
