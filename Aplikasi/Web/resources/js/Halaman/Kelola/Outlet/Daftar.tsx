import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import PemberitahuanBatasOutlet from '@/Komponen/Kelola/PemberitahuanBatasOutlet';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import MenuAksiBaris from '@/Komponen/Tindakan/MenuAksiBaris';
import { Button } from '@/Komponen/Ui/button';
import { Card } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import {
    CekBatasPenuh,
    FormatBatas,
    IzinTenant,
    PunyaIzinTenant,
    type Batas,
    type Kota,
    type StatusOrganisasi,
} from '@/Tipe/Organisasi';

type Outlet = {
    Uuid: string;
    Kode: string;
    Nama: string;
    NamaMerek: string | null;
    NamaKota: string | null;
    ZonaWaktu: string;
    JamTutupBuku: string;
    JumlahGudang: number;
    Status: StatusOrganisasi;
};

type Merek = { Uuid: string; Nama: string; JumlahOutlet: number };

type PropsDaftar = { Outlet: Outlet[]; Merek: Merek[]; Kota: Kota[]; BatasOutlet: Batas };

const kolom: KolomTabel<Outlet>[] = [
    {
        id: 'Kode',
        accessorKey: 'Kode',
        header: 'Kode',
        meta: { label: 'Kode', prioritas: 'penting', kelasSel: 'font-mono text-label text-teks-utama' },
    },
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Outlet',
        meta: { label: 'Outlet', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: outlet } }) => (
            <>
                <Link href={`/kelola/outlet/${outlet.Uuid}`} className="font-semibold text-brand underline">
                    {outlet.Nama}
                </Link>
            </>
        ),
    },
    {
        id: 'NamaKota',
        accessorFn: (outlet) => `${outlet.NamaKota ?? 'Belum diisi'} | ${outlet.ZonaWaktu}`,
        header: 'Kota',
        meta: { label: 'Kota', prioritas: 'penting', kelasSel: 'text-teks-sekunder' },
    },
    {
        id: 'JamTutupBuku',
        accessorKey: 'JamTutupBuku',
        header: 'Tutup buku',
        meta: { label: 'Tutup buku', prioritas: 'rendah', kelasSel: 'font-mono text-label text-teks-sekunder' },
    },
    {
        id: 'JumlahGudang',
        accessorKey: 'JumlahGudang',
        header: 'Lokasi stok',
        meta: { label: 'Lokasi stok', angka: true, prioritas: 'rendah' },
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) =>
            row.original.Status === 'Aktif' ? (
                <LabelStatus jenis="sukses" teks="Aktif" />
            ) : (
                <LabelStatus jenis="netral" teks="Diarsipkan" />
            ),
    },
];

