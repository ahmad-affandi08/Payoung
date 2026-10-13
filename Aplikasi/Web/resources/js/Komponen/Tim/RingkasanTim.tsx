import { BagianRingkasan, BarisRingkasan, TampilYaTidak } from '@/Komponen/Formulir/Wizard/RingkasanWizard';
import { FormatRupiah } from '@/Pustaka/Format';
import type { OpsiOutletPengguna, OpsiPeranPengguna } from '@/Tipe/Organisasi';

import type { IsianTim, KunciLangkahTim, LangkahTim } from './LangkahTim';

type PropsRingkasanTim = {
    isian: IsianTim;
    langkah: LangkahTim[];
    labelAkses: string;
    peran: OpsiPeranPengguna | undefined;
    /** Cakupan outlet akun: semua outlet, atau uuid outlet terpilih. */
    semuaOutlet: boolean;
    uuidOutlet: string[];
    outlet: OpsiOutletPengguna[];
    namaOutletUtama: string | null;
    dicatatKaryawan: boolean;
    saatUbah: (kunci: KunciLangkahTim) => void;
};

const Uang = (nilai: string): string => (nilai === '' ? 'Tidak diisi' : FormatRupiah(nilai));

/**
 * Ringkasan baca-saja langkah Tinjau: kata sandi dan PIN tidak ditampilkan, hanya keterangan bahwa sudah diisi.
 * Bagian yang tidak berlaku untuk cara masuk yang dipilih tidak muncul.
 */
export default function RingkasanTim({
    isian,
    langkah,
    labelAkses,
    peran,
    semuaOutlet,
    uuidOutlet,
    outlet,
    namaOutletUtama,
    dicatatKaryawan,
    saatUbah,
}: PropsRingkasanTim) {
    const Cari = (kunci: KunciLangkahTim) => langkah.find((item) => item.Kunci === kunci);
    const langkahSiapa = Cari('Siapa');
    const langkahAkun = Cari('Akun');
    const langkahPeran = Cari('Peran');
    const langkahOutlet = Cari('Outlet');
    const langkahGaji = Cari('Gaji');
    const namaOutlet = uuidOutlet.map((uuid) => outlet.find((item) => item.Uuid === uuid)?.Nama ?? uuid).join(', ');

    return (
        <div className="flex flex-col gap-4">
            {langkahSiapa ? (
                <BagianRingkasan langkah={langkahSiapa} saatUbah={saatUbah}>
                    <BarisRingkasan nama="Nama">{isian.Nama}</BarisRingkasan>
                    <BarisRingkasan nama="Jabatan">
                        {isian.Jabatan.trim() === '' ? 'Tidak diisi' : isian.Jabatan}
                    </BarisRingkasan>
                    <BarisRingkasan nama="Cara masuk">{labelAkses}</BarisRingkasan>
                </BagianRingkasan>
            ) : null}
            {langkahAkun ? (
                <BagianRingkasan langkah={langkahAkun} saatUbah={saatUbah}>
                    {isian.Akses === 'Dashboard' ? (
                        <>
                            <BarisRingkasan nama="Email">{isian.Email}</BarisRingkasan>
                            <BarisRingkasan nama="Kata sandi awal">
                                Sudah diisi. Orangnya wajib menggantinya saat pertama masuk.
                            </BarisRingkasan>
                        </>
                    ) : null}
                    <BarisRingkasan nama="PIN kasir">
                        {isian.Pin === '' ? 'Tidak diisi' : 'Sudah diisi (6 angka, tidak ditampilkan)'}
                    </BarisRingkasan>
                    <BarisRingkasan nama="Nomor WhatsApp">
                        {isian.NoHp.trim() === '' ? 'Tidak diisi' : isian.NoHp}
                    </BarisRingkasan>
                </BagianRingkasan>
            ) : null}
            {langkahPeran ? (
                <BagianRingkasan langkah={langkahPeran} saatUbah={saatUbah}>
                    <BarisRingkasan nama="Peran">{peran?.Nama ?? 'Belum dipilih'}</BarisRingkasan>
                    <BarisRingkasan nama="Outlet">
                        {semuaOutlet
                            ? 'Semua outlet, termasuk outlet baru'
                            : namaOutlet === ''
                              ? 'Belum dipilih'
                              : namaOutlet}
                    </BarisRingkasan>
                    <BarisRingkasan nama="Dicatat sebagai karyawan">{TampilYaTidak(dicatatKaryawan)}</BarisRingkasan>
                </BagianRingkasan>
            ) : null}
            {langkahOutlet ? (
                <BagianRingkasan langkah={langkahOutlet} saatUbah={saatUbah}>
                    <BarisRingkasan nama="Outlet utama">{namaOutletUtama ?? 'Semua outlet'}</BarisRingkasan>
                </BagianRingkasan>
            ) : null}
            {langkahGaji ? (
                <BagianRingkasan langkah={langkahGaji} saatUbah={saatUbah}>
                    <BarisRingkasan nama="Level staf">
                        {isian.LevelStaf.trim() === '' ? 'Tidak diisi' : isian.LevelStaf}
                    </BarisRingkasan>
                    <BarisRingkasan nama="Gaji pokok">{Uang(isian.GajiPokok)}</BarisRingkasan>
                    <BarisRingkasan nama="Tarif lembur per jam">{Uang(isian.TarifLemburPerJam)}</BarisRingkasan>
                    <BarisRingkasan nama="Potongan per menit terlambat">
                        {Uang(isian.PotonganTerlambatPerMenit)}
                    </BarisRingkasan>
                    <BarisRingkasan nama="Potongan per hari tidak masuk">
                        {Uang(isian.PotonganTidakMasukPerHari)}
                    </BarisRingkasan>
                </BagianRingkasan>
            ) : null}
        </div>
    );
}
