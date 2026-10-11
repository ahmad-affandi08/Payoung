import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import CakupanOutletPeran, { TerapkanCakupanPeran } from '@/Komponen/Kelola/CakupanOutletPeran';
import type { OpsiOutletPengguna, OpsiPeranPengguna } from '@/Tipe/Organisasi';

export type IsianAkses = { Email: string; Peran: string; SemuaOutlet: boolean; Outlet: string[] };

type PropsFormAksesPengguna = {
    alamat: string;
    metode: 'post' | 'put';
    denganEmail?: boolean;
    awal: IsianAkses;
    peran: OpsiPeranPengguna[];
    outlet: OpsiOutletPengguna[];
    tombol: string;
    /** Dipanggil setelah tersimpan (panel ubah menutup diri). Halaman undang tidak memakainya: server mengarahkan. */
    saatSelesai?: () => void;
    saatBatal: () => void;
};

/** Isian undangan / peran & akses outlet anggota (F-02 langkah 3, BR-02.1). */
export default function FormAksesPengguna({
    alamat,
    metode,
    denganEmail = false,
    awal,
    peran,
    outlet,
    tombol,
    saatSelesai,
    saatBatal,
}: PropsFormAksesPengguna) {
    // Isian awal diselaraskan dengan cakupan peran: anggota lama berperan operasional yang dulu "semua outlet" harus memilih outlet.
    const formulir = useForm<IsianAkses>(() => ({
        ...awal,
        ...TerapkanCakupanPeran(
            peran.find((baris) => baris.Uuid === awal.Peran),
            outlet,
            awal,
        ),
    }));
    const peranTerpilih = peran.find((baris) => baris.Uuid === formulir.data.Peran);

    const PilihPeran = (uuid: string) => {
        const baris = peran.find((item) => item.Uuid === uuid);
        formulir.setData({
            ...formulir.data,
            Peran: uuid,
            ...TerapkanCakupanPeran(baris, outlet, formulir.data),
        });
    };

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: () => saatSelesai?.() };

        if (metode === 'post') {
            formulir.post(alamat, opsi);
        } else {
            formulir.put(alamat, opsi);
        }
    };

    return (
        <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
            {denganEmail ? (
                <BidangTeks
                    label="Email"
                    jenis="email"
                    nilai={formulir.data.Email}
                    saatBerubah={(nilai) => formulir.setData('Email', nilai)}
                    galat={formulir.errors.Email}
                    maxLength={191}
                    autoFocus
                    required
                />
            ) : null}
            <BidangPilihan
                label="Peran"
                nilai={formulir.data.Peran}
                opsi={peran.map((baris) => ({ Nilai: baris.Uuid, Label: baris.Nama }))}
                saatBerubah={PilihPeran}
                galat={formulir.errors.Peran}
                required
                kosong="Pilih peran"
            />
            <CakupanOutletPeran
                peran={peranTerpilih}
                outlet={outlet}
                nilai={{ SemuaOutlet: formulir.data.SemuaOutlet, Outlet: formulir.data.Outlet }}
                saatBerubah={(isian) => formulir.setData({ ...formulir.data, ...isian })}
                galatSemuaOutlet={formulir.errors.SemuaOutlet}
                galatOutlet={formulir.errors.Outlet}
            />
            <div className="flex flex-wrap gap-2">
                <Tombol type="submit" memproses={formulir.processing}>
                    {tombol}
                </Tombol>
                <Tombol varian="sekunder" onClick={saatBatal}>
                    Batal
                </Tombol>
            </div>
        </form>
    );
}
