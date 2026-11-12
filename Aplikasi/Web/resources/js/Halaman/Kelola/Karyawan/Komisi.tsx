import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import FormulirAturanKomisi, { AlamatAturanKomisi } from '@/Komponen/Karyawan/FormulirAturanKomisi';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisAturanKomisi, JenisKomisi, PropsAturanKomisi } from '@/Tipe/Karyawan';

const alamat = AlamatAturanKomisi;

/** `10.00` persen → `10%`; nominal → `Rp 5.000 / jumlah`. */
export function FormatNilaiKomisi(jenis: JenisKomisi, nilai: string): string {
    return jenis === 'Persen'
        ? `${nilai.replace(/\.00$/, '').replace('.', ',')}%`
        : `${FormatRupiah(nilai)} per jumlah`;
}

const kolom: KolomTabel<BarisAturanKomisi>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Nama aturan',
        meta: { label: 'Nama aturan', prioritas: 'utama', wajib: true },
        cell: ({ row }) => <span className="font-semibold break-words">{row.original.Nama}</span>,
    },
    {
        id: 'Sasaran',
        header: 'Berlaku untuk',
        enableSorting: false,
        meta: { label: 'Berlaku untuk', prioritas: 'penting' },
        cell: ({ row: { original: a } }) => (
            <span className="flex flex-col">
                <span className="break-words">{a.NamaSasaran}</span>
                <span className="text-keterangan text-teks-sekunder">
                    {a.LabelCakupan} | {a.LevelStaf ? `level ${a.LevelStaf}` : 'semua level'}
                </span>
            </span>
        ),
    },
    {
        id: 'Nilai',
        header: 'Komisi',
        enableSorting: false,
        meta: { label: 'Komisi', prioritas: 'penting', angka: true },
        cell: ({ row }) => FormatNilaiKomisi(row.original.Jenis, row.original.Nilai),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'rendah' },
        cell: ({ row }) => (
            <LabelStatus jenis={row.original.Status === 'Aktif' ? 'sukses' : 'netral'} teks={row.original.Status} />
        ),
    },
];

/** F-18 EMP-04: aturan komisi (produk paling spesifik menang; level staf yang cocok diutamakan). */
export default function HalamanAturanKomisi({ Aturan, OpsiKategori, Izin }: PropsAturanKomisi) {
    const [ubah, AturUbah] = useState<BarisAturanKomisi | null>(null);
    const tombol = Izin.Kelola ? (
        <Button asChild>
            <Link href={`${alamat}/buat`}>Tambah aturan komisi</Link>
        </Button>
    ) : null;

    return (
        <TataLetakAplikasi judul="Aturan komisi">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Kasir memilih staf yang melayani tiap baris. Komisi memakai aturan paling spesifik dan dibagi rata bila
                beberapa staf.
            </p>
            {Izin.Kelola ? (
                <AksiHalaman>{tombol}</AksiHalaman>
            ) : (
                <PesanHanyaLihat izin="karyawan.kelola" objek="aturan komisi" />
            )}
            <TabelData
                id="aturan-komisi"
                label="Daftar aturan komisi"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Aturan }}
                ambilIdBaris={(a) => a.Uuid}
                urutBawaan="Nama"
                cari="Cari nama aturan"
                labelBaris={(a) => `untuk aturan ${a.Nama}`}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (a: BarisAturanKomisi) => (
                              <ItemAksiBaris
                                  aksi={[
                                      { label: 'Ubah aturan', saatPilih: () => AturUbah(a) },
                                      a.Status === 'Aktif'
                                          ? {
                                                label: 'Arsipkan aturan',
                                                bahaya: true,
                                                saatPilih: () =>
                                                    router.post(
                                                        `${alamat}/${a.Uuid}/arsipkan`,
                                                        {},
                                                        { preserveScroll: true },
                                                    ),
                                            }
                                          : {
                                                label: 'Pulihkan aturan',
                                                saatPilih: () =>
                                                    router.post(
                                                        `${alamat}/${a.Uuid}/pulihkan`,
                                                        {},
                                                        { preserveScroll: true },
                                                    ),
                                            },
                                  ]}
                              />
                          ),
                      }
                    : {})}
                kosong={{ ilustrasi: true, judul: 'Belum ada aturan komisi.' }}
            />
            {ubah !== null ? (
                <FormulirAturanKomisi aturan={ubah} opsiKategori={OpsiKategori} saatTutup={() => AturUbah(null)} />
            ) : null}
        </TataLetakAplikasi>
    );
}
