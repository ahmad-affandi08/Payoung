import { router } from '@inertiajs/react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import PemilihJam from '@/Komponen/Tanggal/PemilihJam';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { CekJam, LabelShift, NamaHari } from '@/Fitur/Karyawan/LogikaJadwal';
import type { BarisJadwal, BarisPola, ShiftKerja } from '@/Tipe/Karyawan';

type Props = {
    alamat: string;
    uuidOutlet: string;
    karyawan: BarisJadwal[];
    shift: ShiftKerja[];
    saatTutup: () => void;
};

const opsiHari = NamaHari.map((nama, i) => ({ nilai: String(i + 1), label: nama }));

/** Urutkan pola menurut hari lalu jam mulai. */
export function UrutkanPola(baris: BarisPola[]): BarisPola[] {
    return [...baris].sort((a, b) => a.HariKe - b.HariKe || a.JamMulai.localeCompare(b.JamMulai));
}

/**
 * Pola jadwal berulang (D-251): "Rina selalu Pagi Senin sampai Jumat". Pola menggantikan seluruh pola karyawan itu dan
 * dipakai untuk mengisi hari kosong, lewat tombol Terapkan pola atau otomatis tiap pagi untuk dua minggu ke depan
 * (hanya karyawan yang minggunya belum berjadwal sama sekali).
 */
export default function DialogPolaJadwal({ alamat, uuidOutlet, karyawan, shift, saatTutup }: Props) {
    const aktif = shift.filter((s) => s.Aktif);
    const daftar = karyawan.filter((k) => k.Aktif);
    const [uuidKaryawan, AturKaryawan] = useState(daftar[0]?.Uuid ?? '');
    const [baris, AturBaris] = useState<BarisPola[]>(() => UrutkanPola(daftar[0]?.Pola ?? []));
    const [uuidShift, AturShift] = useState(aktif[0]?.Uuid ?? '');
    const [hari, AturHari] = useState<string[]>([]);
    const [galat, AturGalat] = useState<Record<string, string>>({});
    const [memproses, AturMemproses] = useState(false);
    const JamPolaSah = (b: BarisPola) => CekJam(b.JamMulai) && CekJam(b.JamSelesai);
    const valid = uuidKaryawan !== '' && baris.every(JamPolaSah);
    const PilihKaryawan = (uuid: string) => {
        AturKaryawan(uuid);
        AturBaris(UrutkanPola(daftar.find((k) => k.Uuid === uuid)?.Pola ?? []));
        AturGalat({});
    };
    const TambahDariShift = () => {
        const s = aktif.find((x) => x.Uuid === uuidShift);

        if (!s) {
            return;
        }

        AturBaris((lama) =>
            UrutkanPola([
                ...lama.filter((b) => !hari.includes(String(b.HariKe)) || b.JamMulai !== s.JamMulai),
                ...hari.map((h) => ({
                    HariKe: Number(h),
                    JamMulai: s.JamMulai,
                    JamSelesai: s.JamSelesai,
                    UuidShift: s.Uuid,
                })),
            ]),
        );
        AturHari([]);
    };
    const UbahBaris = (indeks: number, bagian: Partial<BarisPola>) =>
        AturBaris((lama) => lama.map((b, i) => (i === indeks ? { ...b, ...bagian, UuidShift: null } : b)));
    const Kirim = () =>
        router.put(
            `${alamat}/pola/${uuidKaryawan}`,
            { UuidOutlet: uuidOutlet, Baris: baris },
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
            judul="Pola jadwal berulang"
            keterangan="Atur pola mingguan seorang karyawan. Pola dipakai mengisi jadwal yang masih kosong. Pola kosong menghapus pola."
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
                <BidangPilihan
                    label="Karyawan"
                    nilai={uuidKaryawan}
                    opsi={daftar.map((k) => ({ Nilai: k.Uuid, Label: k.Nama }))}
                    saatBerubah={PilihKaryawan}
                    required
                />
                {aktif.length > 0 ? (
                    <div className="flex flex-col gap-2 rounded-panel bg-permukaan-redup p-3">
                        <BidangPilihan
                            label="Tambah dari shift"
                            nilai={uuidShift}
                            opsi={aktif.map((s) => ({ Nilai: s.Uuid, Label: LabelShift(s) }))}
                            saatBerubah={AturShift}
                        />
                        <GrupCentang legenda="Pada hari" opsi={opsiHari} terpilih={hari} saatBerubah={AturHari} />
                        <div>
                            <Tombol varian="sekunder" disabled={hari.length === 0} onClick={TambahDariShift}>
                                Tambahkan ke pola
                            </Tombol>
                        </div>
                    </div>
                ) : null}
                {baris.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">Belum ada pola untuk karyawan ini.</p>
                ) : (
                    <ul className="flex flex-col gap-2" aria-label="Pola jadwal">
                        {baris.map((b, i) => (
                            <li
                                key={`${b.HariKe}-${b.JamMulai}-${i}`}
                                className="grid items-end gap-2 sm:grid-cols-[10rem_1fr_1fr_auto]"
                            >
                                <BidangPilihan
                                    label={`Hari baris ${i + 1}`}
                                    labelTersembunyi
                                    nilai={String(b.HariKe)}
                                    opsi={opsiHari.map((o) => ({ Nilai: o.nilai, Label: o.label }))}
                                    saatBerubah={(n) => UbahBaris(i, { HariKe: Number(n) })}
                                />
                                <PemilihJam
                                    label={`Jam mulai baris ${i + 1}`}
                                    labelTersembunyi
                                    contoh="08:00"
                                    nilai={b.JamMulai}
                                    saatBerubah={(n) => UbahBaris(i, { JamMulai: n })}
                                    galat={galat[`Baris.${i}.JamMulai`]}
                                />
                                <PemilihJam
                                    label={`Jam selesai baris ${i + 1}`}
                                    labelTersembunyi
                                    contoh="16:00"
                                    nilai={b.JamSelesai}
                                    saatBerubah={(n) => UbahBaris(i, { JamSelesai: n })}
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
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={memproses} disabled={!valid}>
                        Simpan pola
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
