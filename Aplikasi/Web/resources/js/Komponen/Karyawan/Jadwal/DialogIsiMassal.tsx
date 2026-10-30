import { router } from '@inertiajs/react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { FormatTanggalPendek, LabelShift, NamaHariDariTanggal } from '@/Fitur/Karyawan/LogikaJadwal';
import type { BarisJadwal, ShiftKerja } from '@/Tipe/Karyawan';

type Props = {
    alamat: string;
    uuidOutlet: string;
    senin: string;
    hari: string[];
    karyawan: BarisJadwal[];
    shift: ShiftKerja[];
    saatTutup: () => void;
};

/**
 * Isi massal (D-251): satu shift bernama dipasang ke banyak karyawan dan hari sekaligus. Hanya hari yang masih kosong
 * yang terisi, hari yang sudah berjadwal atau bertumpuk dilewati dan dihitung di pesan hasil.
 */
export default function DialogIsiMassal({ alamat, uuidOutlet, senin, hari, karyawan, shift, saatTutup }: Props) {
    const aktif = shift.filter((s) => s.Aktif);
    const [uuidShift, AturShift] = useState(aktif[0]?.Uuid ?? '');
    const [orang, AturOrang] = useState<string[]>([]);
    const [tanggal, AturTanggal] = useState<string[]>([]);
    const [galat, AturGalat] = useState<Record<string, string>>({});
    const [memproses, AturMemproses] = useState(false);
    const pilihOrang = karyawan.filter((k) => k.Aktif).map((k) => ({ nilai: k.Uuid, label: k.Nama }));
    const pilihHari = hari.map((t) => ({ nilai: t, label: `${NamaHariDariTanggal(t)} ${FormatTanggalPendek(t)}` }));
    const Kirim = () =>
        router.post(
            `${alamat}/isi-massal`,
            { UuidOutlet: uuidOutlet, Senin: senin, UuidShift: uuidShift, UuidKaryawan: orang, Tanggal: tanggal },
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
            judul="Isi jadwal sekaligus"
            keterangan="Pilih shift, karyawan, dan hari. Hari yang sudah berjadwal tidak diubah."
            galatUmum={galat.Umum}
            saatTutup={saatTutup}
            lebar="lebar"
        >
            {aktif.length === 0 ? (
                <p className="text-isi text-teks-sekunder">
                    Belum ada shift aktif. Buat shift dulu lewat menu Atur › Shift kerja.
                </p>
            ) : (
                <form
                    noValidate
                    className="flex flex-col gap-3"
                    onSubmit={(peristiwa) => {
                        peristiwa.preventDefault();
                        Kirim();
                    }}
                >
                    <BidangPilihan
                        label="Shift"
                        nilai={uuidShift}
                        opsi={aktif.map((s) => ({ Nilai: s.Uuid, Label: LabelShift(s) }))}
                        saatBerubah={AturShift}
                        galat={galat.UuidShift}
                        required
                    />
                    <div className="flex flex-wrap gap-2">
                        <Tombol varian="sekunder" onClick={() => AturOrang(pilihOrang.map((o) => o.nilai))}>
                            Pilih semua karyawan
                        </Tombol>
                        <Tombol
                            varian="sekunder"
                            onClick={() => AturTanggal(hari.filter((t) => NamaHariDariTanggal(t) !== 'Minggu'))}
                        >
                            Senin sampai Sabtu
                        </Tombol>
                        <Tombol varian="sekunder" onClick={() => AturTanggal(hari)}>
                            Semua hari
                        </Tombol>
                    </div>
                    <GrupCentang
                        legenda="Karyawan"
                        opsi={pilihOrang}
                        terpilih={orang}
                        saatBerubah={AturOrang}
                        galat={galat.UuidKaryawan}
                        required
                    />
                    <GrupCentang
                        legenda="Hari"
                        opsi={pilihHari}
                        terpilih={tanggal}
                        saatBerubah={AturTanggal}
                        galat={galat.Tanggal}
                        required
                    />
                    <DialogFooter className="sm:justify-start">
                        <Tombol
                            type="submit"
                            memproses={memproses}
                            disabled={uuidShift === '' || orang.length === 0 || tanggal.length === 0}
                        >
                            Isi jadwal
                        </Tombol>
                        <Tombol varian="sekunder" onClick={saatTutup}>
                            Batal
                        </Tombol>
                    </DialogFooter>
                </form>
            )}
        </DialogFormulir>
    );
}
