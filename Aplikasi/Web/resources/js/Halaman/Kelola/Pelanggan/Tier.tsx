import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import FormulirTier, { AlamatTier, type IsianTier } from '@/Komponen/Pelanggan/FormulirTier';
import PesanFiturLoyalti from '@/Komponen/Pelanggan/PesanFiturLoyalti';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisTier, PropsTierPelanggan } from '@/Tipe/Pelanggan';

/** `1.50` → `×1,5`. */
export function FormatPengali(nilai: string): string {
    const [bulat = '0', pecahan = ''] = nilai.split('.');
    const sisa = pecahan.replace(/0+$/, '');

    return `×${bulat}${sisa === '' ? '' : `,${sisa}`}`;
}

const kolom: KolomTabel<BarisTier>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Tier',
        meta: { label: 'Tier', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: t } }) => (
            <span className="flex flex-col">
                <span className="font-semibold text-teks-utama">{t.Nama}</span>
                <span className="font-mono text-keterangan text-teks-sekunder">{t.Kode}</span>
            </span>
        ),
    },
    {
        id: 'MinimalBelanja',
        accessorKey: 'MinimalBelanja',
        header: 'Minimal belanja',
        meta: { label: 'Minimal belanja', prioritas: 'penting', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.MinimalBelanja),
    },
    {
        id: 'PengaliPoin',
        accessorKey: 'PengaliPoin',
        header: 'Pengali poin',
        meta: { label: 'Pengali poin', prioritas: 'penting', angka: true },
        cell: ({ row }) => FormatPengali(row.original.PengaliPoin),
    },
    {
        id: 'JumlahPelanggan',
        accessorKey: 'JumlahPelanggan',
        header: 'Pelanggan',
        meta: { label: 'Pelanggan', prioritas: 'rendah', angka: true },
        cell: ({ row }) => row.original.JumlahPelanggan.toLocaleString('id-ID'),
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

/**
 * F-16b: tier pelanggan (ambang belanja untuk naik/turun otomatis, pengali poin, kode untuk daftar harga). Ubah di
 * panel; tambah di halaman `/kelola/pelanggan/tier/buat`.
 */
export default function HalamanTierPelanggan({ Tier, FiturAktif, Izin }: PropsTierPelanggan) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [ubah, AturUbah] = useState<{ uuid: string; isian: IsianTier } | null>(null);
    const tombolTambah = (
        <Button asChild>
            <Link href={`${AlamatTier}/buat`}>Tambah tier</Link>
        </Button>
    );

    const BukaUbah = (t: BarisTier) =>
        AturUbah({
            uuid: t.Uuid,
            isian: {
                Kode: t.Kode,
                Nama: t.Nama,
                MinimalBelanja: t.MinimalBelanja,
                PengaliPoin: t.PengaliPoin,
                Urutan: String(t.Urutan),
            },
        });

    return (
        <TataLetakAplikasi judul="Tier pelanggan">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Tier dinilai ulang tiap malam dari total belanja. Pengali poin melipatgandakan poin belanja.
            </p>
            {FiturAktif ? null : <PesanFiturLoyalti />}
            {Izin.Kelola ? (
                <AksiHalaman>{tombolTambah}</AksiHalaman>
            ) : (
                <PesanHanyaLihat izin="pelanggan.kelola" objek="tier pelanggan" />
            )}

            <TabelData
                id="pelanggan-tier"
                label="Daftar tier pelanggan"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Tier }}
                ambilIdBaris={(t) => t.Uuid}
                urutBawaan="MinimalBelanja"
                labelBaris={(t) => `untuk tier ${t.Nama}`}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (t: BarisTier) => (
                              <ItemAksiBaris
                                  aksi={[
                                      { label: 'Ubah tier', saatPilih: () => BukaUbah(t) },
                                      t.Status === 'Aktif'
                                          ? {
                                                label: 'Arsipkan tier',
                                                bahaya: true,
                                                saatPilih: () =>
                                                    router.post(
                                                        `${AlamatTier}/${t.Uuid}/arsipkan`,
                                                        {},
                                                        { preserveScroll: true },
                                                    ),
                                            }
                                          : {
                                                label: 'Pulihkan tier',
                                                saatPilih: () =>
                                                    router.post(
                                                        `${AlamatTier}/${t.Uuid}/pulihkan`,
                                                        {},
                                                        { preserveScroll: true },
                                                    ),
                                            },
                                  ]}
                              />
                          ),
                      }
                    : {})}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada tier. Contoh: Silver mulai Rp 1.000.000, Gold mulai Rp 5.000.000 per 12 bulan.',
                }}
            />

            {ubah !== null ? (
                <DialogFormulir
                    judul={`Ubah tier ${ubah.isian.Nama}`}
                    jenis="panel"
                    galatUmum={props.errors.Umum}
                    saatTutup={() => AturUbah(null)}
                >
                    <FormulirTier
                        uuid={ubah.uuid}
                        awal={ubah.isian}
                        saatSelesai={() => AturUbah(null)}
                        saatBatal={() => AturUbah(null)}
                    />
                </DialogFormulir>
            ) : null}
        </TataLetakAplikasi>
    );
}
