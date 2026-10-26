import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import AksiMassalSederhana from '@/Komponen/TabelData/AksiMassalSederhana';
import TabelData, { type KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { Button } from '@/Komponen/Ui/button';
import { Card } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisPromo, ModeResolusiPromo, PropsDaftarPromo } from '@/Tipe/Promo';

const alamat = '/kelola/promo';

/** `2026-10-01T17:00:00Z` → `2 Okt 2026` (tanggal lokal peramban). */
export function FormatTanggalPromo(nilai: string | null): string {
    return nilai === null
        ? '—'
        : new Date(nilai).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
}

const kolom: KolomTabel<BarisPromo>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Promo',
        meta: { label: 'Promo', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: p } }) => (
            <span className="flex flex-col">
                <span className="font-semibold text-teks-utama">{p.Nama}</span>
                <span className="font-mono text-keterangan text-teks-sekunder">{p.Kode}</span>
            </span>
        ),
    },
    {
        id: 'LabelAksi',
        accessorKey: 'LabelAksi',
        header: 'Jenis',
        meta: { label: 'Jenis', prioritas: 'penting' },
        cell: ({ row: { original: p } }) =>
            [p.LabelAksi, p.Eksklusif ? 'eksklusif' : null, p.WajibVoucher ? 'wajib voucher' : null]
                .filter(Boolean)
                .join(' | '),
    },
    {
        id: 'Periode',
        accessorFn: (p) => p.MulaiPada ?? '',
        header: 'Periode',
        meta: { label: 'Periode', prioritas: 'rendah' },
        cell: ({ row: { original: p } }) =>
            p.MulaiPada === null && p.SelesaiPada === null
                ? 'Tanpa batas waktu'
                : `${FormatTanggalPromo(p.MulaiPada)} – ${FormatTanggalPromo(p.SelesaiPada)}`,
    },
    {
        id: 'Prioritas',
        accessorKey: 'Prioritas',
        header: 'Prioritas',
        meta: { label: 'Prioritas', prioritas: 'rendah', angka: true },
    },
    {
        id: 'JumlahPakai',
        accessorKey: 'JumlahPakai',
        header: 'Dipakai',
        meta: { label: 'Dipakai', prioritas: 'penting', angka: true },
        cell: ({ row: { original: p } }) =>
            p.Kuota === null
                ? p.JumlahPakai.toLocaleString('id-ID')
                : `${p.KuotaTerpakai.toLocaleString('id-ID')} / ${p.Kuota.toLocaleString('id-ID')}`,
    },
    {
        id: 'TotalDiskon',
        accessorKey: 'TotalDiskon',
        header: 'Total potongan',
        meta: { label: 'Total potongan', prioritas: 'penting', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.TotalDiskon),
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

const opsiMode = [
    { Nilai: 'Terbaik', Label: 'Terbaik untuk pelanggan' },
    { Nilai: 'PrioritasKetat', Label: 'Prioritas ketat' },
];

/**
 * F-16c: daftar promo otomatis (diterapkan kasir tanpa kode, juga saat offline) dengan ringkasan pemakaian, dan cara
 * memilih promo bila beberapa berlaku bersamaan.
 */
export default function HalamanDaftarPromo({ Promo, ModeResolusi, FiturAktif, Izin }: PropsDaftarPromo) {
    const [mode, AturMode] = useState<ModeResolusiPromo>(ModeResolusi);

    const SimpanMode = (nilai: string) => {
        const baru = nilai === 'PrioritasKetat' ? 'PrioritasKetat' : 'Terbaik';
        AturMode(baru);
        router.put(`${alamat}/pengaturan`, { ModeResolusi: baru }, { preserveScroll: true });
    };

    return (
        <TataLetakAplikasi judul="Promo">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Promo diterapkan otomatis di kasir saat syaratnya terpenuhi (tanggal, hari & jam, outlet, kanal, tier
                pelanggan, barang, minimal belanja), termasuk saat kasir offline. Potongan promo mengurangi harga
                sebelum pajak.
            </p>
            {FiturAktif ? null : (
                <Pemberitahuan jenis="peringatan" judul="Fitur promo tersedia di paket Pro ke atas">
                    Promo bisa disiapkan sekarang, tetapi baru diterapkan di kasir setelah paket dinaikkan.
                </Pemberitahuan>
            )}
            {Izin.Kelola ? null : <PesanHanyaLihat izin="pelanggan.kelola" objek="promo" />}

            <Card className="gap-3 rounded-panel p-4 shadow-none lg:grid lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)] lg:items-start lg:gap-6">
                <BidangPilihan
                    label="Bila beberapa promo berlaku"
                    nilai={mode}
                    opsi={opsiMode}
                    saatBerubah={SimpanMode}
                    disabled={!Izin.Kelola}
                    required
                />
                <p className="text-keterangan text-teks-sekunder lg:pt-6">
                    {mode === 'Terbaik'
                        ? 'Kasir memilih yang potongannya paling besar: semua promo biasa digabung, atau satu promo eksklusif.'
                        : 'Promo dipakai urut prioritas (angka besar dulu); promo eksklusif hanya dipakai bila belum ada promo lain dan menghentikan promo berikutnya.'}
                </p>
            </Card>

            {Izin.Kelola ? (
                <AksiHalaman>
                    <Button asChild>
                        <Link href={`${alamat}/buat`}>Tambah promo</Link>
                    </Button>
                </AksiHalaman>
            ) : null}

            <TabelData
                id="promo"
                label="Daftar promo"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Promo }}
                ambilIdBaris={(p) => p.Uuid}
                urutBawaan="-Prioritas"
                labelBaris={(p) => `untuk promo ${p.Nama}`}
                alamatDetail={(p) => `${alamat}/${p.Uuid}/efektivitas`}
                {...(Izin.Kelola
                    ? {
                          aksiMassal: (konteks: KonteksAksiMassal<BarisPromo>) => (
                              <AksiMassalSederhana
                                  konteks={konteks}
                                  alamat={`${alamat}/massal`}
                                  ambilUuid={(p) => p.Uuid}
                                  tombol={[
                                      { aksi: 'Pulihkan', label: 'Aktifkan' },
                                      { aksi: 'Arsipkan', label: 'Arsipkan', varian: 'bahaya' },
                                  ]}
                                  maksimal={200}
                                  objek="promo"
                              />
                          ),
                          aksiBaris: (p: BarisPromo) => (
                              <ItemAksiBaris
                                  aksi={[
                                      {
                                          label: 'Ubah promo',
                                          saatPilih: () => router.visit(`${alamat}/${p.Uuid}/ubah`),
                                      },
                                      {
                                          label: 'Lihat efektivitas',
                                          saatPilih: () => router.visit(`${alamat}/${p.Uuid}/efektivitas`),
                                      },
                                      ...(p.WajibVoucher
                                          ? [
                                                {
                                                    label: 'Kelola voucher',
                                                    saatPilih: () => router.visit(`${alamat}/${p.Uuid}/voucher`),
                                                },
                                            ]
                                          : []),
                                      p.Status === 'Aktif'
                                          ? {
                                                label: 'Arsipkan promo',
                                                bahaya: true,
                                                saatPilih: () =>
                                                    router.post(
                                                        `${alamat}/${p.Uuid}/arsipkan`,
                                                        {},
                                                        { preserveScroll: true },
                                                    ),
                                            }
                                          : {
                                                label: 'Pulihkan promo',
                                                saatPilih: () =>
                                                    router.post(
                                                        `${alamat}/${p.Uuid}/pulihkan`,
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
                    judul: 'Belum ada promo. Contoh: happy hour 2 kopi Rp 30.000, beli 2 gratis 1, atau diskon 10% member Gold.',
                }}
            />
        </TataLetakAplikasi>
    );
}
