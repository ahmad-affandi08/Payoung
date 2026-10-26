import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import { AlamatPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import Tombol from '@/Komponen/Formulir/Tombol';
import { SaranKodePemasok } from '@/Pustaka/SaranKode';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisPemasok } from '@/Tipe/Pembelian';

export const AlamatPemasok = `${AlamatPembelian}/pemasok`;

export type IsianPemasok = Omit<BarisPemasok, 'Uuid' | 'Aktif' | 'TerminHari'> & { TerminHari: string };

export const IsianPemasokKosong: IsianPemasok = {
    Kode: '',
    Nama: '',
    NamaKontak: '',
    NoHp: '',
    Email: '',
    Alamat: '',
    Npwp: '',
    Pkp: false,
    TerminHari: '0',
    NamaBank: '',
    NomorRekening: '',
    AtasNamaRekening: '',
    Catatan: '',
};

/** Isian formulir dari baris pemasok (null → kosong); nilai null menjadi string kosong. */
export function BuatIsianPemasok(p: BarisPemasok): IsianPemasok {
    return {
        ...IsianPemasokKosong,
        ...Object.fromEntries(Object.entries(p).map(([k, v]) => [k, v ?? ''])),
        Pkp: p.Pkp,
        TerminHari: String(p.TerminHari),
    };
}

type PropsFormPemasok = {
    /** null = tambah (POST), selain itu ubah (PUT). */
    uuid: string | null;
    awal: IsianPemasok;
    /** Dipanggil setelah tersimpan (panel ubah menutup diri). Halaman tambah tidak memakainya: server mengarahkan. */
    saatSelesai?: () => void;
    saatBatal: () => void;
};

/** F-04 fase 1: formulir pemasok, dipakai halaman "Tambah pemasok" dan panel "Ubah pemasok". */
export default function FormPemasok({ uuid, awal, saatSelesai, saatBatal }: PropsFormPemasok) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [isian, AturIsian] = useState<IsianPemasok>(awal);
    // Pemasok baru: kode disarankan dari nama selama belum diketik sendiri.
    const [kodeDiketik, AturKodeDiketik] = useState(uuid !== null);
    const [memproses, AturMemproses] = useState(false);

    const Ubah = (ubah: Partial<IsianPemasok>) => AturIsian({ ...isian, ...ubah });

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        const data = { ...isian, TerminHari: isian.TerminHari === '' ? '0' : isian.TerminHari };
        const opsi = {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onSuccess: () => saatSelesai?.(),
        };

        if (uuid === null) {
            router.post(AlamatPemasok, data, opsi);
        } else {
            router.put(`${AlamatPemasok}/${uuid}`, data, opsi);
        }
    };

    return (
        <form onSubmit={Simpan} noValidate className="flex flex-col gap-4" aria-label="Formulir pemasok">
            <BidangTeks
                label="Kode pemasok"
                nilai={isian.Kode}
                saatBerubah={(nilai) => {
                    AturKodeDiketik(true);
                    Ubah({ Kode: nilai });
                }}
                galat={galat.Kode}
                {...(kodeDiketik ? {} : { keterangan: 'Disarankan dari nama pemasok; boleh diganti.' })}
                maxLength={30}
                kode
                required
            />
            <BidangTeks
                label="Nama pemasok"
                nilai={isian.Nama}
                saatBerubah={(nilai) =>
                    Ubah(kodeDiketik ? { Nama: nilai } : { Nama: nilai, Kode: SaranKodePemasok(nilai) })
                }
                galat={galat.Nama}
                maxLength={150}
                required
            />
            <BidangTeks
                label="Nama kontak (opsional)"
                nilai={isian.NamaKontak ?? ''}
                saatBerubah={(nilai) => Ubah({ NamaKontak: nilai })}
                galat={galat.NamaKontak}
            />
            <BidangTeks
                label="No. HP/WA (opsional)"
                nilai={isian.NoHp ?? ''}
                saatBerubah={(nilai) => Ubah({ NoHp: nilai })}
                galat={galat.NoHp}
                inputMode="tel"
            />
            <BidangTeks
                label="Email (opsional)"
                jenis="email"
                nilai={isian.Email ?? ''}
                saatBerubah={(nilai) => Ubah({ Email: nilai })}
                galat={galat.Email}
            />
            <BidangTeksPanjang
                label="Alamat (opsional)"
                nilai={isian.Alamat ?? ''}
                saatBerubah={(nilai) => Ubah({ Alamat: nilai })}
                galat={galat.Alamat}
                maksimal={500}
                baris={2}
            />
            <BidangJumlah
                label="Termin bawaan (hari)"
                nilai={isian.TerminHari}
                saatBerubah={(nilai) => Ubah({ TerminHari: nilai })}
                desimal={0}
                digitBulat={3}
                akhiran="hari"
                keterangan="0 = tunai. Jatuh tempo faktur = tanggal faktur + termin."
                galat={galat.TerminHari}
                required
            />
            <KotakCentang
                label="Pemasok PKP (menerbitkan faktur pajak, PPN masukan dihitung)"
                nilai={isian.Pkp}
                saatBerubah={(nilai) => Ubah({ Pkp: nilai })}
            />
            <BidangTeks
                label="NPWP (opsional)"
                nilai={isian.Npwp ?? ''}
                saatBerubah={(nilai) => Ubah({ Npwp: nilai })}
                galat={galat.Npwp}
                kode
            />
            <div className="grid gap-3 sm:grid-cols-2">
                <BidangTeks
                    label="Bank (opsional)"
                    nilai={isian.NamaBank ?? ''}
                    saatBerubah={(nilai) => Ubah({ NamaBank: nilai })}
                    galat={galat.NamaBank}
                />
                <BidangTeks
                    label="No. rekening (opsional)"
                    nilai={isian.NomorRekening ?? ''}
                    saatBerubah={(nilai) => Ubah({ NomorRekening: nilai })}
                    galat={galat.NomorRekening}
                    kode
                />
            </div>
            <BidangTeks
                label="Atas nama rekening (opsional)"
                nilai={isian.AtasNamaRekening ?? ''}
                saatBerubah={(nilai) => Ubah({ AtasNamaRekening: nilai })}
                galat={galat.AtasNamaRekening}
            />
            <BidangTeksPanjang
                label="Catatan (opsional)"
                nilai={isian.Catatan ?? ''}
                saatBerubah={(nilai) => Ubah({ Catatan: nilai })}
                galat={galat.Catatan}
                maksimal={500}
                baris={2}
            />
            <div className="flex flex-wrap justify-end gap-2">
                <Tombol type="button" varian="sekunder" onClick={saatBatal}>
                    Batal
                </Tombol>
                <Tombol type="submit" memproses={memproses}>
                    Simpan pemasok
                </Tombol>
            </div>
        </form>
    );
}
