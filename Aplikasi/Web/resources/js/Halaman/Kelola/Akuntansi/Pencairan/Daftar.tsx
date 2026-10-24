import { Link } from '@inertiajs/react';

import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import Panel from '@/Komponen/Kelola/Panel';
import { TautanEkspor } from '@/Komponen/Laporan/NavigasiTab';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatPersen, FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { BandingkanDesimal } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisPencairan, PropsDaftarPencairan, RekapPotonganPencairan } from '@/Tipe/Akuntansi';

const alamat = '/kelola/akuntansi/pencairan';

const kolom: KolomTabel<BarisPencairan>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: d } }) => (
            <Link href={`${alamat}/${d.Uuid}`} className="font-mono font-semibold break-all text-brand underline">
                {d.Nomor}
            </Link>
        ),
    },
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Masuk rekening',
        meta: { label: 'Tanggal masuk rekening', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
    },
    {
        id: 'Metode',
        header: 'Metode',
        enableSorting: false,
        meta: { label: 'Metode pembayaran', prioritas: 'penting' },
        cell: ({ row }) => <span className="break-words">{row.original.NamaMetode}</span>,
    },
    {
        id: 'JumlahKotor',
        accessorKey: 'JumlahKotor',
        header: 'Nilai transaksi',
        meta: { label: 'Nilai transaksi', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.JumlahKotor),
    },
    {
        id: 'JumlahBersih',
        accessorKey: 'JumlahBersih',
        header: 'Masuk rekening',
        meta: { label: 'Jumlah masuk rekening', angka: true, prioritas: 'utama' },
        cell: ({ row }) => FormatRupiah(row.original.JumlahBersih),
    },
    {
        id: 'Biaya',
        accessorKey: 'Biaya',
        header: 'Potongan',
        meta: { label: 'Potongan platform', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: d } }) => (
            <span className="flex flex-col items-end">
                <span>{FormatRupiah(d.Biaya)}</span>
                {/* Selisih dari pengaturan metode: inilah yang perlu ditanyakan ke platform. */}
                {BandingkanDesimal(d.SelisihBiaya, '0') === 0 ? null : (
                    <span className="text-keterangan text-teks-sekunder">
                        {BandingkanDesimal(d.SelisihBiaya, '0') > 0 ? 'lebih ' : 'kurang '}
                        {FormatRupiah(d.SelisihBiaya.replace('-', ''))} dari perkiraan
                    </span>
                )}
            </span>
        ),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: d } }) => (
            <LabelStatus jenis={d.Status === 'Diposting' ? 'sukses' : 'bahaya'} teks={d.LabelStatus} />
        ),
    },
    {
        id: 'Outlet',
        header: 'Outlet',
        enableSorting: false,
        meta: { label: 'Outlet', prioritas: 'rendah', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => <span className="font-mono">{row.original.KodeOutlet}</span>,
    },
    {
        id: 'Referensi',
        header: 'Referensi',
        enableSorting: false,
        meta: { label: 'Referensi setoran', prioritas: 'rendah' },
        cell: ({ row }) => <span className="break-words">{row.original.Referensi ?? '—'}</span>,
    },
];

/**
 * Rekap potongan per metode untuk saringan yang sedang aktif. Inilah angka yang dibawa pemilik ke platform: berapa yang
 * diserahkan, berapa yang dipotong, berapa yang seharusnya menurut kesepakatan, dan persen efektif yang sebenarnya
 * terjadi. Ikut berubah saat rentang tanggalnya diganti, karena datang dari `Ringkasan` muatan tabel.
 */
