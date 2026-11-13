import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import DaftarAlasanTinjauan from '@/Komponen/Penjualan/DaftarAlasanTinjauan';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Card } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok, FormatNilai } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisDetailRetur, BarisMutasiPenjualan, BarisRefundRetur, PropsDetailRetur } from '@/Tipe/Penjualan';

const nol = /^-?0+(\.0+)?$/;

const kolomBaris: KolomTabel<BarisDetailRetur>[] = [
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block text-teks-utama">{b.NamaProduk}</span>
                <span className="block text-label text-teks-sekunder">
                    {b.NamaGudang ? `Masuk ke ${b.NamaGudang}` : 'Tanpa stok'}
                </span>
            </>
        ),
    },
    {
        id: 'Kondisi',
        header: 'Kondisi',
        enableSorting: false,
        meta: { label: 'Kondisi', prioritas: 'penting' },
        cell: ({ row: { original: b } }) => (
            <LabelStatus jenis={b.Kondisi === 'Rusak' ? 'peringatan' : 'sukses'} teks={b.LabelKondisi} />
        ),
    },
    {
        id: 'Jumlah',
        header: 'Jumlah',
        enableSorting: false,
        meta: { label: 'Jumlah', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => FormatJumlahStok(b.Jumlah, ''),
    },
    {
        id: 'Pajak',
        header: 'Pajak',
        enableSorting: false,
        meta: { label: 'Pajak', angka: true, prioritas: 'rendah' },
        cell: ({ row: { original: b } }) => FormatRupiah(b.Pajak),
    },
    {
        id: 'TotalHpp',
        header: 'HPP kembali',
        enableSorting: false,
        meta: { label: 'HPP kembali', angka: true, prioritas: 'rendah' },
        cell: ({ row: { original: b } }) => FormatRupiah(b.TotalHpp),
    },
    {
        id: 'NilaiBaris',
        header: 'Nilai retur',
        enableSorting: false,
        meta: { label: 'Nilai retur', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => <span className="font-semibold">{FormatRupiah(b.NilaiBaris)}</span>,
    },
];

const kolomRefund: KolomTabel<BarisRefundRetur>[] = [
    {
        id: 'NamaMetode',
        accessorKey: 'NamaMetode',
        header: 'Metode',
        meta: { label: 'Metode', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block text-teks-utama">{b.NamaMetode}</span>
                <span className="block text-label text-teks-sekunder">{b.LabelJenis}</span>
            </>
        ),
    },
    {
        id: 'Jumlah',
        header: 'Jumlah',
        enableSorting: false,
        meta: { label: 'Jumlah', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => FormatRupiah(b.Jumlah),
    },
];

const kolomMutasi: KolomTabel<BarisMutasiPenjualan>[] = [
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: m } }) =>
            m.TautanKartuStok ? (
                <Link href={m.TautanKartuStok} className="text-brand underline">
                    {m.NamaProduk}
                </Link>
            ) : (
                m.NamaProduk
            ),
    },
    {
        id: 'NamaGudang',
        accessorKey: 'NamaGudang',
        header: 'Lokasi stok',
        meta: { label: 'Lokasi stok', prioritas: 'rendah' },
    },
    {
        id: 'Jumlah',
        header: 'Jumlah',
        enableSorting: false,
        meta: { label: 'Jumlah', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: m } }) => FormatJumlahStok(m.Jumlah, m.SimbolSatuan),
    },
    {
        id: 'TotalHpp',
        header: 'Nilai persediaan',
        enableSorting: false,
        meta: { label: 'Nilai persediaan', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: m } }) => FormatNilai(m.TotalHpp),
    },
];

function Nilai({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-label text-teks-sekunder">{label}</dt>
            <dd className="text-isi break-words text-teks-utama">{children}</dd>
        </div>
    );
}

function BarisAngka({ label, nilai, tebal = false }: { label: string; nilai: string; tebal?: boolean }) {
    return (
        <div className={`flex items-baseline justify-between gap-4 ${tebal ? 'font-semibold' : ''}`}>
            <dt className="text-isi text-teks-sekunder">{label}</dt>
            <dd className="text-right text-isi text-teks-utama tabular-nums">{nilai}</dd>
        </div>
    );
}

