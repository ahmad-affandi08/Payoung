import { Link, router } from '@inertiajs/react';

import PilihanCari from '@/Komponen/Formulir/PilihanCari';
import { KolomQty } from '@/Komponen/Laporan/KolomLaporan';
import NavigasiTab, { TautanEkspor } from '@/Komponen/Laporan/NavigasiTab';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { Label } from '@/Komponen/Ui/label';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisPenjualanObatResep, BarisSipnap, PropsLaporanApotek } from '@/Tipe/Laporan';

const alamat = '/kelola/laporan/apotek';

const namaBulan = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

/** 24 bulan terakhir sampai `bulanTerakhir` (`TTTT-BB`), terbaru di atas: { Nilai: '2026-09', Label: 'September 2026' }. */
export function SusunOpsiBulan(bulanTerakhir: string, jumlah = 24): { Nilai: string; Label: string }[] {
    const [tahunTeks = '2000', bulanTeks = '1'] = bulanTerakhir.split('-');
    let tahun = Number.parseInt(tahunTeks, 10);
    let bulan = Number.parseInt(bulanTeks, 10);
    const hasil: { Nilai: string; Label: string }[] = [];

    for (let i = 0; i < jumlah; i++) {
        hasil.push({
            Nilai: `${String(tahun)}-${String(bulan).padStart(2, '0')}`,
            Label: `${namaBulan[bulan - 1] ?? ''} ${String(tahun)}`,
        });
        bulan -= 1;
        if (bulan === 0) {
            bulan = 12;
            tahun -= 1;
        }
    }

    return hasil;
}

const kolomResep: KolomTabel<BarisPenjualanObatResep>[] = [
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => (row.original.Tanggal ? FormatTanggal(row.original.Tanggal) : '—'),
    },
    {
        id: 'Produk',
        header: 'Obat',
        enableSorting: false,
        meta: { label: 'Obat', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col gap-1">
                <span className="break-words text-teks-utama">{b.NamaProduk}</span>
                <span className="text-label text-teks-sekunder">
                    {b.LabelGolongan}
                    {b.ObatWajibApotek ? ' | Obat Wajib Apotek' : ''}
                </span>
                {b.Nomor !== null && b.UuidPenjualan !== null ? (
                    <Link
                        href={`/kelola/penjualan/${b.UuidPenjualan}`}
                        className="font-mono text-label break-all text-brand underline"
                    >
                        {b.Nomor}
                    </Link>
                ) : null}
            </span>
        ),
    },
    {
        id: 'Jumlah',
        header: 'Jumlah',
        enableSorting: false,
        meta: { label: 'Jumlah', angka: true, prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => `${FormatJumlahStok(row.original.Jumlah)} ${row.original.SimbolSatuan}`,
    },
    {
        id: 'Batch',
        header: 'Batch',
        enableSorting: false,
        meta: { label: 'Batch', prioritas: 'rendah' },
        cell: ({ row }) =>
            row.original.Batch === '' ? (
                <span className="text-teks-sekunder">—</span>
            ) : (
                <span className="font-mono break-all">{row.original.Batch}</span>
            ),
    },
    {
        id: 'Resep',
        header: 'Resep',
        enableSorting: false,
        meta: { label: 'Resep', prioritas: 'penting' },
        cell: ({ row: { original: b } }) =>
            b.NomorResep === null ? (
                <LabelStatus
                    jenis={b.ObatWajibApotek ? 'netral' : 'peringatan'}
                    teks={b.ObatWajibApotek ? 'Tanpa resep (OWA)' : 'Tanpa resep'}
                />
            ) : (
                <span className="flex flex-col">
                    <span className="font-mono break-all">{b.NomorResep}</span>
                    <span className="text-label text-teks-sekunder break-words">
                        {b.NamaDokter}
                        {b.TanggalResep ? ` | ${FormatTanggal(b.TanggalResep)}` : ''}
                    </span>
                    {!b.DenganResep ? (
                        <span className="text-label text-teks-sekunder">Baris ini tidak ditandai resep</span>
                    ) : null}
                </span>
            ),
    },
    {
        id: 'Pasien',
        header: 'Pasien',
        enableSorting: false,
        meta: { label: 'Pasien', prioritas: 'rendah' },
        cell: ({ row: { original: b } }) =>
            b.NamaPasien === null ? (
                <span className="text-teks-sekunder">—</span>
            ) : (
                <span className="flex flex-col">
                    <span className="break-words">
                        {b.NamaPasien}
                        {b.UmurPasien ? ` (${b.UmurPasien})` : ''}
                    </span>
                    {b.AlamatPasien ? (
                        <span className="text-label text-teks-sekunder break-words">{b.AlamatPasien}</span>
                    ) : null}
                </span>
            ),
    },
    {
        id: 'Apoteker',
        header: 'Apoteker',
        enableSorting: false,
        meta: { label: 'Apoteker', prioritas: 'rendah' },
        cell: ({ row: { original: b } }) =>
            b.NamaApoteker === null ? (
                <LabelStatus jenis="peringatan" teks="Tanpa apoteker" />
            ) : (
                <span className="break-words">{b.NamaApoteker}</span>
            ),
    },
];

