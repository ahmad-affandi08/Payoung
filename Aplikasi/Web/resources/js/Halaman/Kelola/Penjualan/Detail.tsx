import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import DaftarAlasanTinjauan from '@/Komponen/Penjualan/DaftarAlasanTinjauan';
import LencanaPenjualan from '@/Komponen/Penjualan/LencanaPenjualan';
import PanelFakturPajak from '@/Komponen/Penjualan/PanelFakturPajak';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { Card } from '@/Komponen/Ui/card';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatPersen, FormatRupiah } from '@/Pustaka/Format';
import { FormatHppSatuan, FormatJumlahStok, FormatNilai } from '@/Pustaka/FormatPersediaan';
import { FormatDurasi, FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type {
    BarisDetailPenjualan,
    BarisMutasiPenjualan,
    BarisPajakPenjualan,
    BarisPembayaranPenjualan,
    PropsDetailPenjualan,
    ReturRingkasPenjualan,
} from '@/Tipe/Penjualan';

const nol = /^-?0+(\.0+)?$/;

const kolomBaris: KolomTabel<BarisDetailPenjualan>[] = [
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block text-teks-utama">{b.NamaProduk}</span>
                {b.Pilihan.length > 0 ? (
                    <span className="block text-label text-teks-sekunder">
                        {b.Pilihan.join(', ')} (+{FormatRupiah(b.HargaPilihan)})
                    </span>
                ) : null}
                {b.Catatan ? (
                    <span className="block text-label break-words text-teks-sekunder">{b.Catatan}</span>
                ) : null}
                {b.HargaAsli !== null ? (
                    <span className="block text-label break-words text-peringatan">
                        Harga diubah kasir dari {FormatRupiah(b.HargaAsli)}
                        {b.AlasanHargaManual ? ` | ${b.AlasanHargaManual}` : ''}
                    </span>
                ) : null}
                {b.Racikan ? (
                    <span className="block text-label break-words text-teks-sekunder">
                        Racikan {b.Racikan.Nama} | {b.Racikan.JumlahKemasan} kemasan
                        {b.Racikan.AturanPakai ? ` | ${b.Racikan.AturanPakai}` : ''}:{' '}
                        {b.Racikan.Komponen.map((k) => `${k.NamaProduk} ${FormatJumlahStok(k.Jumlah)}`).join(', ')}
                    </span>
                ) : null}
            </>
        ),
    },
    {
        id: 'Jumlah',
        header: 'Jumlah × harga',
        enableSorting: false,
        meta: { label: 'Jumlah × harga', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block whitespace-nowrap">
                    {FormatJumlahStok(b.Jumlah, b.SimbolSatuan)} × {FormatRupiah(b.HargaSatuan)}
                </span>
                {nol.test(b.JumlahDiretur) ? null : (
                    <span className="block text-label text-teks-sekunder">
                        Diretur {FormatJumlahStok(b.JumlahDiretur, b.SimbolSatuan)}
                    </span>
                )}
            </>
        ),
    },
    {
        id: 'Diskon',
        header: 'Diskon',
        enableSorting: false,
        meta: { label: 'Diskon (baris + pesanan)', angka: true, prioritas: 'rendah' },
        cell: ({ row: { original: b } }) =>
            nol.test(b.JumlahDiskon) && nol.test(b.JumlahDiskonPesanan)
                ? '—'
                : `−${FormatRupiah(b.JumlahDiskon)} / −${FormatRupiah(b.JumlahDiskonPesanan)}`,
    },
    {
        id: 'JumlahPajak',
        header: 'Pajak',
        enableSorting: false,
        meta: { label: 'Pajak', angka: true, prioritas: 'rendah' },
        cell: ({ row: { original: b } }) => FormatRupiah(b.JumlahPajak),
    },
    {
        id: 'TotalHpp',
        header: 'HPP',
        enableSorting: false,
        meta: { label: 'HPP', angka: true, prioritas: 'rendah' },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block">{FormatRupiah(b.TotalHpp)}</span>
                <span className="block text-label text-teks-sekunder">{FormatHppSatuan(b.HppSatuan)}/satuan</span>
            </>
        ),
    },
    {
        id: 'TotalBaris',
        header: 'Total',
        enableSorting: false,
        meta: { label: 'Total baris', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => <span className="font-semibold">{FormatRupiah(b.TotalBaris)}</span>,
    },
];