/** F-09 fase 1: detail retur penjualan (baca saja): ringkasan, baris, refund, mutasi stok, shift, dan jurnal. */
export default function HalamanDetailRetur({ Retur: r, Baris, Refund, MutasiStok, Jurnal }: PropsDetailRetur) {
    return (
        <TataLetakAplikasi
            judul={`Retur ${r.Nomor}`}
            jejak={[{ label: 'Void & retur', href: '/kelola/penjualan/void-retur' }]}
        >
            {r.PerluTinjauan ? (
                <Pemberitahuan jenis="peringatan" judul="Retur ini perlu ditinjau">
                    <DaftarAlasanTinjauan
                        alasan={r.DaftarAlasanTinjauan}
                        cadangan="Retur ini diterima meski ada data yang tidak sesuai saat sinkron."
                    />
                </Pemberitahuan>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
                <Card className="gap-4 rounded-panel p-4 shadow-none">
                    <LabelStatus jenis="netral" teks={r.LabelStatus} />
                    <dl className="grid gap-4 sm:grid-cols-2">
                        <Nilai label="Nomor retur">
                            <span className="font-mono break-all">{r.Nomor}</span>
                        </Nilai>
                        <Nilai label="Penjualan asal">
                            {r.UuidPenjualan !== null && r.NomorPenjualan !== null ? (
                                <Link
                                    href={`/kelola/penjualan/${r.UuidPenjualan}`}
                                    className="font-mono break-all text-brand underline"
                                >
                                    {r.NomorPenjualan}
                                </Link>
                            ) : (
                                'Tanpa struk (disetujui dengan PIN)'
                            )}
                        </Nilai>
                        <Nilai label="Waktu retur">{FormatTanggalWaktu(r.DibuatOfflinePada)}</Nilai>
                        {r.WaktuPenjualan !== null ? (
                            <Nilai label="Waktu penjualan">{FormatTanggalWaktu(r.WaktuPenjualan)}</Nilai>
                        ) : null}
                        <Nilai label="Outlet">{r.NamaOutlet}</Nilai>
                        <Nilai label="Hari bisnis">{FormatTanggal(r.TanggalBisnis)}</Nilai>
                        <Nilai label="Kasir">{r.NamaKasir}</Nilai>
                        <Nilai label="Disetujui">{r.NamaPenyetuju}</Nilai>
                        <Nilai label="Perangkat">
                            <span className="font-mono">{r.Perangkat}</span>
                        </Nilai>
                        <Nilai label="Alasan">{r.Alasan}</Nilai>
                        <Nilai label="Shift">
                            {r.UuidShift ? (
                                <Link href={`/kelola/kasir/shift/${r.UuidShift}`} className="text-brand underline">
                                    Lihat shift
                                </Link>
                            ) : (
                                '—'
                            )}
                        </Nilai>
                        <Nilai label="Jurnal">
                            {Jurnal.length > 0 ? (
                                <span className="flex flex-wrap gap-2">
                                    {Jurnal.map((j) => (
                                        <Link
                                            key={j.Uuid}
                                            href={`/kelola/akuntansi/jurnal/${j.Uuid}`}
                                            className="font-mono text-brand underline"
                                        >
                                            {j.Nomor}
                                        </Link>
                                    ))}
                                </span>
                            ) : (
                                'Tanpa jurnal (nilai Rp 0)'
                            )}
                        </Nilai>
                    </dl>
                </Card>

                <Card className="gap-2 rounded-panel p-4 shadow-none">
                    <h2 className="text-subjudul font-semibold text-teks-utama">Ringkasan</h2>
                    <dl className="flex flex-col gap-1">
                        <BarisAngka label="Nilai barang diretur" nilai={FormatRupiah(r.TotalNilai)} />
                        <BarisAngka label="Termasuk pajak" nilai={FormatRupiah(r.TotalPajak)} />
                        {!nol.test(r.TotalBiayaLayanan) ? (
                            <BarisAngka label="Termasuk biaya layanan" nilai={FormatRupiah(r.TotalBiayaLayanan)} />
                        ) : null}
                        <BarisAngka
                            label={`Refund (${r.LabelMetodeRefund})`}
                            nilai={FormatRupiah(r.TotalRefund)}
                            tebal
                        />
                        <BarisAngka label="Tunai dari laci" nilai={FormatRupiah(r.RefundTunai)} />
                        <BarisAngka label="HPP kembali ke stok" nilai={FormatRupiah(r.TotalHpp)} />
                    </dl>
                </Card>
            </div>

            <h2 className="text-subjudul font-semibold text-teks-utama">Barang diretur</h2>
            <TabelData
                id="retur-baris"
                label="Baris retur"
                kolom={kolomBaris}
                sumber={{ mode: 'lokal', data: Baris }}
                ambilIdBaris={(b) => b.Uuid}
                cari={false}
                kosong={{ ilustrasi: true, judul: 'Retur ini tidak punya baris.' }}
            />

            <h2 className="text-subjudul font-semibold text-teks-utama">Refund</h2>
            <TabelData
                id="retur-refund"
                label="Refund retur"
                kolom={kolomRefund}
                sumber={{ mode: 'lokal', data: Refund }}
                ambilIdBaris={(b) => b.Uuid}
                cari={false}
                kosong={{ ilustrasi: true, judul: 'Tanpa refund (nilai Rp 0).' }}
            />

            <h2 className="text-subjudul font-semibold text-teks-utama">Mutasi stok</h2>
            <TabelData
                id="retur-mutasi"
                label="Mutasi stok retur"
                kolom={kolomMutasi}
                sumber={{ mode: 'lokal', data: MutasiStok }}
                ambilIdBaris={(m) => m.Kunci}
                cari={false}
                kosong={{ ilustrasi: true, judul: 'Retur ini tidak mengembalikan stok (jasa atau produk tanpa stok).' }}
            />
        </TataLetakAplikasi>
    );
}
