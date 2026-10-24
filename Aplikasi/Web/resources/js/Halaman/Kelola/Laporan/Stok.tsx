import { Link, router, usePage } from '@inertiajs/react';

import PilihanCari from '@/Komponen/Formulir/PilihanCari';
import { KolomBilangan, KolomUang } from '@/Komponen/Laporan/KolomLaporan';
import NavigasiTab, { TautanEkspor } from '@/Komponen/Laporan/NavigasiTab';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import { Label } from '@/Komponen/Ui/label';
import { FormatRupiah } from '@/Pustaka/Format';
import { BuatUrlKartuStok, FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { PakaiSektor } from '@/Pustaka/Sektor';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import { IzinTenant, PunyaIzinTenant } from '@/Tipe/Organisasi';
import type {
    BarisBatchKedaluwarsa,
    BarisPerputaranStok,
    BarisRencanaProduksi,
    BarisSaranRestock,
    BarisStokKritis,
    NilaiPersediaan,
    PropsLaporanStok,
} from '@/Tipe/Laporan';

const alamat = '/kelola/laporan/stok';

type BarisGudang = NilaiPersediaan['PerGudang'][number];
type BarisKategori = NilaiPersediaan['PerKategori'][number];

const kolomGudang: KolomTabel<BarisGudang>[] = [
    {
        id: 'NamaGudang',
        accessorKey: 'NamaGudang',
        header: 'Lokasi stok',
        meta: { label: 'Lokasi stok', prioritas: 'utama', wajib: true },
        cell: ({ row }) => (
            <>
                <span className="block text-teks-utama">{row.original.NamaGudang}</span>
                <span className="block text-label text-teks-sekunder">{row.original.NamaOutlet}</span>
            </>
        ),
    },
    KolomBilangan<BarisGudang>('JumlahProduk', 'Produk', 'penting'),
    KolomUang<BarisGudang>('Nilai', 'Nilai persediaan'),
];

const kolomKategori: KolomTabel<BarisKategori>[] = [
    {
        id: 'NamaKategori',
        accessorKey: 'NamaKategori',
        header: 'Kategori',
        meta: { label: 'Kategori', prioritas: 'utama', wajib: true },
    },
    KolomBilangan<BarisKategori>('JumlahProduk', 'Produk', 'penting'),
    KolomUang<BarisKategori>('Nilai', 'Nilai persediaan'),
];

const kolomKritis: KolomTabel<BarisStokKritis>[] = [
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block text-teks-utama">{b.NamaProduk}</span>
                {b.Sku ? <span className="block font-mono text-label text-teks-sekunder">{b.Sku}</span> : null}
            </>
        ),
    },
    {
        id: 'NamaGudang',
        accessorKey: 'NamaGudang',
        header: 'Lokasi stok',
        meta: { label: 'Lokasi stok', prioritas: 'penting' },
        cell: ({ row }) =>
            `${row.original.NamaGudang}${row.original.NamaOutlet ? ` | ${row.original.NamaOutlet}` : ''}`,
    },
    {
        id: 'Saldo',
        accessorKey: 'Saldo',
        header: 'Saldo',
        meta: { label: 'Saldo', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatJumlahStok(row.original.Saldo, row.original.SimbolSatuan),
    },
    {
        id: 'StokMinimum',
        accessorKey: 'StokMinimum',
        header: 'Minimum',
        meta: { label: 'Minimum', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatJumlahStok(row.original.StokMinimum, row.original.SimbolSatuan),
    },
    {
        id: 'Kekurangan',
        accessorKey: 'Kekurangan',
        header: 'Kurang dari minimum',
        enableSorting: false,
        meta: { label: 'Kurang dari minimum', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatJumlahStok(row.original.Kekurangan, row.original.SimbolSatuan),
    },
];

const labelStatusKedaluwarsa: Record<BarisBatchKedaluwarsa['Status'], string> = {
    Lewat: 'Sudah lewat',
    Segera: 'Segera',
    Mendekati: 'Mendekati',
};

const kolomKedaluwarsa: KolomTabel<BarisBatchKedaluwarsa>[] = [
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block text-teks-utama">{b.NamaProduk}</span>
                <span className="block font-mono text-label text-teks-sekunder">Batch {b.NomorBatch}</span>
            </>
        ),
    },
    {
        id: 'TanggalKedaluwarsa',
        accessorKey: 'TanggalKedaluwarsa',
        header: 'Kedaluwarsa',
        meta: { label: 'Kedaluwarsa', prioritas: 'utama' },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block text-teks-utama">{FormatTanggal(b.TanggalKedaluwarsa)}</span>
                <span className="block text-label text-teks-sekunder">
                    {labelStatusKedaluwarsa[b.Status]}
                    {b.SisaHari < 0
                        ? ` (${String(Math.abs(b.SisaHari))} hari lalu)`
                        : ` (${String(b.SisaHari)} hari lagi)`}
                </span>
            </>
        ),
    },
    {
        id: 'Sisa',
        accessorKey: 'Sisa',
        header: 'Sisa',
        meta: { label: 'Sisa', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatJumlahStok(row.original.Sisa, row.original.SimbolSatuan),
    },
    {
        id: 'NamaGudang',
        accessorKey: 'NamaGudang',
        header: 'Lokasi stok',
        meta: { label: 'Lokasi stok', prioritas: 'rendah' },
        cell: ({ row }) =>
            `${row.original.NamaGudang}${row.original.NamaOutlet ? ` | ${row.original.NamaOutlet}` : ''}`,
    },
];