const kolomPembayaran: KolomTabel<BarisPembayaranPenjualan>[] = [
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
        id: 'Referensi',
        header: 'Referensi',
        enableSorting: false,
        meta: { label: 'Referensi', prioritas: 'rendah' },
        cell: ({ row: { original: b } }) => <span className="font-mono">{b.Referensi ?? '—'}</span>,
    },
    {
        id: 'Jumlah',
        header: 'Jumlah',
        enableSorting: false,
        meta: { label: 'Jumlah', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => FormatRupiah(b.Jumlah),
    },
];

const kolomRetur: KolomTabel<ReturRingkasPenjualan>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor retur',
        meta: { label: 'Nomor retur', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: r } }) => (
            <Link href={`/kelola/penjualan/retur/${r.Uuid}`} className="font-mono break-all text-brand underline">
                {r.Nomor}
            </Link>
        ),
    },
    {
        id: 'DibuatOfflinePada',
        header: 'Waktu',
        enableSorting: false,
        meta: { label: 'Waktu', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row: { original: r } }) => FormatTanggalWaktu(r.DibuatOfflinePada),
    },
    {
        id: 'Alasan',
        header: 'Kasir & alasan',
        enableSorting: false,
        meta: { label: 'Kasir & alasan', prioritas: 'rendah' },
        cell: ({ row: { original: r } }) => (
            <>
                <span className="block text-teks-utama">{r.NamaKasir}</span>
                <span className="block text-label break-words text-teks-sekunder">{r.Alasan}</span>
            </>
        ),
    },
    {
        id: 'TotalRefund',
        header: 'Refund',
        enableSorting: false,
        meta: { label: 'Refund', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: r } }) => (
            <>
                <span className="block font-semibold">{FormatRupiah(r.TotalRefund)}</span>
                <span className="block text-label text-teks-sekunder">{r.LabelMetodeRefund}</span>
            </>
        ),
    },
];

const kolomPajak: KolomTabel<BarisPajakPenjualan>[] = [
    {
        id: 'KodeJenisPajak',
        accessorKey: 'KodeJenisPajak',
        header: 'Pajak',
        meta: { label: 'Pajak', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: p } }) => (
            <>
                <span className="block text-teks-utama">
                    {p.KodeJenisPajak} {FormatPersen(p.Tarif)}%
                </span>
                <span className="block text-label text-teks-sekunder">Dasar: {p.DasarPengenaan}</span>
            </>
        ),
    },
    {
        id: 'Dpp',
        header: 'DPP',
        enableSorting: false,
        meta: { label: 'DPP', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: p } }) => FormatRupiah(p.Dpp),
    },
    {
        id: 'Jumlah',
        header: 'Jumlah pajak',
        enableSorting: false,
        meta: { label: 'Jumlah pajak', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: p } }) => FormatRupiah(p.Jumlah),
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

/**
 * F-07b: detail penjualan (baca saja): ringkasan, baris, pembayaran, pajak, mutasi stok, shift, dan jurnal. F-09: void
 * (alasan, penyetuju, refund, jeda sejak bayar) dan daftar retur.
 */
