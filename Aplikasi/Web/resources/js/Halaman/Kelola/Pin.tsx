import { useState } from 'react';

import FormPin from '@/Komponen/Kelola/FormPin';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Komponen/Ui/card';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';

type AnggotaPin = { Uuid: string; Nama: string; Email: string | null; NamaPeran: string | null; PinDiatur: boolean };

type PropsPin = { PinSayaDiatur: boolean; Anggota: AnggotaPin[] | null };

const kolom: KolomTabel<AnggotaPin>[] = [
    {
        id: 'Nama',
        accessorFn: (anggota) => `${anggota.Nama} ${anggota.Email ?? 'kasir'}`,
        header: 'Nama',
        meta: { label: 'Nama', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: anggota } }) => (
            <>
                <span className="block text-teks-utama">{anggota.Nama}</span>
                <span className="block text-keterangan break-all text-teks-sekunder">
                    {anggota.Email ?? 'Hanya kasir (masuk dengan PIN)'}
                </span>
            </>
        ),
    },
    {
        id: 'NamaPeran',
        accessorFn: (anggota) => anggota.NamaPeran ?? '—',
        header: 'Peran',
        meta: { label: 'Peran', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
    },
    {
        id: 'PinDiatur',
        accessorKey: 'PinDiatur',
        header: 'PIN',
        meta: { label: 'PIN', prioritas: 'penting' },
        cell: ({ row }) =>
            row.original.PinDiatur ? (
                <LabelStatus jenis="sukses" teks="Sudah diatur" />
            ) : (
                <LabelStatus jenis="peringatan" teks="Belum diatur" />
            ),
    },
];

/** PIN kasir 6 angka: atur PIN sendiri, atur ulang PIN anggota (F-02 langkah 4, §20.2). */
export default function HalamanPin({ PinSayaDiatur, Anggota }: PropsPin) {
    const [sunting, AturSunting] = useState<AnggotaPin | null>(null);

    return (
        // D-27: remah roti halaman dihapus; jalan kembali ke Keamanan akun ikut jejak halaman di tata letak.
        <TataLetakAplikasi judul="PIN kasir" jejak={[{ label: 'Keamanan akun', href: '/kelola/keamanan' }]}>
            <Card className="gap-4 rounded-panel py-6 shadow-none lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)] lg:items-start lg:gap-8">
                <CardHeader className="gap-1 px-6">
                    <CardTitle className="text-subjudul font-bold text-teks-utama">
                        <h2>PIN saya</h2>
                    </CardTitle>
                    <CardDescription className="text-isi text-teks-sekunder">
                        Dipakai untuk masuk cepat di perangkat kasir bersama. Jangan pakai angka yang sama semua atau
                        berurutan. Setelah 5 kali salah, PIN terkunci 5 menit di perangkat itu.
                    </CardDescription>
                    <p className="text-label font-semibold text-teks-utama">
                        Status: {PinSayaDiatur ? 'Sudah diatur' : 'Belum diatur'}
                    </p>
                </CardHeader>
                <CardContent className="px-6">
                    <FormPin alamat="/kelola/keamanan/pin" labelTombol={PinSayaDiatur ? 'Ganti PIN' : 'Simpan PIN'} />
                </CardContent>
            </Card>

            {Anggota ? (
                <section className="flex flex-col gap-2">
                    <h2 className="text-subjudul font-semibold text-teks-utama">PIN anggota</h2>
                    <p className="text-keterangan text-teks-sekunder">
                        PIN tidak bisa dilihat. Bila anggota lupa PIN, atur PIN baru lalu beritahukan langsung
                        kepadanya.
                    </p>
                    {sunting ? (
                        <Card className="gap-3 rounded-panel py-4 shadow-none lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)] lg:items-start lg:gap-8">
                            <CardHeader className="px-4">
                                <CardTitle className="text-label font-semibold text-teks-utama">
                                    <h3>Atur ulang PIN {sunting.Nama}</h3>
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="px-4">
                                <FormPin
                                    key={sunting.Uuid}
                                    alamat={`/kelola/pengguna/${sunting.Uuid}/pin`}
                                    labelTombol="Simpan PIN baru"
                                    saatSelesai={() => AturSunting(null)}
                                />
                            </CardContent>
                        </Card>
                    ) : null}
                    <TabelData
                        id="organisasi-pin"
                        label="Status PIN anggota"
                        kolom={kolom}
                        sumber={{ mode: 'lokal', data: Anggota }}
                        ambilIdBaris={(anggota) => anggota.Uuid}
                        urutBawaan="Nama"
                        cari="Cari nama atau email"
                        saring={[{ id: 'PinDiatur', label: 'Hanya yang sudah punya PIN', jenis: 'ya' }]}
                        labelBaris={(anggota) => anggota.Nama}
                        aksiBaris={(anggota) => (
                            <DropdownMenuItem onSelect={() => AturSunting(anggota)}>Atur ulang PIN</DropdownMenuItem>
                        )}
                        kosong={{ ilustrasi: true, judul: 'Belum ada anggota lain yang PIN-nya bisa Anda atur.' }}
                    />
                </section>
            ) : null}
        </TataLetakAplikasi>
    );
}