const opsiHariCakupan = [
    { Nilai: '7', Label: '7 hari' },
    { Nilai: '14', Label: '14 hari' },
    { Nilai: '30', Label: '30 hari' },
];

const opsiRentangPerputaran = [
    { Nilai: '30', Label: '30 hari' },
    { Nilai: '60', Label: '60 hari' },
    { Nilai: '90', Label: '90 hari' },
];

const labelKelasPerputaran = {
    Cepat: 'Cepat laku',
    Normal: 'Normal',
    Lambat: 'Lambat laku',
    Mati: 'Tidak bergerak',
} as const;

const kolomPerputaran: KolomTabel<BarisPerputaranStok>[] = [
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block text-teks-utama">{b.NamaProduk}</span>
                {b.Sku ? <span className="block font-mono text-label text-teks-sekunder">{b.Sku}</span> : null}
            </>
        ),
    },
    {
        id: 'Kelas',
        accessorKey: 'Kelas',
        header: 'Kelas',
        meta: { label: 'Kelas', prioritas: 'utama' },
        cell: ({ row }) => labelKelasPerputaran[row.original.Kelas],
    },
    {
        id: 'Pakai',
        accessorKey: 'Pakai',
        header: 'Terpakai',
        meta: { label: 'Terpakai', angka: true, prioritas: 'utama' },
        cell: ({ row }) => FormatJumlahStok(row.original.Pakai, row.original.SimbolSatuan),
    },
    {
        id: 'Saldo',
        accessorKey: 'Saldo',
        header: 'Saldo',
        meta: { label: 'Saldo', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatJumlahStok(row.original.Saldo, row.original.SimbolSatuan),
    },
    KolomUang<BarisPerputaranStok>('Nilai', 'Nilai persediaan', 'penting'),
    {
        id: 'HariCover',
        accessorKey: 'HariCover',
        header: 'Cukup untuk',
        meta: { label: 'Cukup untuk', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => (row.original.HariCover === null ? '–' : `${String(row.original.HariCover)} hari`),
    },
    {
        id: 'NamaGudang',
        accessorKey: 'NamaGudang',
        header: 'Lokasi stok',
        meta: { label: 'Lokasi stok', prioritas: 'rendah' },
        cell: ({ row }) =>
            `${row.original.NamaGudang}${row.original.NamaOutlet ? ` | ${row.original.NamaOutlet}` : ''}`,
    },
];

const kolomRestock: KolomTabel<BarisSaranRestock>[] = [
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block text-teks-utama">{b.NamaProduk}</span>
                {b.Sku ? <span className="block font-mono text-label text-teks-sekunder">{b.Sku}</span> : null}
            </>
        ),
    },
    {
        id: 'HariHabis',
        accessorKey: 'HariHabis',
        header: 'Perkiraan habis',
        meta: { label: 'Perkiraan habis', prioritas: 'utama' },
        cell: ({ row: { original: b } }) =>
            b.HariHabis === null ? '–' : b.HariHabis === 0 ? 'Sudah habis' : `${String(b.HariHabis)} hari lagi`,
    },
    {
        id: 'SaranBeli',
        accessorKey: 'SaranBeli',
        header: 'Saran beli',
        meta: { label: 'Saran beli', angka: true, prioritas: 'utama' },
        cell: ({ row }) => FormatJumlahStok(row.original.SaranBeli, row.original.SimbolSatuan),
    },
    {
        id: 'Saldo',
        accessorKey: 'Saldo',
        header: 'Saldo',
        meta: { label: 'Saldo', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatJumlahStok(row.original.Saldo, row.original.SimbolSatuan),
    },
    {
        id: 'FaktorMusim',
        accessorKey: 'FaktorMusim',
        header: 'Faktor musim',
        enableSorting: false,
        meta: { label: 'Faktor musim', angka: true, prioritas: 'penting' },
        cell: ({ row }) =>
            row.original.FaktorMusim === '1.00' ? '–' : `×${row.original.FaktorMusim.replace('.', ',')}`,
    },
    {
        id: 'RataPerHari',
        accessorKey: 'RataPerHari',
        header: 'Terpakai per hari',
        enableSorting: false,
        meta: { label: 'Terpakai per hari', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatJumlahStok(row.original.RataPerHari, row.original.SimbolSatuan),
    },
    {
        id: 'NamaGudang',
        accessorKey: 'NamaGudang',
        header: 'Lokasi stok',
        meta: { label: 'Lokasi stok', prioritas: 'rendah' },
        cell: ({ row }) =>
            `${row.original.NamaGudang}${row.original.NamaOutlet ? ` | ${row.original.NamaOutlet}` : ''}`,
    },
];

/** Alamat formulir order produksi yang sudah terisi dari satu baris rencana (§9.11). */
export function BuatUrlDrafProduksi(b: BarisRencanaProduksi, tanggal: string): string {
    const kueri = new URLSearchParams({
        produk: b.UuidProduk,
        gudang: b.UuidGudang,
        jumlah: b.Rekomendasi.replace(/\.?0+$/, ''),
        tanggal,
    });

    return `/kelola/persediaan/produksi/buat?${kueri.toString()}`;
}

function BuatKolomRencana(tanggal: string, bolehKelola: boolean): KolomTabel<BarisRencanaProduksi>[] {
    return [
        {
            id: 'NamaProduk',
            accessorKey: 'NamaProduk',
            header: 'Produk',
            meta: { label: 'Produk', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: b } }) => (
                <>
                    <span className="block text-teks-utama">{b.NamaProduk}</span>
                    <span className="block text-label text-teks-sekunder">
                        {b.NamaGudang}
                        {b.NamaOutlet ? ` | ${b.NamaOutlet}` : ''}
                    </span>
                </>
            ),
        },
        {
            id: 'Rekomendasi',
            accessorKey: 'Rekomendasi',
            header: 'Produksi',
            meta: { label: 'Produksi', angka: true, prioritas: 'utama' },
            cell: ({ row: { original: b } }) =>
                Number(b.Rekomendasi) > 0 ? (
                    <span className="flex flex-wrap items-center justify-end gap-2">
                        <span className="font-semibold">{FormatJumlahStok(b.Rekomendasi, b.SimbolSatuan)}</span>
                        {bolehKelola ? (
                            <Button asChild size="sm" variant="outline">
                                <Link href={BuatUrlDrafProduksi(b, tanggal)}>Siapkan draf produksi</Link>
                            </Button>
                        ) : null}
                    </span>
                ) : (
                    'Cukup'
                ),
        },
        {
            id: 'Kebutuhan',
            accessorKey: 'Kebutuhan',
            header: 'Kebutuhan',
            meta: { label: 'Kebutuhan', angka: true, prioritas: 'penting' },
            cell: ({ row: { original: b } }) => FormatJumlahStok(b.Kebutuhan, b.SimbolSatuan),
        },
        {
            id: 'Saldo',
            accessorKey: 'Saldo',
            header: 'Saldo sekarang',
            meta: { label: 'Saldo sekarang', angka: true, prioritas: 'penting' },
            cell: ({ row: { original: b } }) => FormatJumlahStok(b.Saldo, b.SimbolSatuan),
        },
        {
            id: 'RataHariSama',
            accessorKey: 'RataHariSama',
            header: 'Rata-rata hari yang sama',
            enableSorting: false,
            meta: { label: 'Rata-rata hari yang sama', angka: true, prioritas: 'rendah' },
            cell: ({ row: { original: b } }) =>
                b.Sampel === 0 ? '–' : FormatJumlahStok(b.RataHariSama, b.SimbolSatuan),
        },
        {
            id: 'PreOrder',
            accessorKey: 'PreOrder',
            header: 'Pre-order',
            enableSorting: false,
            meta: { label: 'Pre-order', angka: true, prioritas: 'rendah' },
            cell: ({ row: { original: b } }) => FormatJumlahStok(b.PreOrder, b.SimbolSatuan),
        },
    ];
}

