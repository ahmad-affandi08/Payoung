import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import AksiMassalPelanggan from '@/Komponen/Pelanggan/AksiMassalPelanggan';
import FormulirPelanggan, { AlamatPelanggan } from '@/Komponen/Pelanggan/FormulirPelanggan';
import TabelData, { type KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisPelanggan, PropsDaftarPelanggan } from '@/Tipe/Pelanggan';

const kolom: KolomTabel<BarisPelanggan>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Nama pelanggan',
        meta: { label: 'Nama pelanggan', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: p } }) => (
            <span className="flex flex-col">
                <Link
                    href={`${AlamatPelanggan}/${p.Uuid}`}
                    className="font-semibold break-words text-teks-utama underline-offset-2 hover:underline"
                >
                    {p.Nama}
                </Link>
                {p.Tag.length > 0 ? (
                    <span className="text-keterangan text-teks-sekunder">{p.Tag.join(' | ')}</span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'Kontak',
        header: 'Kontak',
        enableSorting: false,
        meta: { label: 'Kontak', prioritas: 'penting' },
        cell: ({ row: { original: p } }) => (
            <span className="flex flex-col break-words">
                <span className="font-mono whitespace-nowrap">{p.NoHp}</span>
                {p.Email ? <span className="text-keterangan text-teks-sekunder">{p.Email}</span> : null}
            </span>
        ),
    },
    {
        id: 'Tier',
        header: 'Tier',
        enableSorting: false,
        meta: { label: 'Tier', prioritas: 'penting' },
        cell: ({ row: { original: p } }) => (p.Tier ? `${p.Tier.Nama}${p.TierTetap ? ' (dikunci)' : ''}` : '—'),
    },
    {
        id: 'SaldoPoin',
        header: 'Poin',
        enableSorting: false,
        meta: { label: 'Poin', prioritas: 'rendah', angka: true },
        cell: ({ row }) => row.original.SaldoPoin.toLocaleString('id-ID'),
    },
    {
        id: 'SaldoDeposit',
        header: 'Deposit',
        meta: { label: 'Deposit', prioritas: 'rendah', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.SaldoDeposit),
    },
    {
        id: 'JumlahTransaksi',
        header: 'Transaksi',
        enableSorting: false,
        meta: { label: 'Transaksi', prioritas: 'rendah', angka: true },
        cell: ({ row }) => row.original.JumlahTransaksi.toLocaleString('id-ID'),
    },
    {
        id: 'TotalBelanja',
        header: 'Total belanja',
        enableSorting: false,
        meta: { label: 'Total belanja', prioritas: 'penting', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.TotalBelanja),
    },
    {
        id: 'TerakhirPada',
        header: 'Terakhir belanja',
        enableSorting: false,
        meta: { label: 'Terakhir belanja', prioritas: 'rendah' },
        cell: ({ row }) => (row.original.TerakhirPada ? FormatTanggal(row.original.TerakhirPada.slice(0, 10)) : '—'),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) =>
            row.original.Status === 'Aktif' ? (
                <LabelStatus jenis="sukses" teks="Aktif" />
            ) : (
                <LabelStatus jenis="netral" teks="Diarsipkan" />
            ),
    },
];

/** F-16a CRM-01: daftar pelanggan dengan ringkasan belanja; ubah (panel), arsipkan & pulihkan. Tambah di halaman `/kelola/pelanggan/buat`. */
export default function HalamanDaftarPelanggan({ Pelanggan, Izin, OpsiTag, OpsiTier }: PropsDaftarPelanggan) {
    const [ubah, AturUbah] = useState<BarisPelanggan | null>(null);

    return (
        <TataLetakAplikasi judul="Pelanggan">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Data pelanggan untuk riwayat belanja, loyalti, dan promo. Nomor HP menjadi kunci pelanggan. Kasir bisa
                memilih atau menambah pelanggan langsung dari aplikasi POS, juga saat offline.
            </p>
            {Izin.Kelola ? (
                <AksiHalaman
                    utama={
                        <Button asChild>
                            <Link href={`${AlamatPelanggan}/buat`}>Tambah pelanggan</Link>
                        </Button>
                    }
                    lainnya={[
                        { label: 'Kampanye pesan', href: `${AlamatPelanggan}/kampanye` },
                        { label: 'Impor pelanggan', href: `${AlamatPelanggan}/impor` },
                    ]}
                />
            ) : (
                <PesanHanyaLihat izin="pelanggan.kelola" objek="pelanggan" />
            )}

            <TabelData
                id="pelanggan"
                label="Daftar pelanggan"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: AlamatPelanggan, awal: Pelanggan }}
                ambilIdBaris={(p) => p.Uuid}
                urutBawaan="Nama"
                cari="Cari nama, nomor HP, atau email"
                {...(Izin.Kelola ? { ekspor: { alamat: `${AlamatPelanggan}/ekspor`, label: 'Ekspor ke Excel' } } : {})}
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: [
                            { nilai: 'Aktif', label: 'Aktif' },
                            { nilai: 'Diarsipkan', label: 'Diarsipkan' },
                        ],
                    },
                    ...(OpsiTier.length > 0
                        ? [
                              {
                                  id: 'Tier',
                                  label: 'Tier',
                                  jenis: 'pilihanBanyak' as const,
                                  opsi: OpsiTier.map((t) => ({ nilai: t.Nilai, label: t.Label })),
                              },
                          ]
                        : []),
                    ...(OpsiTag.length > 0
                        ? [
                              {
                                  id: 'Tag',
                                  label: 'Tag',
                                  jenis: 'pilihanBanyak' as const,
                                  opsi: OpsiTag.map((t) => ({ nilai: t, label: t })),
                              },
                          ]
                        : []),
                ]}
                labelBaris={(p) => `untuk pelanggan ${p.Nama}`}
                {...(Izin.Kelola
                    ? {
                          aksiMassal: (konteks: KonteksAksiMassal<BarisPelanggan>) => (
                              <AksiMassalPelanggan konteks={konteks} tier={OpsiTier} />
                          ),
                      }
                    : {})}
                aksiBaris={(p: BarisPelanggan) => (
                    <ItemAksiBaris
                        aksi={[
                            {
                                label: 'Lihat riwayat belanja',
                                saatPilih: () => router.visit(`${AlamatPelanggan}/${p.Uuid}`),
                            },
                            ...(Izin.Kelola
                                ? [
                                      { label: 'Ubah pelanggan', saatPilih: () => AturUbah(p) },
                                      p.Status === 'Aktif'
                                          ? {
                                                label: 'Arsipkan pelanggan',
                                                bahaya: true,
                                                saatPilih: () =>
                                                    router.post(
                                                        `${AlamatPelanggan}/${p.Uuid}/arsipkan`,
                                                        {},
                                                        { preserveScroll: true },
                                                    ),
                                            }
                                          : {
                                                label: 'Pulihkan pelanggan',
                                                saatPilih: () =>
                                                    router.post(
                                                        `${AlamatPelanggan}/${p.Uuid}/pulihkan`,
                                                        {},
                                                        { preserveScroll: true },
                                                    ),
                                            },
                                  ]
                                : []),
                        ]}
                    />
                )}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada pelanggan. Tambahkan di sini atau dari aplikasi kasir saat transaksi.',
                }}
            />

            {ubah !== null ? <FormulirPelanggan pelanggan={ubah} saatTutup={() => AturUbah(null)} /> : null}
        </TataLetakAplikasi>
    );
}
