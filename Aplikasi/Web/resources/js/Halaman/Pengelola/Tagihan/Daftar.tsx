import {
    AntreanRefund,
    PanelBatasRefund,
    type BarisRefundMenunggu,
} from '@/Komponen/Langganan/TindakanTagihanPengelola';
import { Link } from '@inertiajs/react';

import AksiMassalVerifikasi from '@/Komponen/Pengelola/AksiMassalVerifikasi';
import TabelData, { type KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { HasilTabel, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Card } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import type { Pilihan } from '@/Tipe/Pengelola';
import { JenisLabelTagihan, type PembayaranLangganan, type TagihanLangganan } from '@/Tipe/TagihanLangganan';

type BarisAntrean = PembayaranLangganan & {
    NamaTenant: string;
    UuidTagihan: string | null;
    NomorTagihan: string | null;
    TotalTagihan: string | null;
    NamaPaket: string | null;
};

type BarisTagihan = TagihanLangganan & { NamaTenant: string };

type PropsDaftarTagihan = {
    Antrean: BarisAntrean[];
    Tagihan: HasilTabel<BarisTagihan>;
    Ringkasan: { MenungguVerifikasi: number; BelumDibayar: number };
    OpsiStatus: Pilihan[];
    BolehVerifikasi?: boolean;
    RefundMenunggu?: BarisRefundMenunggu[];
    BolehSetujuiRefund?: boolean;
    BatasRefund?: string;
};

const kolomAntrean: KolomTabel<BarisAntrean>[] = [
    {
        id: 'DiunggahPada',
        accessorKey: 'DiunggahPada',
        header: 'Diunggah',
        meta: { label: 'Diunggah', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.DiunggahPada),
    },
    {
        id: 'NamaTenant',
        accessorKey: 'NamaTenant',
        header: 'Tenant',
        meta: { label: 'Tenant', prioritas: 'penting' },
    },
    {
        id: 'Tagihan',
        header: 'Tagihan',
        enableSorting: false,
        meta: { label: 'Tagihan', prioritas: 'penting' },
        cell: ({ row: { original: baris } }) => (
            <>
                {baris.UuidTagihan ? (
                    <Link href={`/tagihan/${baris.UuidTagihan}`} className="font-mono text-label text-brand underline">
                        {baris.NomorTagihan}
                    </Link>
                ) : null}
                <span className="block text-keterangan text-teks-sekunder">{baris.NamaPaket}</span>
            </>
        ),
    },
    {
        id: 'Transfer',
        header: 'Transfer',
        enableSorting: false,
        meta: { label: 'Transfer', prioritas: 'rendah' },
        cell: ({ row: { original: baris } }) => (
            <>
                <span className="block">{FormatTanggal(baris.TanggalTransfer)}</span>
                <span className="block text-keterangan text-teks-sekunder">
                    {baris.BankPengirim} → {baris.BankTujuan}
                </span>
            </>
        ),
    },
    {
        id: 'Jumlah',
        accessorKey: 'Jumlah',
        header: 'Jumlah',
        enableSorting: false,
        meta: { label: 'Jumlah', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.Jumlah),
    },
];

const kolomTagihan: KolomTabel<BarisTagihan>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        enableSorting: false,
        meta: { label: 'Nomor tagihan', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => (
            <Link href={`/tagihan/${row.original.Uuid}`} className="font-mono text-label text-brand underline">
                {row.original.Nomor}
            </Link>
        ),
    },
    {
        id: 'NamaTenant',
        header: 'Tenant',
        enableSorting: false,
        meta: { label: 'Tenant', prioritas: 'penting' },
        cell: ({ row }) => row.original.NamaTenant,
    },
    {
        id: 'Paket',
        header: 'Paket',
        enableSorting: false,
        meta: { label: 'Paket', prioritas: 'rendah' },
        cell: ({ row }) => `${row.original.NamaPaket} | ${row.original.Siklus}`,
    },
    {
        id: 'TerbitPada',
        accessorKey: 'TerbitPada',
        header: 'Terbit',
        meta: { label: 'Terbit', prioritas: 'rendah', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggal(row.original.TerbitPada),
    },
    {
        id: 'JatuhTempoPada',
        accessorKey: 'JatuhTempoPada',
        header: 'Jatuh tempo',
        meta: { label: 'Jatuh tempo', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.JatuhTempoPada),
    },
    {
        id: 'Total',
        accessorKey: 'Total',
        header: 'Total',
        meta: { label: 'Total', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.Total),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => (
            <LabelStatus jenis={JenisLabelTagihan(row.original.Status)} teks={row.original.LabelStatus} />
        ),
    },
];

/** Angka ringkasan antrean (kartu ringkas, tanpa warna: angka + teks sudah cukup). */
function KartuRingkasan({ nilai, label }: { nilai: number; label: string }) {
    return (
        <Card className="gap-1 px-4 py-3 rounded-panel shadow-none">
            <span className="text-judul font-semibold tabular-nums text-teks-utama">{nilai}</span>
            <span className="text-label text-teks-sekunder">{label}</span>
        </Card>
    );
}

/** Tagihan langganan & antrean "Menunggu Verifikasi" transfer manual (P-08 langkah 3), TabelData D-16. */
export default function HalamanDaftarTagihan({
    Antrean,
    Tagihan,
    Ringkasan,
    OpsiStatus,
    BolehVerifikasi = false,
    RefundMenunggu = [],
    BolehSetujuiRefund = false,
    BatasRefund = '1000000',
}: PropsDaftarTagihan) {
    return (
        <TataLetakPengelola judul="Tagihan langganan">
            <p className="text-label">
                <Link href="/laporan-langganan" className="text-brand underline">
                    Laporan langganan (MRR, churn)
                </Link>
            </p>
            <ul className="grid gap-3 sm:grid-cols-2" aria-label="Ringkasan tagihan">
                <li>
                    <KartuRingkasan nilai={Ringkasan.MenungguVerifikasi} label="bukti transfer menunggu verifikasi" />
                </li>
                <li>
                    <KartuRingkasan nilai={Ringkasan.BelumDibayar} label="tagihan belum dibayar" />
                </li>
            </ul>
            <AntreanRefund antrean={RefundMenunggu} boleh={BolehSetujuiRefund} />
            <PanelBatasRefund batas={BatasRefund} boleh={BolehSetujuiRefund} />
            <section aria-labelledby="judul-antrean" className="flex flex-col gap-2">
                <h2 id="judul-antrean" className="text-subjudul font-semibold text-teks-utama">
                    Menunggu verifikasi
                </h2>
                <TabelData
                    id="pengelola-tagihan-antrean"
                    label="Bukti transfer menunggu verifikasi, terlama di atas"
                    kolom={kolomAntrean}
                    sumber={{ mode: 'lokal', data: Antrean }}
                    ambilIdBaris={(baris) => baris.Uuid}
                    {...(BolehVerifikasi
                        ? {
                              aksiMassal: (konteks: KonteksAksiMassal<BarisAntrean>) => (
                                  <AksiMassalVerifikasi konteks={konteks} />
                              ),
                          }
                        : {})}
                    alamatDetail={(baris) => (baris.UuidTagihan ? `/tagihan/${baris.UuidTagihan}` : '')}
                    kosong={{
                        ilustrasi: true,
                        judul: 'Antrean kosong. Belum ada bukti transfer yang perlu diperiksa.',
                    }}
                />
            </section>
            <section aria-labelledby="judul-semua" className="flex flex-col gap-2">
                <h2 id="judul-semua" className="text-subjudul font-semibold text-teks-utama">
                    Semua tagihan
                </h2>
                <TabelData
                    id="pengelola-tagihan"
                    label="Daftar tagihan langganan"
                    kolom={kolomTagihan}
                    sumber={{ mode: 'server', alamat: '/tagihan', awal: Tagihan }}
                    ambilIdBaris={(baris) => baris.Uuid}
                    urutBawaan="-TerbitPada"
                    cari="Cari nomor tagihan atau nama usaha"
                    saring={[
                        {
                            id: 'Status',
                            label: 'Status',
                            jenis: 'pilihanBanyak',
                            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
                        },
                        { id: 'TerbitPada', label: 'Tanggal terbit', jenis: 'rentangTanggal' },
                    ]}
                    alamatDetail={(baris) => `/tagihan/${baris.Uuid}`}
                    kosong={{
                        ilustrasi: true,
                        judul: 'Belum ada tagihan. Tagihan terbit otomatis saat tenant berlangganan paket.',
                    }}
                />
            </section>
        </TataLetakPengelola>
    );
}