/** Daftar outlet & merek (F-02 langkah 1, BR-02.1). */
export default function HalamanDaftarOutlet({ Outlet, Merek, BatasOutlet }: PropsDaftar) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const bolehKelola = PunyaIzinTenant(props.Akses, IzinTenant.OutletKelola);
    const penuh = CekBatasPenuh(BatasOutlet);

    return (
        <TataLetakAplikasi judul="Outlet">
            <AksiHalaman
                keterangan={
                    <p className="text-isi text-teks-sekunder">
                        Outlet aktif:{' '}
                        <span className="font-semibold text-teks-utama">{FormatBatas(BatasOutlet, 'outlet')}</span>
                    </p>
                }
                {...(bolehKelola
                    ? {
                          utama: penuh ? (
                              <Tombol disabled>Tambah outlet</Tombol>
                          ) : (
                              <Button asChild>
                                  <Link href="/kelola/outlet/buat">Tambah outlet</Link>
                              </Button>
                          ),
                      }
                    : {})}
            />

            {bolehKelola && penuh ? <PemberitahuanBatasOutlet /> : null}

            <TabelData
                id="organisasi-outlet"
                label="Daftar outlet"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Outlet }}
                ambilIdBaris={(outlet) => outlet.Uuid}
                urutBawaan="Kode"
                cari="Cari kode, nama outlet, atau kota"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihan',
                        opsi: [
                            { nilai: 'Aktif', label: 'Aktif' },
                            { nilai: 'Diarsipkan', label: 'Diarsipkan' },
                        ],
                    },
                ]}
                alamatDetail={(outlet) => `/kelola/outlet/${outlet.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada outlet yang bisa Anda akses. Minta Owner menugaskan Anda ke outlet.',
                }}
            />

            {/* D-76: merek disembunyikan selama tenant hanya punya satu (bawaan dari sistem). */}
            {Merek.length > 1 ? <BagianMerek merek={Merek} bolehKelola={bolehKelola} /> : null}
        </TataLetakAplikasi>
    );
}

function BagianMerek({ merek, bolehKelola }: { merek: Merek[]; bolehKelola: boolean }) {
    const [sunting, AturSunting] = useState<Merek | 'baru' | null>(null);

    return (
        <section className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-subjudul font-semibold text-teks-utama">Merek</h2>
                {bolehKelola ? (
                    <Tombol varian="sekunder" onClick={() => AturSunting('baru')}>
                        Tambah merek
                    </Tombol>
                ) : null}
            </div>
            <p className="text-keterangan text-teks-sekunder">
                Pakai lebih dari satu merek bila usaha Anda punya beberapa nama dagang, misal kafe dan toko roti.
            </p>
            {sunting !== null ? (
                <DialogFormulir
                    judul={sunting === 'baru' ? 'Tambah merek' : `Ganti nama merek ${sunting.Nama}`}
                    saatTutup={() => AturSunting(null)}
                >
                    <FormMerek
                        key={sunting === 'baru' ? 'baru' : sunting.Uuid}
                        merek={sunting === 'baru' ? null : sunting}
                        saatSelesai={() => AturSunting(null)}
                    />
                </DialogFormulir>
            ) : null}
            <Card className="gap-0 py-0 rounded-panel shadow-none">
                <ul className="divide-y divide-garis">
                    {merek.map((baris) => (
                        <li
                            key={baris.Uuid}
                            className="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-isi"
                        >
                            <span>
                                <span className="font-semibold text-teks-utama">{baris.Nama}</span>
                                <span className="text-teks-sekunder"> | {baris.JumlahOutlet} outlet</span>
                            </span>
                            {bolehKelola ? (
                                <MenuAksiBaris
                                    label={`Aksi merek ${baris.Nama}`}
                                    aksi={[
                                        { label: 'Ganti nama', saatPilih: () => AturSunting(baris) },
                                        ...(baris.JumlahOutlet === 0 && merek.length > 1
                                            ? [
                                                  {
                                                      label: 'Hapus merek',
                                                      bahaya: true,
                                                      saatPilih: () =>
                                                          router.delete(`/kelola/merek/${baris.Uuid}`, {
                                                              preserveScroll: true,
                                                          }),
                                                  },
                                              ]
                                            : []),
                                    ]}
                                />
                            ) : null}
                        </li>
                    ))}
                </ul>
            </Card>
        </section>
    );
}

function FormMerek({ merek, saatSelesai }: { merek: Merek | null; saatSelesai: () => void }) {
    const formulir = useForm({ Nama: merek?.Nama ?? '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: saatSelesai };

        if (merek === null) {
            formulir.post('/kelola/merek', opsi);
        } else {
            formulir.put(`/kelola/merek/${merek.Uuid}`, opsi);
        }
    };

    return (
        <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
            <BidangTeks
                label="Nama merek"
                nilai={formulir.data.Nama}
                saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                galat={formulir.errors.Nama}
                maxLength={150}
                autoFocus
                required
            />
            <div className="flex flex-wrap gap-2">
                <Tombol type="submit" memproses={formulir.processing}>
                    Simpan merek
                </Tombol>
                <Tombol varian="sekunder" onClick={saatSelesai}>
                    Batal
                </Tombol>
            </div>
        </form>
    );
}
