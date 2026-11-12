import { Link, router } from '@inertiajs/react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import PesanFiturPaketSesi from '@/Komponen/Pelanggan/PesanFiturPaketSesi';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisPaketSesi, PropsDaftarPaketSesi } from '@/Tipe/Katalog';

const alamat = '/kelola/paket-sesi';

const kolom: KolomTabel<BarisPaketSesi>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Paket',
        meta: { label: 'Paket', prioritas: 'utama', wajib: true },
        cell: ({ row }) => <span className="font-semibold break-words">{row.original.Nama}</span>,
    },
    {
        id: 'JumlahSesi',
        accessorKey: 'JumlahSesi',
        header: 'Jumlah sesi',
        meta: { label: 'Jumlah sesi', prioritas: 'utama', angka: true },
        cell: ({ row }) => `${row.original.JumlahSesi.toLocaleString('id-ID')} sesi`,
    },
    {
        id: 'MasaBerlakuHari',
        accessorKey: 'MasaBerlakuHari',
        header: 'Masa berlaku',
        meta: { label: 'Masa berlaku', prioritas: 'penting' },
        cell: ({ row }) =>
            row.original.MasaBerlakuHari === null
                ? 'Tanpa batas'
                : `${row.original.MasaBerlakuHari.toLocaleString('id-ID')} hari`,
    },
    {
        id: 'ProdukBerlaku',
        header: 'Layanan yang bisa ditukar',
        enableSorting: false,
        meta: { label: 'Layanan yang bisa ditukar', prioritas: 'rendah' },
        cell: ({ row }) =>
            row.original.SemuaProdukJasa
                ? 'Semua layanan (produk Jasa)'
                : `${row.original.JumlahProdukBerlaku.toLocaleString('id-ID')} layanan`,
    },
    {
        id: 'Aktif',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => (
            <LabelStatus
                jenis={row.original.Aktif ? 'sukses' : 'netral'}
                teks={row.original.Aktif ? 'Aktif' : 'Tidak aktif'}
            />
        ),
    },
];

const saring: DefinisiSaring[] = [
    {
        id: 'Aktif',
        label: 'Status',
        jenis: 'pilihanBanyak',
        opsi: [
            { nilai: 'Ya', label: 'Aktif' },
            { nilai: 'Tidak', label: 'Tidak aktif' },
        ],
    },
];

/** F-16d bagian 2: paket sesi (produk Jasa yang dijual sebagai N sesi dan dipakai bertahap di kasir). */
export default function HalamanDaftarPaketSesi({ PaketSesi, Izin, FiturAktif }: PropsDaftarPaketSesi) {
    return (
        <TataLetakAplikasi judul="Paket sesi">
            {FiturAktif ? null : <PesanFiturPaketSesi />}
            {!Izin.Kelola ? <PesanHanyaLihat izin="produk.kelola" objek="paket sesi" /> : null}
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Produk Jasa yang dijual sekali bayar untuk beberapa sesi, misal 10x creambath. Pendapatan diakui saat
                sesi dipakai.
            </p>
            {Izin.Kelola && FiturAktif ? (
                <AksiHalaman>
                    <Button asChild>
                        <Link href={`${alamat}/buat`}>Tambah paket sesi</Link>
                    </Button>
                </AksiHalaman>
            ) : null}
            <TabelData
                id="paket-sesi"
                label="Daftar paket sesi"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: PaketSesi }}
                ambilIdBaris={(p) => p.Uuid}
                urutBawaan="Nama"
                cari="Cari nama paket"
                saring={saring}
                labelBaris={(p) => `paket ${p.Nama}`}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (p: BarisPaketSesi) => (
                              <ItemAksiBaris
                                  aksi={[
                                      {
                                          label: 'Ubah paket sesi',
                                          saatPilih: () => router.visit(`${alamat}/${p.Uuid}/ubah`),
                                      },
                                  ]}
                              />
                          ),
                      }
                    : {})}
                kosong={{ ilustrasi: true, judul: 'Belum ada paket sesi.' }}
            />
        </TataLetakAplikasi>
    );
}
