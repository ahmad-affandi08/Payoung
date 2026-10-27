import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import FormPemasok, { AlamatPemasok as alamat, BuatIsianPemasok } from '@/Komponen/Pembelian/FormPemasok';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisPemasok, PropsDaftarPemasok } from '@/Tipe/Pembelian';

/** Teks termin: 0 = tunai, N = tempo N hari. */
export function FormatTermin(hari: number): string {
    return hari === 0 ? 'Tunai' : `Tempo ${hari.toLocaleString('id-ID')} hari`;
}

const kolom: KolomTabel<BarisPemasok>[] = [
    {
        id: 'Kode',
        accessorKey: 'Kode',
        header: 'Kode',
        meta: { label: 'Kode', prioritas: 'penting', kelasSel: 'font-mono whitespace-nowrap' },
    },
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Nama pemasok',
        meta: { label: 'Nama pemasok', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: p } }) => (
            <span className="flex flex-col">
                <span className="font-semibold break-words text-teks-utama">{p.Nama}</span>
                {p.NamaKontak ? <span className="text-keterangan text-teks-sekunder">{p.NamaKontak}</span> : null}
            </span>
        ),
    },
    {
        id: 'Kontak',
        header: 'Kontak',
        enableSorting: false,
        meta: { label: 'Kontak', prioritas: 'rendah' },
        cell: ({ row: { original: p } }) => (
            <span className="flex flex-col break-words">
                <span>{p.NoHp ?? '—'}</span>
                {p.Email ? <span className="text-keterangan text-teks-sekunder">{p.Email}</span> : null}
            </span>
        ),
    },
    {
        id: 'TerminHari',
        accessorKey: 'TerminHari',
        header: 'Termin',
        meta: { label: 'Termin', prioritas: 'penting' },
        cell: ({ row }) => FormatTermin(row.original.TerminHari),
    },
    {
        id: 'Pajak',
        header: 'PKP',
        enableSorting: false,
        meta: { label: 'PKP', prioritas: 'rendah' },
        cell: ({ row: { original: p } }) => (p.Pkp ? `PKP${p.Npwp ? ` | ${p.Npwp}` : ''}` : 'Bukan PKP'),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) =>
            row.original.Aktif ? (
                <LabelStatus jenis="sukses" teks="Aktif" />
            ) : (
                <LabelStatus jenis="netral" teks="Nonaktif" />
            ),
    },
];

/** F-04 fase 1: master pemasok (tambah, ubah, nonaktifkan, hapus bila belum dipakai). */
export default function HalamanDaftarPemasok({ Pemasok, Izin }: PropsDaftarPemasok) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [ubah, AturUbah] = useState<BarisPemasok | null>(null);
    const [hapus, AturHapus] = useState<BarisPemasok | null>(null);
    const [memproses, AturMemproses] = useState(false);
    return (
        <TataLetakAplikasi judul="Pemasok">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Daftar pemasok untuk pesanan pembelian, penerimaan barang, dan hutang. Termin bawaan dipakai untuk
                menghitung jatuh tempo faktur. Pemasok yang sudah dipakai tidak bisa dihapus. Nonaktifkan saja.
            </p>
            {Izin.Kelola ? (
                <AksiHalaman
                    utama={
                        <Button asChild>
                            <Link href={`${alamat}/buat`}>Tambah pemasok</Link>
                        </Button>
                    }
                    lainnya={[{ label: 'Impor pemasok', href: `${alamat}/impor` }]}
                />
            ) : (
                <PesanHanyaLihat izin="pembelian.kelola" objek="pemasok" />
            )}

            <TabelData
                id="pembelian-pemasok"
                label="Daftar pemasok"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Pemasok }}
                ambilIdBaris={(p) => p.Uuid}
                urutBawaan="Nama"
                cari="Cari kode, nama, kontak, no. HP, atau NPWP"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: [
                            { nilai: 'Aktif', label: 'Aktif' },
                            { nilai: 'Nonaktif', label: 'Nonaktif' },
                        ],
                    },
                ]}
                labelBaris={(p) => `untuk pemasok ${p.Nama}`}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (p: BarisPemasok) => (
                              <ItemAksiBaris
                                  aksi={[
                                      { label: 'Ubah pemasok', saatPilih: () => AturUbah(p) },
                                      {
                                          label: p.Aktif ? 'Nonaktifkan pemasok' : 'Aktifkan pemasok',
                                          saatPilih: () =>
                                              router.post(
                                                  `${alamat}/${p.Uuid}/status`,
                                                  { Aktif: !p.Aktif },
                                                  { preserveScroll: true },
                                              ),
                                          bahaya: p.Aktif,
                                      },
                                      { label: 'Hapus pemasok', saatPilih: () => AturHapus(p), bahaya: true },
                                  ]}
                              />
                          ),
                      }
                    : {})}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada pemasok. Tambahkan pemasok agar bisa membuat pesanan pembelian.',
                }}
            />

            {ubah !== null ? (
                <DialogFormulir
                    judul={`Ubah pemasok ${ubah.Nama}`}
                    jenis="panel"
                    galatUmum={galat.Umum}
                    saatTutup={() => AturUbah(null)}
                >
                    <FormPemasok
                        key={ubah.Uuid}
                        uuid={ubah.Uuid}
                        awal={BuatIsianPemasok(ubah)}
                        saatSelesai={() => AturUbah(null)}
                        saatBatal={() => AturUbah(null)}
                    />
                </DialogFormulir>
            ) : null}

            {hapus !== null ? (
                <DialogKonfirmasi
                    judul={`Hapus pemasok ${hapus.Nama}?`}
                    labelAksi="Hapus pemasok"
                    memproses={memproses}
                    saatBatal={() => AturHapus(null)}
                    saatKonfirmasi={() =>
                        router.delete(`${alamat}/${hapus.Uuid}`, {
                            preserveScroll: true,
                            onStart: () => AturMemproses(true),
                            onFinish: () => {
                                AturMemproses(false);
                                AturHapus(null);
                            },
                        })
                    }
                >
                    <p>Pemasok yang sudah dipakai di dokumen pembelian tidak bisa dihapus. Nonaktifkan saja.</p>
                </DialogKonfirmasi>
            ) : null}
        </TataLetakAplikasi>
    );
}