/**
 * F-14a laporan stok (izin lihat persediaan): nilai persediaan per lokasi stok & kategori pada akhir tanggal
 * tertentu (dari buku stok), stok kritis (saldo ≤ batas minimum per lokasi), dan F-05g batch yang sudah lewat atau akan
 * kedaluwarsa dalam 30 hari, serta X6 saran restock dari laju pemakaian 28 hari terakhir. Posisi & kartu stok per
 * produk ada di menu Persediaan.
 */
export default function HalamanLaporanStok({
    Saring,
    OpsiGudang,
    Nilai,
    Kritis,
    Kedaluwarsa,
    Restock,
    Perputaran,
    Rencana,
}: PropsLaporanStok) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const bolehKelola = PunyaIzinTenant(props.Akses, IzinTenant.PersediaanKelola);
    // D-183: tab kedaluwarsa hanya untuk usaha yang menyimpan barang bertanggal kedaluwarsa, rencana produksi hanya untuk
    // usaha yang memproduksi/merakit; tab yang sedang dibuka (tautan lama, hasil pencarian) tetap tampil.
    const sektorBatch = PakaiSektor(['RTL-PHR', 'FNB', 'RTL-GEN', 'RTL-FRS', 'RTL-PET', 'RTL-FLR']);
    const sektorProduksi = PakaiSektor(['FNB', 'WHS', 'RTL-BLD', 'BIZ-MFG']);
    const tabStok = [
        { nilai: 'nilai', label: 'Nilai persediaan' },
        { nilai: 'kritis', label: 'Stok kritis' },
        { nilai: 'kedaluwarsa', label: 'Kedaluwarsa' },
        { nilai: 'restock', label: 'Saran restock' },
        { nilai: 'perputaran', label: 'Slow & fast moving' },
        { nilai: 'rencana', label: 'Rencana produksi' },
    ].filter(
        (tab) =>
            (tab.nilai !== 'kedaluwarsa' || sektorBatch || Saring.Tab === 'kedaluwarsa') &&
            (tab.nilai !== 'rencana' || sektorProduksi || Saring.Tab === 'rencana'),
    );
    const query = {
        tanggal: Saring.Tanggal,
        gudang: Saring.Gudang,
        ...(Saring.Tab === 'restock' || Saring.Tab === 'perputaran' ? { hari: String(Saring.Hari) } : {}),
    };
    const Terapkan = (ubah: Record<string, string>) => {
        const baru = Object.fromEntries(
            Object.entries({ ...query, tab: Saring.Tab, ...ubah }).filter(([, nilai]) => nilai !== ''),
        );
        router.get(alamat, baru, { preserveScroll: true, preserveState: true });
    };

    return (
        <TataLetakAplikasi judul="Laporan stok">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" role="group" aria-label="Filter laporan stok">
                {Saring.Tab === 'nilai' ? (
                    <PemilihTanggal
                        label="Posisi pada tanggal"
                        nilai={Saring.Tanggal}
                        tanpaKosongkan
                        saatBerubah={(tanggal) => Terapkan({ tanggal })}
                    />
                ) : null}
                {Saring.Tab === 'rencana' ? (
                    <PemilihTanggal
                        label="Rencana untuk tanggal"
                        nilai={Saring.Tanggal}
                        tanpaKosongkan
                        saatBerubah={(tanggal) => Terapkan({ tanggal })}
                    />
                ) : null}
                {Saring.Tab === 'restock' ? (
                    <div className="flex flex-col gap-1">
                        <Label htmlFor="saring-laporan-hari" className="text-label font-semibold text-teks-utama">
                            Stok cukup untuk
                        </Label>
                        <PilihanCari
                            id="saring-laporan-hari"
                            label="Stok cukup untuk"
                            nilai={String(Saring.Hari)}
                            opsi={opsiHariCakupan}
                            saatBerubah={(hari) => Terapkan({ hari })}
                        />
                    </div>
                ) : null}
                {Saring.Tab === 'perputaran' ? (
                    <div className="flex flex-col gap-1">
                        <Label htmlFor="saring-laporan-rentang" className="text-label font-semibold text-teks-utama">
                            Rentang pemakaian
                        </Label>
                        <PilihanCari
                            id="saring-laporan-rentang"
                            label="Rentang pemakaian"
                            nilai={String(Saring.Hari)}
                            opsi={opsiRentangPerputaran}
                            saatBerubah={(hari) => Terapkan({ hari })}
                        />
                    </div>
                ) : null}
                {OpsiGudang.length > 1 ? (
                    <div className="flex flex-col gap-1">
                        <Label htmlFor="saring-laporan-gudang" className="text-label font-semibold text-teks-utama">
                            Lokasi stok
                        </Label>
                        <PilihanCari
                            id="saring-laporan-gudang"
                            label="Lokasi stok"
                            nilai={Saring.Gudang}
                            opsi={OpsiGudang}
                            kosong="Semua lokasi stok"
                            saatBerubah={(gudang) => Terapkan({ gudang })}
                        />
                    </div>
                ) : null}
            </div>

            <div className="flex flex-wrap items-end justify-between gap-2">
                <NavigasiTab
                    label="Jenis laporan stok"
                    alamat={alamat}
                    query={query}
                    tabAktif={Saring.Tab}
                    tab={tabStok}
                />
                <TautanEkspor alamat={`${alamat}/ekspor`} query={{ ...query, tab: Saring.Tab }} eksporBesar />
            </div>

            {Nilai ? (
                <>
                    <dl className="grid grid-cols-2 gap-3 rounded-panel border border-garis bg-permukaan p-4 md:max-w-xl">
                        <div>
                            <dt className="text-label text-teks-sekunder">
                                Nilai persediaan {FormatTanggal(Saring.Tanggal)}
                            </dt>
                            <dd className="text-subjudul font-semibold text-teks-utama tabular-nums">
                                {FormatRupiah(Nilai.Total.Nilai)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-label text-teks-sekunder">Produk bersaldo</dt>
                            <dd className="text-subjudul font-semibold text-teks-utama tabular-nums">
                                {String(Nilai.Total.JumlahProduk)}
                            </dd>
                        </div>
                    </dl>
                    <section aria-labelledby="judul-per-gudang" className="flex flex-col gap-2">
                        <h2 id="judul-per-gudang" className="text-subjudul font-semibold text-teks-utama">
                            Per lokasi stok
                        </h2>
                        <TabelData
                            id="laporan-stok-gudang"
                            label="Nilai persediaan per lokasi stok"
                            kolom={kolomGudang}
                            sumber={{ mode: 'lokal', data: Nilai.PerGudang }}
                            ambilIdBaris={(b) => b.Kunci}
                            urutBawaan="-Nilai"
                            cari={false}
                            kosong={{ ilustrasi: true, judul: 'Belum ada stok pada tanggal ini.' }}
                        />
                    </section>
                    <section aria-labelledby="judul-per-kategori" className="flex flex-col gap-2">
                        <h2 id="judul-per-kategori" className="text-subjudul font-semibold text-teks-utama">
                            Per kategori
                        </h2>
                        <TabelData
                            id="laporan-stok-kategori"
                            label="Nilai persediaan per kategori"
                            kolom={kolomKategori}
                            sumber={{ mode: 'lokal', data: Nilai.PerKategori }}
                            ambilIdBaris={(b) => b.Kunci}
                            urutBawaan="-Nilai"
                            cari="Cari kategori"
                            kosong={{ ilustrasi: true, judul: 'Belum ada stok pada tanggal ini.' }}
                        />
                    </section>
                </>
            ) : null}

            {Kritis ? (
                <section aria-labelledby="judul-kritis" className="flex flex-col gap-2">
                    <h2 id="judul-kritis" className="text-subjudul font-semibold text-teks-utama">
                        Stok kritis ({String(Kritis.Jumlah)})
                    </h2>
                    <p className="text-label text-teks-sekunder">
                        Produk yang saldonya sama dengan atau di bawah batas minimum lokasi stoknya. Atur batas minimum
                        di halaman produk.
                    </p>
                    <TabelData
                        id="laporan-stok-kritis"
                        label="Stok kritis"
                        kolom={kolomKritis}
                        sumber={{ mode: 'lokal', data: Kritis.Baris }}
                        ambilIdBaris={(b) => b.Kunci}
                        cari="Cari produk"
                        alamatDetail={(b) => BuatUrlKartuStok(b.UuidProduk, b.UuidGudang)}
                        kosong={{
                            ilustrasi: true,
                            judul: 'Tidak ada stok kritis. Semua produk berbatas minimum masih di atas batasnya.',
                        }}
                    />
                </section>
            ) : null}

            {Kedaluwarsa ? (
                <section aria-labelledby="judul-kedaluwarsa" className="flex flex-col gap-2">
                    <h2 id="judul-kedaluwarsa" className="text-subjudul font-semibold text-teks-utama">
                        Batch kedaluwarsa ({String(Kedaluwarsa.Jumlah)})
                    </h2>
                    <p className="text-label text-teks-sekunder">
                        Batch yang masih bersisa dan sudah lewat atau kedaluwarsa dalam 30 hari. Penjualan mengambil
                        batch berkedaluwarsa terdekat lebih dulu (FEFO); barang yang sudah lewat dicatat sebagai bahan
                        terbuang atau penyesuaian stok.
                    </p>
                    <TabelData
                        id="laporan-stok-kedaluwarsa"
                        label="Batch kedaluwarsa"
                        kolom={kolomKedaluwarsa}
                        sumber={{ mode: 'lokal', data: Kedaluwarsa.Baris }}
                        ambilIdBaris={(b) => b.Kunci}
                        urutBawaan="TanggalKedaluwarsa"
                        cari="Cari produk atau batch"
                        alamatDetail={(b) => BuatUrlKartuStok(b.UuidProduk, b.UuidGudang)}
                        kosong={{
                            ilustrasi: true,
                            judul: 'Tidak ada batch yang lewat atau mendekati kedaluwarsa dalam 30 hari.',
                        }}
                    />
                </section>
            ) : null}

            {Perputaran ? (
                <section aria-labelledby="judul-perputaran" className="flex flex-col gap-2">
                    <h2 id="judul-perputaran" className="text-subjudul font-semibold text-teks-utama">
                        Slow &amp; fast moving ({String(Perputaran.Baris.length)})
                    </h2>
                    <p className="max-w-3xl text-label text-teks-sekunder">
                        Perputaran = pemakaian {String(Perputaran.Rentang)} hari terakhir (sampai kemarin) ÷ saldo
                        sekarang. Cepat laku: 1 atau lebih (stok habis dalam rentang itu). Normal: 0,25 sampai kurang
                        dari 1. Lambat laku: di bawah 0,25. Tidak bergerak: ada saldo tetapi tidak ada pemakaian.
                    </p>
                    <dl className="grid grid-cols-2 gap-3 rounded-panel border border-garis bg-permukaan p-4 md:grid-cols-5">
                        {(['Cepat', 'Normal', 'Lambat', 'Mati'] as const).map((kelas) => (
                            <div key={kelas}>
                                <dt className="text-label text-teks-sekunder">{labelKelasPerputaran[kelas]}</dt>
                                <dd className="text-subjudul font-semibold tabular-nums text-teks-utama">
                                    {String(Perputaran.Ringkasan[kelas].Jumlah)} produk
                                </dd>
                            </div>
                        ))}
                        <div>
                            <dt className="text-label text-teks-sekunder">Nilai stok lambat &amp; tidak bergerak</dt>
                            <dd className="text-subjudul font-semibold tabular-nums text-teks-utama">
                                {FormatRupiah(Perputaran.NilaiTertahan)}
                            </dd>
                        </div>
                    </dl>
                    <TabelData
                        id="laporan-stok-perputaran"
                        label="Slow dan fast moving"
                        kolom={kolomPerputaran}
                        sumber={{ mode: 'lokal', data: Perputaran.Baris }}
                        ambilIdBaris={(b) => b.Kunci}
                        cari="Cari produk"
                        alamatDetail={(b) => BuatUrlKartuStok(b.UuidProduk, b.UuidGudang)}
                        kosong={{
                            ilustrasi: true,
                            judul: 'Belum ada pemakaian atau saldo stok untuk dianalisis.',
                        }}
                    />
                </section>
            ) : null}

            {Rencana ? (
                <section aria-labelledby="judul-rencana" className="flex flex-col gap-2">
                    <h2 id="judul-rencana" className="text-subjudul font-semibold text-teks-utama">
                        Rencana produksi {FormatTanggal(Rencana.Target)} ({String(Rencana.Baris.length)})
                    </h2>
                    <p className="max-w-3xl text-label text-teks-sekunder">
                        Kebutuhan = yang lebih besar antara rata-rata pemakaian pada hari yang sama dalam{' '}
                        {String(Rencana.Minggu)} minggu terakhir dan pre-order yang diambil pada tanggal itu (pre-order
                        biasanya sudah ikut di riwayat penjualan, jadi tidak dijumlahkan). Produksi = kebutuhan − saldo
                        sekarang. Ini perkiraan; sesuaikan dengan cuaca, promo, dan kapasitas oven sebelum produksi.
                    </p>
                    <TabelData
                        id="laporan-stok-rencana"
                        label="Rencana produksi"
                        kolom={BuatKolomRencana(Rencana.Target, bolehKelola)}
                        sumber={{ mode: 'lokal', data: Rencana.Baris }}
                        ambilIdBaris={(b) => b.Kunci}
                        cari="Cari produk"
                        kosong={{
                            ilustrasi: true,
                            judul: 'Belum ada produk jenis Produksi dengan riwayat penjualan, saldo, atau pre-order.',
                        }}
                    />
                </section>
            ) : null}

            {Restock ? (
                <section aria-labelledby="judul-restock" className="flex flex-col gap-2">
                    <h2 id="judul-restock" className="text-subjudul font-semibold text-teks-utama">
                        Saran restock ({String(Restock.Baris.length)})
                    </h2>
                    <p className="max-w-3xl text-label text-teks-sekunder">
                        Laju pemakaian dihitung dari penjualan, bahan resep, produksi, dan barang susut selama{' '}
                        {String(Restock.HariDasar)} hari terakhir (sampai kemarin), dikurangi retur. Saran beli =
                        rata-rata per hari × {String(Restock.HariCakupan)} hari − saldo sekarang. Ini perkiraan;
                        sesuaikan dengan promo, musim, dan jadwal kirim pemasok sebelum membuat pesanan pembelian.
                    </p>
                    <p className="max-w-3xl text-label text-teks-sekunder">
                        {Restock.Musim.Jenis === 'Lebaran'
                            ? `Periode ini dekat Ramadan & Lebaran (${Restock.Musim.Lebaran ? FormatTanggal(Restock.Musim.Lebaran) : ''}). Laju dikali faktor musim dari masa yang sama sebelum Lebaran tahun lalu (digeser ${String(Restock.Musim.SelisihHari)} hari).`
                            : 'Laju dikali faktor musim dari periode yang sama tahun lalu bila datanya ada (tanpa data = ×1).'}{' '}
                        Faktor dibatasi ×0,5 sampai ×3.
                    </p>
                    <TabelData
                        id="laporan-stok-restock"
                        label="Saran restock"
                        kolom={kolomRestock}
                        sumber={{ mode: 'lokal', data: Restock.Baris }}
                        ambilIdBaris={(b) => b.Kunci}
                        cari="Cari produk"
                        alamatDetail={(b) => BuatUrlKartuStok(b.UuidProduk, b.UuidGudang)}
                        kosong={{
                            ilustrasi: true,
                            judul: `Belum ada pemakaian stok dalam ${String(Restock.HariDasar)} hari terakhir.`,
                        }}
                    />
                </section>
            ) : null}
        </TataLetakAplikasi>
    );
}