const kolomSipnap: KolomTabel<BarisSipnap>[] = [
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Obat',
        meta: { label: 'Obat', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col">
                <span className="break-words text-teks-utama">{b.NamaProduk}</span>
                <span className="text-label text-teks-sekunder">
                    {b.LabelGolongan}
                    {b.Prekursor ? ' | prekursor' : ''} | {b.SimbolSatuan}
                </span>
            </span>
        ),
    },
    KolomQty<BarisSipnap>('StokAwal', 'Stok awal'),
    KolomQty<BarisSipnap>('PemasukanPemasok', 'Masuk dari pemasok'),
    KolomQty<BarisSipnap>('PemasukanLain', 'Masuk lain'),
    KolomQty<BarisSipnap>('PengeluaranPenjualan', 'Keluar penjualan'),
    KolomQty<BarisSipnap>('PengeluaranLain', 'Keluar lain'),
    KolomQty<BarisSipnap>('StokAkhir', 'Stok akhir'),
];

/**
 * Laporan apotek (Sektor Apotek bagian 1, PRD §9.5): penjualan obat keras, psikotropika & narkotika beserta resep,
 * batch, dan apoteker; serta data pendukung SIPNAP per bulan dari buku stok. Data pasien utuh hanya untuk pemegang
 * izin `apotek.resep.lihat`. Obat mendekati kedaluwarsa ada di Laporan stok › Kedaluwarsa.
 */
