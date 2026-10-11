import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import FormPeran from '@/Komponen/Kelola/FormPeran';
import TabPengguna from '@/Komponen/Kelola/TabPengguna';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import MenuAksiBaris from '@/Komponen/Tindakan/MenuAksiBaris';
import { Button } from '@/Komponen/Ui/button';
import { Card } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import { IzinTenant, PunyaIzinTenant, type IzinPeran } from '@/Tipe/Organisasi';

type Peran = {
    Uuid: string;
    Nama: string;
    Keterangan: string | null;
    Bawaan: boolean;
    Pemilik: boolean;
    Izin: string[];
    JumlahAnggota: number;
};

type PropsDaftar = { Peran: Peran[]; DaftarIzin: IzinPeran[] };

/** Peran & izin tenant (PRD §19.1): peran bawaan hanya dibaca; peran kustom bisa dibuat dari izin granular. */
export default function HalamanDaftarPeran({ Peran, DaftarIzin }: PropsDaftar) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const bolehKelola = PunyaIzinTenant(props.Akses, IzinTenant.PeranKelola);
    const [sunting, AturSunting] = useState<Peran | null>(null);
    const labelIzin = new Map(DaftarIzin.map((izin) => [izin.Kunci, izin.Label]));

    return (
        <TataLetakAplikasi judul="Peran & izin">
            <TabPengguna />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-isi text-teks-sekunder">
                    Peran bawaan disiapkan sistem. Buat peran kustom bila tim Anda butuh kombinasi izin lain.
                </p>
                {bolehKelola ? (
                    <Button asChild>
                        <Link href="/kelola/peran/buat">Buat peran</Link>
                    </Button>
                ) : null}
            </div>

            {sunting !== null ? (
                <DialogFormulir jenis="panel" judul={`Ubah ${sunting.Nama}`} saatTutup={() => AturSunting(null)}>
                    <FormPeran
                        key={sunting.Uuid}
                        uuid={sunting.Uuid}
                        awal={{ Nama: sunting.Nama, Keterangan: sunting.Keterangan ?? '', Izin: sunting.Izin }}
                        daftarIzin={DaftarIzin}
                        saatSelesai={() => AturSunting(null)}
                        saatBatal={() => AturSunting(null)}
                    />
                </DialogFormulir>
            ) : null}

            <Card className="gap-0 py-0 rounded-panel shadow-none">
                <ul className="flex flex-col divide-y divide-garis">
                    {Peran.map((peran) => (
                        <li key={peran.Uuid} className="flex flex-col gap-2 px-4 py-3">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p className="flex flex-wrap items-center gap-2 font-semibold text-teks-utama">
                                        {peran.Nama}
                                        <LabelStatus jenis="netral" teks={peran.Bawaan ? 'Bawaan' : 'Kustom'} />
                                    </p>
                                    {peran.Keterangan ? (
                                        <p className="text-keterangan text-teks-sekunder">{peran.Keterangan}</p>
                                    ) : null}
                                    <p className="text-keterangan text-teks-sekunder">
                                        {peran.JumlahAnggota} anggota aktif
                                    </p>
                                </div>
                                {bolehKelola && !peran.Bawaan ? (
                                    <MenuAksiBaris
                                        label={`Aksi peran ${peran.Nama}`}
                                        aksi={[
                                            { label: 'Ubah peran', saatPilih: () => AturSunting(peran) },
                                            ...(peran.JumlahAnggota === 0
                                                ? [
                                                      {
                                                          label: 'Hapus peran',
                                                          bahaya: true,
                                                          saatPilih: () =>
                                                              router.delete(`/kelola/peran/${peran.Uuid}`, {
                                                                  preserveScroll: true,
                                                              }),
                                                      },
                                                  ]
                                                : []),
                                        ]}
                                    />
                                ) : null}
                            </div>
                            <p className="text-keterangan text-teks-sekunder">
                                {peran.Pemilik
                                    ? 'Semua izin, termasuk langganan.'
                                    : peran.Izin.map((kunci) => labelIzin.get(kunci) ?? kunci).join(' | ') ||
                                      'Belum ada izin.'}
                            </p>
                        </li>
                    ))}
                </ul>
            </Card>
        </TataLetakAplikasi>
    );
}