function RekapPotongan({ rekap }: { rekap: RekapPotonganPencairan[] }) {
    if (rekap.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2 rounded-panel border border-garis p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-label font-semibold text-teks-sekunder">Potongan platform pada filter ini</h3>
                <TautanEkspor
                    alamat={`${alamat}/rekap-potongan/ekspor`}
                    query={Object.fromEntries(
                        new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search),
                    )}
                />
            </div>
            <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {rekap.map((r) => {
                    const menyimpang = BandingkanDesimal(r.Selisih, '0') !== 0;

                    return (
                        <li key={r.Nama} className="flex flex-col gap-0.5">
                            <span className="font-semibold break-words text-teks-utama">{r.Nama}</span>
                            <span className="text-keterangan text-teks-sekunder">
                                {r.Jumlah} pencairan | diserahkan {FormatRupiah(r.JumlahKotor)}
                            </span>
                            <span className="text-isi tabular-nums text-teks-utama">
                                dipotong {FormatRupiah(r.Biaya)} ({FormatPersen(r.PersenEfektif)})
                            </span>
                            {menyimpang ? (
                                <span className="text-keterangan text-teks-sekunder">
                                    perkiraan {FormatRupiah(r.BiayaDiharapkan)} | selisih{' '}
                                    {FormatRupiah(r.Selisih.replace('-', ''))}{' '}
                                    {BandingkanDesimal(r.Selisih, '0') > 0 ? 'lebih' : 'kurang'}
                                </span>
                            ) : (
                                <span className="text-keterangan text-teks-sekunder">sesuai perkiraan</span>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

/**
 * Daftar pencairan dana non-tunai (F-08, BR-08.4, J-08.1).
 *
 * Bagian **"Belum dicairkan"** di atas tabel adalah inti halaman ini: itu isi akun kliring yang masih menunggu uang
 * masuk rekening. Angka yang menua di sana berarti platform belum menyetor — sebelum ada halaman ini, tidak ada tempat
 * di produk yang bisa menunjukkannya.
 */
export default function HalamanDaftarPencairan({
    Pencairan,
    BelumDicairkan,
    OpsiMetode,
    OpsiStatus,
    Izin,
}: PropsDaftarPencairan) {
    return (
        <TataLetakAplikasi judul="Pencairan dana">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Setoran QRIS, kartu, gerbang, dan ojol yang masuk ke rekening toko. Mencatatnya melunasi akun kliring
                dan membebankan potongan platform, sehingga laba yang terbaca sudah bersih dari MDR dan komisi.
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="akuntansi.kelola" objek="pencairan" /> : null}

            <Panel judul="Belum dicairkan">
                {BelumDicairkan.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">
                        Tidak ada pembayaran non-tunai yang menunggu pencairan. Akun kliring sudah bersih.
                    </p>
                ) : (
                    <ul className="flex flex-col gap-2">
                        {BelumDicairkan.map((b) => (
                            <li
                                key={b.Uuid}
                                className="flex flex-wrap items-baseline justify-between gap-2 rounded-panel border border-garis p-3"
                            >
                                <span className="flex min-w-0 flex-col">
                                    <span className="font-semibold break-words text-teks-utama">{b.Nama}</span>
                                    <span className="text-keterangan text-teks-sekunder">
                                        {b.Jumlah} pembayaran menunggu
                                    </span>
                                </span>
                                <span className="flex items-baseline gap-3">
                                    <span className="text-subjudul font-semibold tabular-nums">
                                        {FormatRupiah(b.Total)}
                                    </span>
                                    {Izin.Kelola ? (
                                        <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                                            <Link href={`${alamat}/buat?metode=${b.Uuid}`}>Cairkan</Link>
                                        </Button>
                                    ) : null}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            {OpsiMetode.length === 0 ? (
                <Pemberitahuan jenis="info" judul="Belum ada metode non-tunai">
                    Pencairan hanya berlaku untuk metode yang uangnya tidak langsung diterima kasir — QRIS, kartu/EDC,
                    e-wallet, transfer, dan platform ojol. Tambahkan metodenya dulu di Pengaturan.
                </Pemberitahuan>
            ) : null}

            <TabelData
                id="akuntansi-pencairan"
                label="Daftar pencairan dana"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Pencairan }}
                ambilIdBaris={(d) => d.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor pencairan atau referensi"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
                    },
                    {
                        id: 'Metode',
                        label: 'Metode',
                        jenis: 'pilihan',
                        opsi: OpsiMetode.map((m) => ({ nilai: m.Uuid, label: m.Nama })),
                    },
                    { id: 'Tanggal', label: 'Tanggal', jenis: 'rentangTanggal' },
                ]}
                ringkasan={(hasil) => <RekapPotongan rekap={(hasil?.Ringkasan as RekapPotonganPencairan[]) ?? []} />}
                alamatDetail={(d) => `${alamat}/${d.Uuid}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada pencairan yang dicatat.' }}
            />
        </TataLetakAplikasi>
    );
}