export default function HalamanLaporanApotek({
    Tab,
    Penjualan,
    OpsiGolongan,
    LihatPasien,
    Sipnap,
    OpsiOutlet,
}: PropsLaporanApotek) {
    const querySipnap = { bulan: Sipnap.Bulan, outlet: Sipnap.Outlet };
    const saring: DefinisiSaring[] = [
        {
            id: 'Golongan',
            label: 'Golongan',
            jenis: 'pilihanBanyak',
            opsi: OpsiGolongan.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        { id: 'Tanggal', label: 'Tanggal', jenis: 'rentangTanggal' },
        {
            id: 'Resep',
            label: 'Resep',
            jenis: 'pilihan',
            opsi: [
                { nilai: '1', label: 'Dengan resep' },
                { nilai: '0', label: 'Tanpa resep' },
            ],
        },
    ];
    const TerapkanSipnap = (ubah: Record<string, string>) => {
        const baru = Object.fromEntries(
            Object.entries({ ...querySipnap, tab: 'sipnap', ...ubah }).filter(([, nilai]) => nilai !== ''),
        );
        router.get(alamat, baru, { preserveScroll: true, preserveState: true });
    };

    return (
        <TataLetakAplikasi judul="Laporan apotek">
            <NavigasiTab
                label="Jenis laporan apotek"
                alamat={alamat}
                query={{}}
                tabAktif={Tab}
                tab={[
                    { nilai: 'resep', label: 'Obat wajib resep' },
                    { nilai: 'sipnap', label: 'Data pendukung SIPNAP' },
                ]}
            />

            {Tab === 'resep' ? (
                <section aria-labelledby="judul-obat-resep" className="flex flex-col gap-2">
                    <h2 id="judul-obat-resep" className="text-subjudul font-semibold text-teks-utama">
                        Penjualan obat keras, psikotropika & narkotika
                    </h2>
                    <p className="max-w-3xl text-label text-teks-sekunder">
                        Setiap penyerahan obat keras (termasuk Obat Wajib Apotek tanpa resep), psikotropika, dan
                        narkotika beserta resep, batch, dan apoteker yang menyerahkannya. Obat yang mendekati
                        kedaluwarsa ada di{' '}
                        <Link href="/kelola/laporan/stok?tab=kedaluwarsa" className="text-brand underline">
                            Laporan stok › Kedaluwarsa
                        </Link>
                        .
                        {LihatPasien
                            ? ''
                            : ' Nama pasien disamarkan dan alamat disembunyikan; butuh izin melihat resep (apotek.resep.lihat).'}
                    </p>
                    <TabelData
                        id="laporan-apotek-resep"
                        label="Penjualan obat wajib resep"
                        kolom={kolomResep}
                        sumber={{ mode: 'server', alamat, awal: Penjualan }}
                        ambilIdBaris={(b) => b.Uuid}
                        urutBawaan="-Tanggal"
                        cari="Cari nomor penjualan, nomor resep, atau dokter"
                        saring={saring}
                        ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true }}
                        kosong={{
                            ilustrasi: true,
                            judul: 'Belum ada penjualan obat keras, psikotropika, atau narkotika.',
                        }}
                    />
                </section>
            ) : (
                <section aria-labelledby="judul-sipnap" className="flex flex-col gap-3">
                    <h2 id="judul-sipnap" className="text-subjudul font-semibold text-teks-utama">
                        Data pendukung SIPNAP
                    </h2>
                    <p className="max-w-3xl text-label text-teks-sekunder">
                        Rekap bulanan psikotropika dan narkotika dari buku stok, dalam satuan dasar. Ini data pendukung,
                        bukan laporan resmi: periksa lalu isikan sendiri ke aplikasi SIPNAP. Stok akhir = stok awal +
                        masuk dari pemasok + masuk lain − keluar penjualan − keluar lain (transfer, penyesuaian, opname,
                        pemusnahan, retur ke pemasok).
                    </p>
                    <div
                        className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                        role="group"
                        aria-label="Filter data SIPNAP"
                    >
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="saring-sipnap-bulan" className="text-label font-semibold text-teks-utama">
                                Bulan
                            </Label>
                            <PilihanCari
                                id="saring-sipnap-bulan"
                                label="Bulan"
                                nilai={Sipnap.Bulan}
                                opsi={SusunOpsiBulan(Sipnap.Bulan)}
                                saatBerubah={(bulan) => TerapkanSipnap({ bulan })}
                            />
                        </div>
                        {OpsiOutlet.length > 1 ? (
                            <div className="flex flex-col gap-1">
                                <Label
                                    htmlFor="saring-sipnap-outlet"
                                    className="text-label font-semibold text-teks-utama"
                                >
                                    Outlet
                                </Label>
                                <PilihanCari
                                    id="saring-sipnap-outlet"
                                    label="Outlet"
                                    nilai={Sipnap.Outlet}
                                    opsi={OpsiOutlet}
                                    kosong="Semua outlet"
                                    saatBerubah={(outlet) => TerapkanSipnap({ outlet })}
                                />
                            </div>
                        ) : null}
                        <div className="flex items-end">
                            <TautanEkspor alamat={`${alamat}/sipnap/ekspor`} query={querySipnap} />
                        </div>
                    </div>
                    <TabelData
                        id="laporan-apotek-sipnap"
                        label="Data pendukung SIPNAP"
                        kolom={kolomSipnap}
                        sumber={{ mode: 'lokal', data: Sipnap.Baris }}
                        ambilIdBaris={(b) => b.UuidProduk}
                        cari="Cari obat"
                        kosong={{
                            ilustrasi: true,
                            judul: 'Tidak ada stok atau mutasi psikotropika & narkotika pada bulan ini.',
                        }}
                    />
                </section>
            )}
        </TataLetakAplikasi>
    );
}