export default function HalamanDetailPenjualan({
    Penjualan: p,
    Baris,
    Pajak,
    Pembayaran,
    MutasiStok,
    Jurnal,
    Void,
    Retur,
    FakturPajak,
    Izin,
}: PropsDetailPenjualan) {
    return (
        <TataLetakAplikasi judul={`Penjualan ${p.Nomor}`}>
            <Button asChild variant="link" className="h-auto self-start px-0">
                <Link href="/kelola/penjualan">Kembali ke daftar penjualan</Link>
            </Button>

            {p.PerluTinjauan ? (
                <Pemberitahuan jenis="peringatan" judul="Penjualan ini perlu ditinjau">
                    <DaftarAlasanTinjauan
                        alasan={p.DaftarAlasanTinjauan}
                        cadangan="Penjualan ini diterima meski ada data yang tidak sesuai saat sinkron."
                    />
                </Pemberitahuan>
            ) : null}

            {Void ? (
                <Pemberitahuan jenis="bahaya" judul="Penjualan ini dibatalkan (void)">
                    <dl className="grid gap-2 sm:grid-cols-2">
                        <Nilai label="Waktu void">
                            {FormatTanggalWaktu(Void.DivoidPada)} ({FormatDurasi(Void.JedaDetik)} setelah bayar)
                        </Nilai>
                        <Nilai label="Alasan">{Void.Alasan}</Nilai>
                        <Nilai label="Kasir">{Void.NamaKasir}</Nilai>
                        <Nilai label="Disetujui">{Void.NamaPenyetuju}</Nilai>
                        <Nilai label="Tunai dikembalikan dari laci">{FormatRupiah(Void.RefundTunai)}</Nilai>
                        <Nilai label="Refund non-tunai (manual)">{FormatRupiah(Void.RefundNonTunai)}</Nilai>
                    </dl>
                </Pemberitahuan>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
                <Card className="gap-4 rounded-panel p-4 shadow-none">
                    <LencanaPenjualan status={p.Status} label={p.LabelStatus} perluTinjauan={p.PerluTinjauan} />
                    <dl className="grid gap-4 sm:grid-cols-2">
                        <Nilai label="Nomor">
                            <span className="font-mono break-all">{p.Nomor}</span>
                        </Nilai>
                        <Nilai label="Waktu transaksi">{FormatTanggalWaktu(p.DibuatOfflinePada)}</Nilai>
                        <Nilai label="Outlet">{p.NamaOutlet}</Nilai>
                        <Nilai label="Hari bisnis">{FormatTanggal(p.TanggalBisnis)}</Nilai>
                        <Nilai label="Kasir">{p.NamaKasir}</Nilai>
                        <Nilai label="Perangkat">
                            <span className="font-mono">{p.Perangkat}</span>
                        </Nilai>
                        <Nilai label="Kanal">{p.LabelKanal}</Nilai>
                        <Nilai label="Pelanggan">
                            {p.Pelanggan ? (
                                <Link href={`/kelola/pelanggan/${p.Pelanggan.Uuid}`} className="text-brand underline">
                                    {p.Pelanggan.Nama}
                                </Link>
                            ) : (
                                'Umum (tanpa pelanggan)'
                            )}
                        </Nilai>
                        <Nilai label="Diterima server">{FormatTanggalWaktu(p.DiterimaPada)}</Nilai>
                        {p.NamaPenyetujuDiskon ? <Nilai label="Diskon disetujui">{p.NamaPenyetujuDiskon}</Nilai> : null}
                        <Nilai label="Shift">
                            {p.UuidShift ? (
                                <Link href={`/kelola/kasir/shift/${p.UuidShift}`} className="text-brand underline">
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
                        {p.NomorAntrian ? <Nilai label="Nomor antrian">{p.NomorAntrian}</Nilai> : null}
                        {p.NamaPemesan ? <Nilai label="Nama pemesan">{p.NamaPemesan}</Nilai> : null}
                        {p.Catatan ? <Nilai label="Catatan">{p.Catatan}</Nilai> : null}
                    </dl>
                </Card>

                <Card className="gap-2 rounded-panel p-4 shadow-none">
                    <h2 className="text-subjudul font-semibold text-teks-utama">Ringkasan</h2>
                    <dl className="flex flex-col gap-1">
                        <BarisAngka label="Subtotal" nilai={FormatRupiah(p.Subtotal)} />
                        {!nol.test(p.DiskonPesanan) ? (
                            <BarisAngka label="Diskon pesanan" nilai={`−${FormatRupiah(p.DiskonPesanan)}`} />
                        ) : null}
                        {p.Promo.map((promo) => (
                            <BarisAngka
                                key={promo.Uuid}
                                label={`Promo ${promo.Nama}`}
                                nilai={`−${FormatRupiah(promo.JumlahDiskon)}`}
                            />
                        ))}
                        {p.PoinDitukar > 0 ? (
                            <BarisAngka
                                label={`Termasuk tukar ${p.PoinDitukar.toLocaleString('id-ID')} poin`}
                                nilai={`−${FormatRupiah(p.DiskonPoin)}`}
                            />
                        ) : null}
                        {!nol.test(p.BiayaLayanan) ? (
                            <BarisAngka
                                label={`Biaya layanan ${FormatPersen(p.PersenBiayaLayanan)}%`}
                                nilai={FormatRupiah(p.BiayaLayanan)}
                            />
                        ) : null}
                        <BarisAngka
                            label={p.HargaTermasukPajak ? 'Pajak (termasuk harga)' : 'Pajak'}
                            nilai={FormatRupiah(p.TotalPajak)}
                        />
                        {!nol.test(p.Pembulatan) ? (
                            <BarisAngka label="Pembulatan tunai" nilai={FormatRupiah(p.Pembulatan)} />
                        ) : null}
                        <BarisAngka label="Total" nilai={FormatRupiah(p.TotalAkhir)} tebal />
                        <BarisAngka label="Dibayar" nilai={FormatRupiah(p.TotalDibayar)} />
                        <BarisAngka label="Kembalian" nilai={FormatRupiah(p.Kembalian)} />
                        {!nol.test(p.TotalDiskon) ? (
                            <BarisAngka label="Total diskon" nilai={FormatRupiah(p.TotalDiskon)} />
                        ) : null}
                        <BarisAngka label="HPP" nilai={FormatRupiah(p.TotalHpp)} />
                    </dl>
                </Card>
            </div>

            <h2 className="text-subjudul font-semibold text-teks-utama">Barang & jasa</h2>
            <TabelData
                id="penjualan-baris"
                label="Baris penjualan"
                kolom={kolomBaris}
                sumber={{ mode: 'lokal', data: Baris }}
                ambilIdBaris={(b) => b.Uuid}
                cari={false}
                kosong={{ judul: 'Penjualan ini tidak punya baris.' }}
            />

            {Retur.length > 0 ? (
                <>
                    <h2 className="text-subjudul font-semibold text-teks-utama">Retur</h2>
                    <TabelData
                        id="penjualan-retur"
                        label="Retur penjualan ini"
                        kolom={kolomRetur}
                        sumber={{ mode: 'lokal', data: Retur }}
                        ambilIdBaris={(r) => r.Uuid}
                        cari={false}
                        kosong={{ judul: 'Belum ada retur.' }}
                    />
                </>
            ) : null}

            <h2 className="text-subjudul font-semibold text-teks-utama">Pembayaran</h2>
            <TabelData
                id="penjualan-pembayaran"
                label="Pembayaran penjualan"
                kolom={kolomPembayaran}
                sumber={{ mode: 'lokal', data: Pembayaran }}
                ambilIdBaris={(b) => b.Uuid}
                cari={false}
                kosong={{ judul: 'Belum ada pembayaran.' }}
            />

            {Pajak.length > 0 ? (
                <>
                    <h2 className="text-subjudul font-semibold text-teks-utama">Rincian pajak</h2>
                    <TabelData
                        id="penjualan-pajak"
                        label="Rincian pajak penjualan"
                        kolom={kolomPajak}
                        sumber={{ mode: 'lokal', data: Pajak }}
                        ambilIdBaris={(b) => b.KodeJenisPajak}
                        cari={false}
                        kosong={{ judul: 'Tanpa pajak.' }}
                    />
                </>
            ) : null}

            {Pajak.length > 0 || FakturPajak !== null ? (
                <PanelFakturPajak
                    uuidPenjualan={p.Uuid}
                    faktur={FakturPajak}
                    bolehBuat={p.Status === 'Lunas' && Pajak.length > 0}
                    bolehKelola={Izin.KelolaFakturPajak}
                />
            ) : null}

            <h2 className="text-subjudul font-semibold text-teks-utama">Mutasi stok</h2>
            <TabelData
                id="penjualan-mutasi"
                label="Mutasi stok penjualan"
                kolom={kolomMutasi}
                sumber={{ mode: 'lokal', data: MutasiStok }}
                ambilIdBaris={(m) => m.Kunci}
                cari={false}
                kosong={{ judul: 'Penjualan ini tidak mengurangi stok (jasa atau produk tanpa stok).' }}
            />
        </TataLetakAplikasi>
    );
}
