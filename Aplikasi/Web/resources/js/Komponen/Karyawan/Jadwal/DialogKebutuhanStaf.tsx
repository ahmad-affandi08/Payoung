import { router } from '@inertiajs/react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import PemilihJam from '@/Komponen/Tanggal/PemilihJam';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { CekJam, NamaHari } from '@/Fitur/Karyawan/LogikaJadwal';
import type { KebutuhanStaf } from '@/Tipe/Karyawan';

type Props = { alamat: string; uuidOutlet: string; kebutuhan: KebutuhanStaf[]; saatTutup: () => void };

type BarisIsian = { HariKe: string; JamMulai: string; JamSelesai: string; Minimal: string };

const opsiHari = NamaHari.map((nama, i) => ({ Nilai: String(i + 1), Label: nama }));

/** Baris valid: jam sah, selesai setelah mulai (tidak lewat tengah malam), minimal 1 sampai 99. */
export function CekBarisKebutuhan(b: BarisIsian): boolean {
    const minimal = Number(b.Minimal);

    return (
        CekJam(b.JamMulai) &&
        CekJam(b.JamSelesai) &&
        b.JamSelesai > b.JamMulai &&
        Number.isInteger(minimal) &&
        minimal >= 1 &&
        minimal <= 99
    );
}

/**
 * Kebutuhan staf minimal outlet (D-251): "Senin 10:00–14:00 minimal 2 orang". Jadwal dibandingkan dengan aturan ini,
 * dan jam yang kurang staf ditandai di halaman Jadwal. Daftar kosong menghapus semua aturan.
 */
export default function DialogKebutuhanStaf({ alamat, uuidOutlet, kebutuhan, saatTutup }: Props) {
    const [baris, AturBaris] = useState<BarisIsian[]>(
        kebutuhan.map((k) => ({
            HariKe: String(k.HariKe),
            JamMulai: k.JamMulai,
            JamSelesai: k.JamSelesai,
            Minimal: String(k.Minimal),
        })),
    );
    const [galat, AturGalat] = useState<Record<string, string>>({});
    const [memproses, AturMemproses] = useState(false);
    const Ubah = (i: number, bagian: Partial<BarisIsian>) =>
        AturBaris((lama) => lama.map((b, j) => (j === i ? { ...b, ...bagian } : b)));
    const Kirim = () =>
        router.put(
            `${alamat}/kebutuhan`,
            {
                UuidOutlet: uuidOutlet,
                Baris: baris.map((b) => ({ ...b, HariKe: Number(b.HariKe), Minimal: Number(b.Minimal) })),
            },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onError: (errors) => AturGalat(errors),
                onSuccess: saatTutup,
            },
        );

    return (
        <DialogFormulir
            judul="Kebutuhan staf"
            keterangan="Tentukan jumlah staf minimal pada jam tertentu. Jam yang kurang staf ditandai di jadwal."
            galatUmum={galat.Umum}
            saatTutup={saatTutup}
            lebar="lebar"
        >
            <form
                noValidate
                className="flex flex-col gap-3"
                onSubmit={(peristiwa) => {
                    peristiwa.preventDefault();
                    Kirim();
                }}
            >
                {baris.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">Belum ada aturan kebutuhan staf.</p>
                ) : (
                    <ul className="flex flex-col gap-2" aria-label="Kebutuhan staf">
                        {baris.map((b, i) => (
                            <li key={i} className="grid items-end gap-2 sm:grid-cols-[9rem_1fr_1fr_6rem_auto]">
                                <BidangPilihan
                                    label={`Hari baris ${i + 1}`}
                                    labelTersembunyi
                                    nilai={b.HariKe}
                                    opsi={opsiHari}
                                    saatBerubah={(n) => Ubah(i, { HariKe: n })}
                                />
                                <PemilihJam
                                    label={`Jam mulai baris ${i + 1}`}
                                    labelTersembunyi
                                    contoh="10:00"
                                    nilai={b.JamMulai}
                                    saatBerubah={(n) => Ubah(i, { JamMulai: n })}
                                    galat={galat[`Baris.${i}.JamMulai`]}
                                />
                                <PemilihJam
                                    label={`Jam selesai baris ${i + 1}`}
                                    labelTersembunyi
                                    contoh="14:00"
                                    nilai={b.JamSelesai}
                                    saatBerubah={(n) => Ubah(i, { JamSelesai: n })}
                                />
                                <BidangTeks
                                    label={`Minimal baris ${i + 1}`}
                                    labelTersembunyi
                                    inputMode="numeric"
                                    nilai={b.Minimal}
                                    saatBerubah={(n) => Ubah(i, { Minimal: n.replace(/\D/g, '') })}
                                    galat={galat[`Baris.${i}.Minimal`]}
                                    maxLength={2}
                                />
                                <Tombol
                                    varian="sekunder"
                                    onClick={() => AturBaris((lama) => lama.filter((_, j) => j !== i))}
                                    aria-label={`Hapus baris ${i + 1}`}
                                >
                                    Hapus
                                </Tombol>
                            </li>
                        ))}
                    </ul>
                )}
                <div>
                    <Tombol
                        varian="sekunder"
                        onClick={() =>
                            AturBaris((lama) => [...lama, { HariKe: '1', JamMulai: '', JamSelesai: '', Minimal: '1' }])
                        }
                    >
                        Tambah aturan
                    </Tombol>
                </div>
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={memproses} disabled={!baris.every(CekBarisKebutuhan)}>
                        Simpan kebutuhan
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
