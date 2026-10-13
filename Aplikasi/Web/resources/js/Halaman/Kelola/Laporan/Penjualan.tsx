import TabelData from '@/Komponen/TabelData/TabelData';
import type { HasilTabel, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { KolomAngkaPenjualan, KolomBilangan, KolomQty, KolomUang } from '@/Komponen/Laporan/KolomLaporan';
import NavigasiTab from '@/Komponen/Laporan/NavigasiTab';
import PetaPanasJam from '@/Komponen/Laporan/PetaPanasJam';
import PilihanInsightWhatsapp from '@/Komponen/Laporan/PilihanInsightWhatsapp';
import SaringLaporan from '@/Komponen/Laporan/SaringLaporan';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { BandingkanDesimal } from '@/Pustaka/HitungDesimal';
import { PakaiFiturTerkunci } from '@/Pustaka/FiturPaket';
import { PakaiSektor } from '@/Pustaka/Sektor';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatDurasi, FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { BuatQueryLaporan } from '@/Pustaka/Laporan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type {
    BarisAbcLaporan,
    BarisAntiFraudLaporan,
    BarisDetailPenjualan,
    BarisDiskonLaporan,
    BarisHarian,
    BarisKanalLaporan,
    BarisOutletLaporan,
    BarisPelangganLaporan,
    BarisBundling,
    BarisUmpanBalik,
    IsiUmpanBalik,
    BarisFoodCost,
    BarisPrediksi,
    IsiBundling,
    IsiPrediksi,
    BarisPerformaKasir,
    BarisWaktuSaji,
    IsiFoodCost,
    BarisKasirLaporan,
    BarisKategoriLaporan,
    BarisMenuLaporan,
    BarisMetodeLaporan,
    BarisProdukLaporan,
    BarisVoidItemLaporan,
    IsiAbc,
    IsiJam,
    IsiMenu,
    KelasMenu,
    PropsLaporanPenjualan,
    TabLaporanPenjualan,
} from '@/Tipe/Laporan';

const alamat = '/kelola/laporan/penjualan';

const daftarTab: { nilai: TabLaporanPenjualan; label: string }[] = [
    { nilai: 'harian', label: 'Ringkasan harian' },
    { nilai: 'detail', label: 'Detail penjualan' },
    { nilai: 'produk', label: 'Per produk' },
    { nilai: 'kategori', label: 'Per kategori' },
    { nilai: 'jam', label: 'Per jam' },
    { nilai: 'kasir', label: 'Per kasir' },
    { nilai: 'kanal', label: 'Per kanal' },
    { nilai: 'metode', label: 'Metode bayar' },
    { nilai: 'diskon', label: 'Diskon' },
    { nilai: 'void-item', label: 'Void item' },
    { nilai: 'anti-fraud', label: 'Anti-fraud' },
    { nilai: 'abc', label: 'Analisis ABC' },
    { nilai: 'menu', label: 'Menu engineering' },
    { nilai: 'pelanggan', label: 'Per pelanggan' },
    { nilai: 'outlet', label: 'Perbandingan outlet' },
    { nilai: 'performa-kasir', label: 'Performa kasir' },
    { nilai: 'waktu-saji', label: 'Waktu saji dapur' },
    { nilai: 'food-cost', label: 'Food cost' },
    { nilai: 'prediksi', label: 'Prediksi penjualan' },
    { nilai: 'bundling', label: 'Rekomendasi bundling' },
    { nilai: 'umpan-balik', label: 'Umpan balik' },
];

const kolomHarian: KolomTabel<BarisHarian>[] = [
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
    },
    ...KolomAngkaPenjualan<BarisHarian>(),
    KolomUang<BarisHarian>('Pajak', 'Pajak', 'rendah'),
    KolomBilangan<BarisHarian>('JumlahTransaksi', 'Transaksi', 'penting'),
    KolomUang<BarisHarian>('RataRataKeranjang', 'Rata-rata keranjang', 'rendah'),
];

const kolomProduk: KolomTabel<BarisProdukLaporan>[] = [
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
    },
    KolomQty<BarisProdukLaporan>('Qty', 'Qty'),
    ...KolomAngkaPenjualan<BarisProdukLaporan>(),
    KolomBilangan<BarisProdukLaporan>('JumlahTransaksi', 'Transaksi'),
];

const kolomDetail: KolomTabel<BarisDetailPenjualan>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'No transaksi',
        meta: { label: 'No transaksi', prioritas: 'utama', wajib: true, kelasSel: 'font-mono whitespace-nowrap' },
    },
    {
        id: 'Waktu',
        accessorKey: 'Waktu',
        header: 'Waktu',
        meta: { label: 'Waktu', prioritas: 'penting', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.Waktu),
    },
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama' },
    },
    KolomQty<BarisDetailPenjualan>('Qty', 'Qty'),
    KolomUang<BarisDetailPenjualan>('HargaSatuan', 'Harga satuan', 'rendah'),
    KolomUang<BarisDetailPenjualan>('Kotor', 'Kotor', 'rendah'),
    KolomUang<BarisDetailPenjualan>('Diskon', 'Diskon', 'rendah'),
    KolomUang<BarisDetailPenjualan>('Total', 'Total', 'penting'),
    {
        id: 'Metode',
        accessorKey: 'Metode',
        header: 'Metode bayar',
        enableSorting: false,
        meta: { label: 'Metode bayar', prioritas: 'rendah' },
    },
    {
        id: 'Kanal',
        accessorKey: 'Kanal',
        header: 'Jenis order',
        enableSorting: false,
        meta: { label: 'Jenis order', prioritas: 'rendah' },
    },
    {
        id: 'NamaOutlet',
        accessorKey: 'NamaOutlet',
        header: 'Outlet',
        enableSorting: false,
        meta: { label: 'Outlet', prioritas: 'rendah' },
    },
    {
        id: 'NamaKasir',
        accessorKey: 'NamaKasir',
        header: 'Kasir',
        enableSorting: false,
        meta: { label: 'Kasir', prioritas: 'rendah' },
    },
];

const kolomKategori: KolomTabel<BarisKategoriLaporan>[] = [
    {
        id: 'NamaKategori',
        accessorKey: 'NamaKategori',
        header: 'Kategori',
        meta: { label: 'Kategori', prioritas: 'utama', wajib: true },
    },
    KolomBilangan<BarisKategoriLaporan>('JumlahProduk', 'Produk'),
    KolomQty<BarisKategoriLaporan>('Qty', 'Qty'),
    ...KolomAngkaPenjualan<BarisKategoriLaporan>(),
];

const kolomPerJam: KolomTabel<IsiJam['PerJam'][number]>[] = [
    {
        id: 'Jam',
        accessorKey: 'Jam',
        header: 'Jam',
        meta: { label: 'Jam', prioritas: 'utama', wajib: true },
        cell: ({ row }) =>
            `${String(row.original.Jam).padStart(2, '0')}.00–${String(row.original.Jam).padStart(2, '0')}.59`,
    },
    KolomUang<IsiJam['PerJam'][number]>('Bersih', 'Bersih'),
    KolomBilangan<IsiJam['PerJam'][number]>('JumlahTransaksi', 'Transaksi', 'penting'),
];

const kolomKasir: KolomTabel<BarisKasirLaporan>[] = [
    {
        id: 'NamaKasir',
        accessorKey: 'NamaKasir',
        header: 'Kasir',
        meta: { label: 'Kasir', prioritas: 'utama', wajib: true },
    },
    ...KolomAngkaPenjualan<BarisKasirLaporan>(),
    KolomBilangan<BarisKasirLaporan>('JumlahTransaksi', 'Transaksi', 'penting'),
    KolomBilangan<BarisKasirLaporan>('JumlahRetur', 'Retur (dokumen)'),
    KolomUang<BarisKasirLaporan>('RataRataKeranjang', 'Rata-rata keranjang', 'rendah'),
];

const kolomKanal: KolomTabel<BarisKanalLaporan>[] = [
    {
        id: 'LabelKanal',
        accessorKey: 'LabelKanal',
        header: 'Kanal',
        meta: { label: 'Kanal', prioritas: 'utama', wajib: true },
    },
    ...KolomAngkaPenjualan<BarisKanalLaporan>(),
    KolomBilangan<BarisKanalLaporan>('JumlahTransaksi', 'Transaksi', 'penting'),
    KolomUang<BarisKanalLaporan>('RataRataKeranjang', 'Rata-rata keranjang', 'rendah'),
];

const KolomPorsi = <T extends { Porsi: string }>(): KolomTabel<T> =>
    ({
        id: 'Porsi',
        accessorKey: 'Porsi',
        header: 'Porsi',
        sortingFn: (a, b, kolom) => BandingkanDesimal(String(a.getValue(kolom)), String(b.getValue(kolom))),
        meta: { label: 'Porsi omzet', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatPersen(row.original.Porsi),
    }) as KolomTabel<T>;

const kolomPelanggan: KolomTabel<BarisPelangganLaporan>[] = [
    {
        id: 'NamaPelanggan',
        accessorKey: 'NamaPelanggan',
        header: 'Pelanggan',
        meta: { label: 'Pelanggan', prioritas: 'utama', wajib: true },
    },
    ...KolomAngkaPenjualan<BarisPelangganLaporan>(),
    KolomBilangan<BarisPelangganLaporan>('JumlahTransaksi', 'Transaksi', 'penting'),
    KolomUang<BarisPelangganLaporan>('RataRataKeranjang', 'Rata-rata keranjang', 'rendah'),
    KolomPorsi<BarisPelangganLaporan>(),
];

const kolomOutlet: KolomTabel<BarisOutletLaporan>[] = [
    {
        id: 'NamaOutlet',
        accessorKey: 'NamaOutlet',
        header: 'Outlet',
        meta: { label: 'Outlet', prioritas: 'utama', wajib: true },
    },
    ...KolomAngkaPenjualan<BarisOutletLaporan>(),
    KolomBilangan<BarisOutletLaporan>('JumlahTransaksi', 'Transaksi', 'penting'),
    KolomUang<BarisOutletLaporan>('RataRataKeranjang', 'Rata-rata keranjang', 'rendah'),
    {
        id: 'Margin',
        accessorKey: 'Margin',
        header: 'Margin',
        sortingFn: (a, b, kolom) => BandingkanDesimal(String(a.getValue(kolom)), String(b.getValue(kolom))),
        meta: { label: 'Margin laba kotor', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatPersen(row.original.Margin),
    },
    KolomPorsi<BarisOutletLaporan>(),
];

const KolomDurasi = <T extends object>(
    id: keyof T & string,
    label: string,
    prioritas: 'utama' | 'penting' | 'rendah' = 'penting',
): KolomTabel<T> =>
    ({
        id,
        accessorKey: id,
        header: label,
        meta: { label, angka: true, prioritas, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => {
            const nilai = row.original[id] as number | null;

            return nilai === null ? '–' : FormatDurasi(nilai);
        },
    }) as KolomTabel<T>;

const kolomWaktuSaji: KolomTabel<BarisWaktuSaji>[] = [
    {
        id: 'NamaStasiun',
        accessorKey: 'NamaStasiun',
        header: 'Stasiun',
        meta: { label: 'Stasiun', prioritas: 'utama', wajib: true },
    },
    KolomBilangan<BarisWaktuSaji>('JumlahTiket', 'Tiket', 'utama'),
    KolomDurasi<BarisWaktuSaji>('TotalRataRata', 'Rata-rata total', 'utama'),
    KolomDurasi<BarisWaktuSaji>('TotalMedian', 'Median', 'penting'),
    KolomDurasi<BarisWaktuSaji>('TotalP90', 'Persentil 90', 'penting'),
    KolomDurasi<BarisWaktuSaji>('TotalTerlama', 'Terlama', 'rendah'),
    KolomDurasi<BarisWaktuSaji>('TungguRataRata', 'Rata-rata tunggu', 'rendah'),
    KolomDurasi<BarisWaktuSaji>('MasakRataRata', 'Rata-rata masak', 'rendah'),
    KolomDurasi<BarisWaktuSaji>('SajikanRataRata', 'Rata-rata sajikan', 'rendah'),
    {
        id: 'LewatTarget',
        accessorKey: 'LewatTarget',
        header: 'Lewat target',
        meta: { label: 'Lewat target', angka: true, prioritas: 'utama' },
        cell: ({ row }) => `${String(row.original.LewatTarget)} (${FormatPersen(row.original.PersenLewatTarget)})`,
    },
];

const kolomFoodCost: KolomTabel<BarisFoodCost>[] = [
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Bahan / produk',
        meta: { label: 'Bahan / produk', prioritas: 'utama', wajib: true },
    },
    KolomUang<BarisFoodCost>('NilaiTeoretis', 'Teoretis', 'penting'),
    KolomUang<BarisFoodCost>('NilaiTerbuang', 'Terbuang', 'penting'),
    KolomUang<BarisFoodCost>('NilaiSelisih', 'Selisih stok', 'penting'),
    KolomUang<BarisFoodCost>('NilaiAktual', 'Aktual', 'utama'),
    {
        id: 'VarianPersen',
        accessorKey: 'VarianPersen',
        header: 'Varian',
        meta: { label: 'Varian vs teoretis', angka: true, prioritas: 'penting' },
        cell: ({ row }) => (row.original.VarianPersen === null ? '–' : FormatPersen(row.original.VarianPersen)),
    },
    {
        id: 'QtyTerbuang',
        accessorKey: 'QtyTerbuang',
        header: 'Jumlah terbuang',
        enableSorting: false,
        meta: { label: 'Jumlah terbuang', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatJumlahStok(row.original.QtyTerbuang, row.original.SimbolSatuan),
    },
];

const kolomPerformaKasir: KolomTabel<BarisPerformaKasir>[] = [
    {
        id: 'NamaKasir',
        accessorKey: 'NamaKasir',
        header: 'Kasir',
        meta: { label: 'Kasir', prioritas: 'utama', wajib: true },
    },
    KolomBilangan<BarisPerformaKasir>('JumlahTransaksi', 'Transaksi', 'utama'),
    KolomUang<BarisPerformaKasir>('Bersih', 'Penjualan bersih', 'utama'),
    KolomUang<BarisPerformaKasir>('RataRataKeranjang', 'Rata-rata keranjang', 'penting'),
    {
        id: 'JamKerja',
        accessorKey: 'JamKerja',
        header: 'Jam kerja',
        sortingFn: (a, b, kolom) => BandingkanDesimal(String(a.getValue(kolom)), String(b.getValue(kolom))),
        meta: { label: 'Jam kerja', angka: true, prioritas: 'penting' },
        cell: ({ row }) => `${row.original.JamKerja.replace('.', ',')} jam`,
    },
    {
        id: 'TransaksiPerJam',
        accessorKey: 'TransaksiPerJam',
        header: 'Transaksi per jam',
        meta: { label: 'Transaksi per jam', angka: true, prioritas: 'penting' },
        cell: ({ row }) =>
            row.original.TransaksiPerJam === null ? '–' : row.original.TransaksiPerJam.replace('.', ','),
    },
    {
        id: 'OmzetPerJam',
        accessorKey: 'OmzetPerJam',
        header: 'Omzet per jam',
        meta: { label: 'Omzet per jam', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => (row.original.OmzetPerJam === null ? '–' : FormatRupiah(row.original.OmzetPerJam)),
    },
    {
        id: 'PersenVoid',
        accessorKey: 'PersenVoid',
        header: 'Void',
        meta: { label: 'Void', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => `${String(row.original.JumlahVoid)} (${FormatPersen(row.original.PersenVoid)})`,
    },
    KolomUang<BarisPerformaKasir>('SelisihKurang', 'Selisih kas kurang', 'rendah'),
];

const NamaHariId = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

const kolomPrediksi: KolomTabel<BarisPrediksi>[] = [
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => `${NamaHariId[row.original.Hari - 1] ?? ''}, ${FormatTanggal(row.original.Tanggal)}`,
    },
    KolomUang<BarisPrediksi>('Perkiraan', 'Perkiraan penjualan bersih', 'utama'),
    KolomBilangan<BarisPrediksi>('PerkiraanTransaksi', 'Perkiraan transaksi', 'penting'),
    KolomUang<BarisPrediksi>('Terendah', 'Terendah (riwayat)', 'rendah'),
    KolomUang<BarisPrediksi>('Tertinggi', 'Tertinggi (riwayat)', 'rendah'),
    KolomBilangan<BarisPrediksi>('Sampel', 'Sampel hari', 'rendah'),
];

const kolomBundling: KolomTabel<BarisBundling>[] = [
    {
        id: 'Pasangan',
        accessorFn: (b) => `${b.NamaA} + ${b.NamaB}`,
        header: 'Pasangan produk',
        enableSorting: false,
        meta: { label: 'Pasangan produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => `${b.NamaA} + ${b.NamaB}`,
    },
    KolomBilangan<BarisBundling>('Support', 'Transaksi bersama', 'utama'),
    {
        id: 'Lift',
        accessorKey: 'Lift',
        header: 'Lift',
        sortingFn: (a, b, kolom) => BandingkanDesimal(String(a.getValue(kolom)), String(b.getValue(kolom))),
        meta: { label: 'Lift', angka: true, prioritas: 'penting' },
        cell: ({ row }) => row.original.Lift.replace('.', ','),
    },
    {
        id: 'KeyakinanAB',
        accessorKey: 'KeyakinanAB',
        header: 'Beli A lalu B',
        enableSorting: false,
        meta: { label: 'Yang membeli pertama juga membeli kedua', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => `${FormatPersen(row.original.KeyakinanAB)} | ${FormatPersen(row.original.KeyakinanBA)}`,
    },
    {
        id: 'HargaPaket',
        accessorKey: 'HargaPaket',
        header: 'Saran harga paket',
        enableSorting: false,
        meta: { label: 'Saran harga paket', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) =>
            b.HargaPaket === null || b.HargaNormal === null
                ? '–'
                : `${FormatRupiah(b.HargaPaket)} (normal ${FormatRupiah(b.HargaNormal)})`,
    },
];

const kolomUmpanBalik: KolomTabel<BarisUmpanBalik>[] = [
    {
        id: 'Waktu',
        accessorKey: 'Waktu',
        header: 'Waktu',
        meta: { label: 'Waktu', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.Waktu),
    },
    {
        id: 'Nilai',
        accessorKey: 'Nilai',
        header: 'Nilai',
        meta: { label: 'Nilai', prioritas: 'utama' },
        cell: ({ row }) => (
            <LabelStatus
                jenis={row.original.Nilai >= 4 ? 'sukses' : row.original.Nilai === 3 ? 'netral' : 'bahaya'}
                teks={`${String(row.original.Nilai)} dari 5`}
            />
        ),
    },
    {
        id: 'Komentar',
        accessorKey: 'Komentar',
        header: 'Komentar',
        enableSorting: false,
        meta: { label: 'Komentar', prioritas: 'utama' },
        cell: ({ row }) => row.original.Komentar ?? '–',
    },
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'No transaksi',
        meta: { label: 'No transaksi', prioritas: 'penting', kelasSel: 'font-mono whitespace-nowrap' },
    },
    { id: 'NamaOutlet', accessorKey: 'NamaOutlet', header: 'Outlet', meta: { label: 'Outlet', prioritas: 'rendah' } },
    {
        id: 'NamaPelanggan',
        accessorKey: 'NamaPelanggan',
        header: 'Pelanggan',
        meta: { label: 'Pelanggan', prioritas: 'rendah' },
        cell: ({ row }) => row.original.NamaPelanggan ?? '–',
    },
];

const kolomMetode: KolomTabel<BarisMetodeLaporan>[] = [
    {
        id: 'NamaMetode',
        accessorKey: 'NamaMetode',
        header: 'Metode bayar',
        meta: { label: 'Metode bayar', prioritas: 'utama', wajib: true },
    },
    {
        id: 'LabelJenis',
        accessorKey: 'LabelJenis',
        header: 'Jenis',
        meta: { label: 'Jenis', prioritas: 'rendah' },
    },
    KolomUang<BarisMetodeLaporan>('Diterima', 'Diterima', 'rendah'),
    KolomUang<BarisMetodeLaporan>('Refund', 'Refund retur', 'rendah'),
    KolomUang<BarisMetodeLaporan>('Bersih', 'Bersih'),
    KolomBilangan<BarisMetodeLaporan>('JumlahTransaksi', 'Transaksi', 'penting'),
];

const kolomDiskon: KolomTabel<BarisDiskonLaporan>[] = [
    {
        id: 'NamaKasir',
        accessorKey: 'NamaKasir',
        header: 'Kasir',
        meta: { label: 'Kasir', prioritas: 'utama', wajib: true },
    },
    KolomBilangan<BarisDiskonLaporan>('JumlahTransaksi', 'Transaksi'),
    KolomBilangan<BarisDiskonLaporan>('JumlahBerdiskon', 'Berdiskon', 'penting'),
    KolomBilangan<BarisDiskonLaporan>('JumlahDisetujui', 'Disetujui penyetuju'),
    KolomUang<BarisDiskonLaporan>('DiskonBaris', 'Diskon baris', 'rendah'),
    KolomUang<BarisDiskonLaporan>('DiskonPesanan', 'Diskon pesanan', 'rendah'),
    KolomUang<BarisDiskonLaporan>('TotalDiskon', 'Total diskon'),
    KolomUang<BarisDiskonLaporan>('Kotor', 'Kotor', 'rendah'),
];

const kolomVoidItem: KolomTabel<BarisVoidItemLaporan>[] = [
    {
        id: 'Waktu',
        accessorKey: 'Waktu',
        header: 'Waktu',
        meta: { label: 'Waktu', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.Waktu),
    },
    { id: 'NamaProduk', accessorKey: 'NamaProduk', header: 'Item', meta: { label: 'Item', prioritas: 'utama' } },
    KolomQty<BarisVoidItemLaporan>('Jumlah', 'Jumlah'),
    KolomUang<BarisVoidItemLaporan>('Nilai', 'Nilai', 'penting'),
    { id: 'Alasan', accessorKey: 'Alasan', header: 'Alasan', meta: { label: 'Alasan', prioritas: 'penting' } },
    {
        id: 'NamaPembatal',
        accessorKey: 'NamaPembatal',
        header: 'Dibatalkan oleh',
        meta: { label: 'Dibatalkan oleh', prioritas: 'penting' },
    },
    {
        id: 'NamaPenyetuju',
        accessorKey: 'NamaPenyetuju',
        header: 'Disetujui oleh',
        meta: { label: 'Disetujui oleh', prioritas: 'rendah' },
    },
    {
        id: 'Terbuang',
        accessorKey: 'Terbuang',
        header: 'Bahan terbuang',
        meta: { label: 'Bahan terbuang', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => (
            <LabelStatus
                jenis={row.original.Terbuang ? 'peringatan' : 'netral'}
                teks={row.original.Terbuang ? 'Dicatat terbuang' : 'Tidak dicatat'}
            />
        ),
    },
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'No pesanan',
        meta: { label: 'No pesanan', prioritas: 'rendah', kelasSel: 'font-mono whitespace-nowrap' },
    },
    { id: 'NamaOutlet', accessorKey: 'NamaOutlet', header: 'Outlet', meta: { label: 'Outlet', prioritas: 'rendah' } },
];

const JenisTingkat: Record<BarisAntiFraudLaporan['Tingkat'], 'sukses' | 'peringatan' | 'bahaya'> = {
    Rendah: 'sukses',
    Sedang: 'peringatan',
    Tinggi: 'bahaya',
};

const kolomAntiFraud: KolomTabel<BarisAntiFraudLaporan>[] = [
    {
        id: 'NamaKasir',
        accessorKey: 'NamaKasir',
        header: 'Kasir',
        meta: { label: 'Kasir', prioritas: 'utama', wajib: true },
    },
    {
        id: 'Skor',
        accessorKey: 'Skor',
        header: 'Risiko',
        meta: { label: 'Risiko', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col items-start gap-1">
                <LabelStatus jenis={JenisTingkat[b.Tingkat]} teks={`${b.Tingkat} | ${String(b.Skor)}`} />
                {b.Alasan.map((a) => (
                    <span key={a} className="text-keterangan text-teks-sekunder">
                        {a}
                    </span>
                ))}
            </span>
        ),
    },
    KolomBilangan<BarisAntiFraudLaporan>('JumlahTransaksi', 'Transaksi'),
    KolomBilangan<BarisAntiFraudLaporan>('JumlahVoid', 'Void', 'penting'),
    KolomUang<BarisAntiFraudLaporan>('NilaiVoid', 'Nilai void', 'rendah'),
    KolomBilangan<BarisAntiFraudLaporan>('VoidCepatTunai', 'Void tunai ≤ 10 menit', 'penting'),
    KolomBilangan<BarisAntiFraudLaporan>('JumlahRetur', 'Retur'),
    KolomUang<BarisAntiFraudLaporan>('NilaiRetur', 'Nilai retur', 'rendah'),
    KolomBilangan<BarisAntiFraudLaporan>('JumlahBerdiskon', 'Berdiskon', 'rendah'),
    KolomUang<BarisAntiFraudLaporan>('TotalDiskon', 'Total diskon', 'rendah'),
    KolomBilangan<BarisAntiFraudLaporan>('BukaLaciManual', 'Buka laci manual'),
    KolomBilangan<BarisAntiFraudLaporan>('ShiftSelisihKurang', 'Shift kas kurang', 'rendah'),
    KolomUang<BarisAntiFraudLaporan>('SelisihKurang', 'Total kas kurang'),
];

const FormatPersen = (nilai: string) => `${nilai.replace('.', ',')}%`;

const keteranganAbc: Record<BarisAbcLaporan['Kelas'], string> = {
    A: 'Penyumbang ±80% penjualan. Jaga stok jangan sampai kosong dan pantau harganya.',
    B: 'Penyumbang 15% berikutnya. Stok cukup, tinjau berkala.',
    C: 'Sisa 5% penjualan. Kandidat dikurangi stoknya atau dihapus dari katalog.',
};

const JenisKelasAbc: Record<BarisAbcLaporan['Kelas'], 'sukses' | 'netral'> = {
    A: 'sukses',
    B: 'netral',
    C: 'netral',
};

const kolomAbc: KolomTabel<BarisAbcLaporan>[] = [
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
    },
    {
        id: 'Kelas',
        accessorKey: 'Kelas',
        header: 'Kelas',
        meta: { label: 'Kelas', prioritas: 'utama', wajib: true },
        cell: ({ row }) => (
            <LabelStatus jenis={JenisKelasAbc[row.original.Kelas]} teks={`Kelas ${row.original.Kelas}`} />
        ),
    },
    KolomUang<BarisAbcLaporan>('Bersih', 'Bersih'),
    {
        id: 'Porsi',
        accessorKey: 'Porsi',
        header: 'Porsi',
        enableSorting: false,
        meta: { label: 'Porsi', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatPersen(row.original.Porsi),
    },
    {
        id: 'PorsiKumulatif',
        accessorKey: 'PorsiKumulatif',
        header: 'Kumulatif',
        enableSorting: false,
        meta: { label: 'Kumulatif', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatPersen(row.original.PorsiKumulatif),
    },
    KolomQty<BarisAbcLaporan>('Qty', 'Qty'),
];

/** Label Indonesia untuk kelas menu engineering; istilah aslinya tetap disebut di keterangan. */
const infoKelasMenu: Record<
    KelasMenu,
    { label: string; jenis: 'sukses' | 'netral' | 'peringatan' | 'bahaya'; saran: string }
> = {
    Star: {
        label: 'Bintang',
        jenis: 'sukses',
        saran: 'Laris dan untung besar. Pertahankan resep, porsi, dan posisinya di menu.',
    },
    Plowhorse: {
        label: 'Laris, untung tipis',
        jenis: 'netral',
        saran: 'Laris tetapi marginnya kecil. Coba naikkan harga sedikit atau tekan HPP (porsi, bahan).',
    },
    Puzzle: {
        label: 'Untung besar, kurang laku',
        jenis: 'peringatan',
        saran: 'Marginnya besar tetapi jarang dipesan. Tonjolkan di menu, rekomendasikan, atau ganti namanya.',
    },
    Dog: {
        label: 'Kurang laku, untung tipis',
        jenis: 'bahaya',
        saran: 'Jarang dipesan dan marginnya kecil. Kandidat dihapus atau dirombak.',
    },
};

const kolomMenu: KolomTabel<BarisMenuLaporan>[] = [
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
    },
    {
        id: 'Kelas',
        accessorKey: 'Kelas',
        header: 'Kelas',
        meta: { label: 'Kelas', prioritas: 'utama', wajib: true },
        cell: ({ row }) => (
            <LabelStatus
                jenis={infoKelasMenu[row.original.Kelas].jenis}
                teks={infoKelasMenu[row.original.Kelas].label}
            />
        ),
    },
    KolomQty<BarisMenuLaporan>('Qty', 'Qty'),
    {
        id: 'PorsiQty',
        accessorKey: 'PorsiQty',
        header: 'Porsi qty',
        enableSorting: false,
        meta: { label: 'Porsi qty', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatPersen(row.original.PorsiQty),
    },
    KolomUang<BarisMenuLaporan>('MarginPerUnit', 'Margin per unit', 'penting'),
    {
        id: 'SaranHarga',
        accessorKey: 'SaranHarga',
        header: 'Saran harga',
        meta: { label: 'Saran harga', angka: true, prioritas: 'penting' },
        cell: ({ row }) =>
            row.original.SaranHarga === null
                ? '–'
                : `${FormatRupiah(row.original.SaranHarga)} (dari ${FormatRupiah(row.original.HargaRata)})`,
    },
    KolomUang<BarisMenuLaporan>('Bersih', 'Bersih', 'rendah'),
    KolomUang<BarisMenuLaporan>('Hpp', 'HPP', 'rendah'),
];

const kosong = { judul: 'Belum ada penjualan pada periode dan saring ini.' };

function IsiTab({
    tab,
    isi,
    queryEkspor,
}: {
    tab: TabLaporanPenjualan;
    isi: PropsLaporanPenjualan['Isi'];
    queryEkspor: string;
}) {
    switch (tab) {
        case 'detail':
            return (
                <TabelData
                    id="laporan-penjualan-detail"
                    label="Detail penjualan per item"
                    kolom={kolomDetail}
                    sumber={{ mode: 'server', alamat, awal: isi as HasilTabel<BarisDetailPenjualan> }}
                    ambilIdBaris={(b) => String(b.Id)}
                    urutBawaan="-Waktu"
                    cari="Cari nomor transaksi atau produk"
                    ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true, eksporBesar: true }}
                    kosong={kosong}
                />
            );
        case 'produk':
            return (
                <TabelData
                    id="laporan-penjualan-produk"
                    label="Penjualan per produk"
                    kolom={kolomProduk}
                    sumber={{ mode: 'server', alamat, awal: isi as HasilTabel<BarisProdukLaporan> }}
                    ambilIdBaris={(b) => String(b.IdProduk)}
                    urutBawaan="-Bersih"
                    cari="Cari nama produk"
                    ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true, eksporBesar: true }}
                    kosong={kosong}
                />
            );
        case 'kategori':
            return (
                <TabelData
                    id="laporan-penjualan-kategori"
                    label="Penjualan per kategori"
                    kolom={kolomKategori}
                    sumber={{ mode: 'lokal', data: isi as BarisKategoriLaporan[] }}
                    ambilIdBaris={(b) => b.Kunci}
                    urutBawaan="-Bersih"
                    cari="Cari kategori"
                    ekspor={{
                        alamat: `${alamat}/ekspor`,
                        label: 'Ekspor',
                        laporan: true,
                        eksporBesar: true,
                        query: queryEkspor,
                    }}
                    kosong={kosong}
                />
            );
        case 'jam': {
            const jam = isi as IsiJam;

            return (
                <div className="flex flex-col gap-4">
                    <section aria-labelledby="judul-heatmap" className="flex flex-col gap-2">
                        <h2 id="judul-heatmap" className="text-subjudul font-semibold text-teks-utama">
                            Penjualan bersih per hari & jam
                        </h2>
                        <p className="text-label text-teks-sekunder">
                            Jam lokal outlet saat transaksi dibuat. Retur tidak mengurangi heatmap.
                        </p>
                        <PetaPanasJam sel={jam.Sel} />
                    </section>
                    <TabelData
                        id="laporan-penjualan-jam"
                        label="Penjualan per jam"
                        kolom={kolomPerJam}
                        sumber={{ mode: 'lokal', data: jam.PerJam }}
                        ambilIdBaris={(b) => String(b.Jam)}
                        urutBawaan="Jam"
                        cari={false}
                        ekspor={{
                            alamat: `${alamat}/ekspor`,
                            label: 'Ekspor',
                            laporan: true,
                            eksporBesar: true,
                            query: queryEkspor,
                        }}
                        kosong={kosong}
                    />
                </div>
            );
        }
        case 'kasir':
            return (
                <TabelData
                    id="laporan-penjualan-kasir"
                    label="Penjualan per kasir"
                    kolom={kolomKasir}
                    sumber={{ mode: 'lokal', data: isi as BarisKasirLaporan[] }}
                    ambilIdBaris={(b) => b.Kunci}
                    urutBawaan="-Bersih"
                    cari="Cari nama kasir"
                    ekspor={{
                        alamat: `${alamat}/ekspor`,
                        label: 'Ekspor',
                        laporan: true,
                        eksporBesar: true,
                        query: queryEkspor,
                    }}
                    kosong={kosong}
                />
            );
        case 'kanal':
            return (
                <TabelData
                    id="laporan-penjualan-kanal"
                    label="Penjualan per kanal"
                    kolom={kolomKanal}
                    sumber={{ mode: 'lokal', data: isi as BarisKanalLaporan[] }}
                    ambilIdBaris={(b) => b.Kunci}
                    urutBawaan="-Bersih"
                    cari={false}
                    ekspor={{
                        alamat: `${alamat}/ekspor`,
                        label: 'Ekspor',
                        laporan: true,
                        eksporBesar: true,
                        query: queryEkspor,
                    }}
                    kosong={kosong}
                />
            );
        case 'pelanggan':
            return (
                <TabelData
                    id="laporan-penjualan-pelanggan"
                    label="Penjualan per pelanggan"
                    kolom={kolomPelanggan}
                    sumber={{ mode: 'lokal', data: isi as BarisPelangganLaporan[] }}
                    ambilIdBaris={(b) => b.Kunci}
                    urutBawaan="-Bersih"
                    cari="Cari nama pelanggan"
                    ekspor={{
                        alamat: `${alamat}/ekspor`,
                        label: 'Ekspor',
                        laporan: true,
                        eksporBesar: true,
                        query: queryEkspor,
                    }}
                    kosong={kosong}
                />
            );
        case 'outlet':
            return (
                <TabelData
                    id="laporan-penjualan-outlet"
                    label="Perbandingan outlet"
                    kolom={kolomOutlet}
                    sumber={{ mode: 'lokal', data: isi as BarisOutletLaporan[] }}
                    ambilIdBaris={(b) => b.Kunci}
                    urutBawaan="-Bersih"
                    cari={false}
                    ekspor={{
                        alamat: `${alamat}/ekspor`,
                        label: 'Ekspor',
                        laporan: true,
                        eksporBesar: true,
                        query: queryEkspor,
                    }}
                    kosong={kosong}
                />
            );
        case 'prediksi': {
            const ramal = isi as IsiPrediksi;

            if (!ramal.Cukup) {
                return (
                    <Pemberitahuan jenis="info">
                        Data penjualan belum cukup untuk memperkirakan. Dibutuhkan riwayat penjualan minimal 14 hari
                        (saat ini {String(ramal.HariData)} hari).
                    </Pemberitahuan>
                );
            }

            return (
                <div className="flex flex-col gap-3">
                    <p className="max-w-3xl text-keterangan text-teks-sekunder">
                        Perkiraan = rata-rata penjualan bersih pada hari yang sama dalam minggu selama 8 minggu terakhir
                        (hari tutup dihitung nol) × faktor tren {ramal.FaktorTren.replace('.', ',')} (penjualan 4 minggu
                        terakhir dibanding 4 minggu sebelumnya, dibatasi 0,80 sampai 1,25). Ini perkiraan statistik
                        sederhana, bukan jaminan; promo, cuaca, dan hari libur belum diperhitungkan.
                    </p>
                    <dl className="grid grid-cols-2 gap-3 rounded-panel border border-garis bg-permukaan p-4 md:max-w-xl">
                        <div>
                            <dt className="text-label text-teks-sekunder">
                                Perkiraan {String(ramal.Baris.length)} hari
                            </dt>
                            <dd className="text-subjudul font-semibold tabular-nums text-teks-utama">
                                {FormatRupiah(ramal.TotalPerkiraan)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-label text-teks-sekunder">Riwayat dipakai</dt>
                            <dd className="text-subjudul font-semibold tabular-nums text-teks-utama">
                                {String(Math.min(ramal.HariData, 56))} hari
                            </dd>
                        </div>
                    </dl>
                    <TabelData
                        id="laporan-penjualan-prediksi"
                        label="Prediksi penjualan harian"
                        kolom={kolomPrediksi}
                        sumber={{ mode: 'lokal', data: ramal.Baris }}
                        ambilIdBaris={(b) => b.Tanggal}
                        urutBawaan="Tanggal"
                        cari={false}
                        ekspor={{
                            alamat: `${alamat}/ekspor`,
                            label: 'Ekspor',
                            laporan: true,
                            eksporBesar: true,
                            query: queryEkspor,
                        }}
                        kosong={kosong}
                    />
                </div>
            );
        }
        case 'bundling': {
            const bundling = isi as IsiBundling;

            return (
                <div className="flex flex-col gap-3">
                    <p className="max-w-3xl text-keterangan text-teks-sekunder">
                        Pasangan produk yang dibeli dalam satu transaksi (minimal 3 kali) dari{' '}
                        {String(bundling.JumlahTransaksi)} transaksi pada periode ini. Lift di atas 1 berarti keduanya
                        dibeli bersama lebih sering daripada kebetulan. Saran harga paket 95% dari harga normal,
                        dibulatkan ke bawah ke kelipatan Rp 500.
                    </p>
                    {bundling.Terpotong ? (
                        <Pemberitahuan jenis="peringatan">
                            Transaksi pada periode ini sangat banyak, sebagian tidak ikut dianalisis. Persempit periode
                            atau pilih satu outlet.
                        </Pemberitahuan>
                    ) : null}
                    <TabelData
                        id="laporan-penjualan-bundling"
                        label="Rekomendasi bundling"
                        kolom={kolomBundling}
                        sumber={{ mode: 'lokal', data: bundling.Baris }}
                        ambilIdBaris={(b) => b.Kunci}
                        urutBawaan="-Lift"
                        cari="Cari nama produk"
                        ekspor={{
                            alamat: `${alamat}/ekspor`,
                            label: 'Ekspor',
                            laporan: true,
                            eksporBesar: true,
                            query: queryEkspor,
                        }}
                        kosong={{
                            ilustrasi: true,
                            judul: 'Belum ada pasangan produk yang sering dibeli bersama pada periode ini.',
                        }}
                    />
                </div>
            );
        }
        case 'umpan-balik': {
            const umpan = isi as IsiUmpanBalik;
            const r = umpan.Ringkasan;

            return (
                <div className="flex flex-col gap-3">
                    <p className="max-w-3xl text-keterangan text-teks-sekunder">
                        Penilaian pelanggan dari struk digital (1 sampai 5). Tingkat respons = penilaian masuk dibanding
                        jumlah transaksi pada periode. Pelanggan yang tidak membuka struk digital tidak ikut menilai.
                    </p>
                    <dl className="grid grid-cols-2 gap-3 rounded-panel border border-garis bg-permukaan p-4 md:grid-cols-4">
                        <div>
                            <dt className="text-label text-teks-sekunder">Rata-rata nilai</dt>
                            <dd className="text-subjudul font-semibold tabular-nums text-teks-utama">
                                {r.Jumlah === 0 ? '–' : `${r.RataRata.replace('.', ',')} dari 5`}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-label text-teks-sekunder">Penilaian masuk</dt>
                            <dd className="text-subjudul font-semibold tabular-nums text-teks-utama">
                                {String(r.Jumlah)} dari {String(r.JumlahTransaksi)} transaksi
                            </dd>
                        </div>
                        <div>
                            <dt className="text-label text-teks-sekunder">Tingkat respons</dt>
                            <dd className="text-subjudul font-semibold tabular-nums text-teks-utama">
                                {FormatPersen(r.TingkatRespons)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-label text-teks-sekunder">Sebaran 5 | 4 | 3 | 2 | 1</dt>
                            <dd className="text-subjudul font-semibold tabular-nums text-teks-utama">
                                {(['5', '4', '3', '2', '1'] as const).map((n) => String(r.PerNilai[n])).join(' | ')}
                            </dd>
                        </div>
                    </dl>
                    <TabelData
                        id="laporan-penjualan-umpan-balik"
                        label="Umpan balik pelanggan"
                        kolom={kolomUmpanBalik}
                        sumber={{ mode: 'lokal', data: umpan.Baris }}
                        ambilIdBaris={(b) => b.Kunci}
                        urutBawaan="-Waktu"
                        cari="Cari komentar atau nomor transaksi"
                        ekspor={{
                            alamat: `${alamat}/ekspor`,
                            label: 'Ekspor',
                            laporan: true,
                            eksporBesar: true,
                            query: queryEkspor,
                        }}
                        kosong={{
                            ilustrasi: true,
                            judul: 'Belum ada penilaian pelanggan pada periode dan saring ini.',
                        }}
                    />
                </div>
            );
        }
        case 'performa-kasir':
            return (
                <TabelData
                    id="laporan-penjualan-performa-kasir"
                    label="Performa kasir"
                    kolom={kolomPerformaKasir}
                    sumber={{ mode: 'lokal', data: isi as BarisPerformaKasir[] }}
                    ambilIdBaris={(b) => b.Kunci}
                    urutBawaan="-Bersih"
                    cari="Cari nama kasir"
                    ekspor={{
                        alamat: `${alamat}/ekspor`,
                        label: 'Ekspor',
                        laporan: true,
                        eksporBesar: true,
                        query: queryEkspor,
                    }}
                    kosong={kosong}
                />
            );
        case 'waktu-saji':
            return (
                <div className="flex flex-col gap-3">
                    <p className="max-w-3xl text-keterangan text-teks-sekunder">
                        Dari tiket dapur yang sudah siap. Total = dikirim ke dapur sampai siap; tunggu dan masak hanya
                        terisi bila KDS menandai tahap Dimasak. Persentil 90 berarti 9 dari 10 tiket selesai lebih cepat
                        dari angka itu.
                    </p>
                    <TabelData
                        id="laporan-penjualan-waktu-saji"
                        label="Waktu saji dapur per stasiun"
                        kolom={kolomWaktuSaji}
                        sumber={{ mode: 'lokal', data: isi as BarisWaktuSaji[] }}
                        ambilIdBaris={(b) => b.Kunci}
                        urutBawaan="-JumlahTiket"
                        cari="Cari stasiun"
                        ekspor={{
                            alamat: `${alamat}/ekspor`,
                            label: 'Ekspor',
                            laporan: true,
                            eksporBesar: true,
                            query: queryEkspor,
                        }}
                        kosong={{
                            ilustrasi: true,
                            judul: 'Belum ada tiket dapur yang selesai pada periode dan saring ini.',
                        }}
                    />
                </div>
            );
        case 'food-cost': {
            const food = isi as IsiFoodCost;
            const r = food.Ringkasan;

            return (
                <div className="flex flex-col gap-3">
                    <p className="max-w-3xl text-keterangan text-teks-sekunder">
                        Teoretis = nilai bahan yang seharusnya terpakai menurut penjualan dan produksi (resep × jumlah
                        terjual). Aktual = teoretis + bahan terbuang + selisih stok dari penyesuaian dan opname. Selisih
                        positif berarti kerugian. Persentase terhadap penjualan bersih periode.
                    </p>
                    <dl className="grid grid-cols-2 gap-3 rounded-panel border border-garis bg-permukaan p-4 md:grid-cols-4">
                        {(
                            [
                                ['Penjualan bersih', FormatRupiah(r.Penjualan)],
                                [
                                    'Food cost teoretis',
                                    `${FormatRupiah(r.Teoretis)} (${FormatPersen(r.TeoretisPersen)})`,
                                ],
                                ['Food cost aktual', `${FormatRupiah(r.Aktual)} (${FormatPersen(r.AktualPersen)})`],
                                ['Selisih aktual − teoretis', `${r.SelisihPoin.replace('.', ',')} poin persen`],
                                ['Bahan terbuang', FormatRupiah(r.Terbuang)],
                                ['Selisih stok (penyesuaian & opname)', FormatRupiah(r.Selisih)],
                            ] as [string, string][]
                        ).map(([label, nilai]) => (
                            <div key={label} className="min-w-0">
                                <dt className="text-label text-teks-sekunder">{label}</dt>
                                <dd className="text-isi font-semibold break-words text-teks-utama tabular-nums">
                                    {nilai}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    <TabelData
                        id="laporan-penjualan-food-cost"
                        label="Food cost per bahan"
                        kolom={kolomFoodCost}
                        sumber={{ mode: 'lokal', data: food.Baris }}
                        ambilIdBaris={(b) => b.Kunci}
                        cari="Cari bahan atau produk"
                        ekspor={{
                            alamat: `${alamat}/ekspor`,
                            label: 'Ekspor',
                            laporan: true,
                            eksporBesar: true,
                            query: queryEkspor,
                        }}
                        kosong={{ ilustrasi: true, judul: 'Belum ada pemakaian bahan pada periode dan saring ini.' }}
                    />
                </div>
            );
        }
        case 'metode':
            return (
                <TabelData
                    id="laporan-penjualan-metode"
                    label="Penjualan per metode bayar"
                    kolom={kolomMetode}
                    sumber={{ mode: 'lokal', data: isi as BarisMetodeLaporan[] }}
                    ambilIdBaris={(b) => b.Kunci}
                    urutBawaan="-Bersih"
                    cari="Cari metode bayar"
                    ekspor={{
                        alamat: `${alamat}/ekspor`,
                        label: 'Ekspor',
                        laporan: true,
                        eksporBesar: true,
                        query: queryEkspor,
                    }}
                    kosong={kosong}
                />
            );
        case 'diskon':
            return (
                <TabelData
                    id="laporan-penjualan-diskon"
                    label="Diskon per kasir"
                    kolom={kolomDiskon}
                    sumber={{ mode: 'lokal', data: isi as BarisDiskonLaporan[] }}
                    ambilIdBaris={(b) => b.Kunci}
                    urutBawaan="-TotalDiskon"
                    cari="Cari nama kasir"
                    ekspor={{
                        alamat: `${alamat}/ekspor`,
                        label: 'Ekspor',
                        laporan: true,
                        eksporBesar: true,
                        query: queryEkspor,
                    }}
                    kosong={{
                        ilustrasi: true,
                        judul: 'Belum ada penjualan berdiskon pada periode dan saring ini.',
                    }}
                />
            );
        case 'void-item':
            return (
                <>
                    <p className="max-w-3xl text-keterangan text-teks-sekunder">
                        Item pesanan meja yang sudah dikirim ke dapur lalu dibatalkan, terbaru dulu (paling banyak 200
                        baris; persempit periode untuk melihat yang lebih lama). Nilai adalah petunjuk kerugian, bukan
                        angka akuntansi. &quot;Dicatat terbuang&quot; berarti bahan sudah dikurangi dari stok sebagai
                        bahan terbuang karena item sempat dibuat.
                    </p>
                    <TabelData
                        id="laporan-penjualan-void-item"
                        label="Void item pesanan meja"
                        kolom={kolomVoidItem}
                        sumber={{ mode: 'lokal', data: isi as BarisVoidItemLaporan[] }}
                        ambilIdBaris={(b) => b.Kunci}
                        urutBawaan="-Waktu"
                        cari="Cari item, alasan, atau nomor pesanan"
                        ekspor={{
                            alamat: `${alamat}/ekspor`,
                            label: 'Ekspor',
                            laporan: true,
                            eksporBesar: true,
                            query: queryEkspor,
                        }}
                        kosong={{
                            ilustrasi: true,
                            judul: 'Tidak ada void item pada periode dan saring ini.',
                        }}
                    />
                </>
            );
        case 'anti-fraud':
            return (
                <>
                    <p className="max-w-3xl text-keterangan text-teks-sekunder">
                        Skor risiko adalah petunjuk untuk diperiksa, bukan bukti. Dibandingkan dengan rata-rata kasir
                        lain pada periode yang sama: void tunai ≤ 10 menit setelah bayar, rasio void/diskon/retur
                        minimal 2× rata-rata, buka laci tanpa transaksi, dan kas kurang saat tutup shift.
                    </p>
                    <TabelData
                        id="laporan-penjualan-anti-fraud"
                        label="Anti-fraud per kasir"
                        kolom={kolomAntiFraud}
                        sumber={{ mode: 'lokal', data: isi as BarisAntiFraudLaporan[] }}
                        ambilIdBaris={(b) => b.Kunci}
                        urutBawaan="-Skor"
                        cari="Cari nama kasir"
                        ekspor={{
                            alamat: `${alamat}/ekspor`,
                            label: 'Ekspor',
                            laporan: true,
                            eksporBesar: true,
                            query: queryEkspor,
                        }}
                        kosong={{
                            ilustrasi: true,
                            judul: 'Belum ada transaksi kasir pada periode dan saring ini.',
                        }}
                    />
                </>
            );
        case 'abc': {
            const abc = isi as IsiAbc;

            return (
                <div className="flex flex-col gap-3">
                    <p className="max-w-3xl text-keterangan text-teks-sekunder">
                        Produk diurutkan dari penjualan bersih terbesar. Kelas A menyumbang ±80% penjualan, B sampai
                        95%, dan C sisanya. Produk tanpa penjualan bersih tidak ditampilkan.
                    </p>
                    <dl className="grid gap-3 rounded-panel border border-garis bg-permukaan p-4 md:grid-cols-3">
                        {(['A', 'B', 'C'] as const).map((k) => (
                            <div key={k} className="min-w-0">
                                <dt className="text-label font-semibold text-teks-utama">
                                    Kelas {k} | {String(abc.Ringkasan[k].Jumlah)} produk
                                </dt>
                                <dd className="text-subjudul font-semibold text-teks-utama tabular-nums">
                                    {FormatRupiah(abc.Ringkasan[k].Bersih)}
                                </dd>
                                <dd className="text-keterangan text-teks-sekunder">{keteranganAbc[k]}</dd>
                            </div>
                        ))}
                    </dl>
                    <TabelData
                        id="laporan-penjualan-abc"
                        label="Analisis ABC produk"
                        kolom={kolomAbc}
                        sumber={{ mode: 'lokal', data: abc.Baris }}
                        ambilIdBaris={(b) => String(b.IdProduk)}
                        cari="Cari nama produk"
                        ekspor={{
                            alamat: `${alamat}/ekspor`,
                            label: 'Ekspor',
                            laporan: true,
                            eksporBesar: true,
                            query: queryEkspor,
                        }}
                        kosong={{ ilustrasi: true, judul: 'Belum ada penjualan bersih pada periode dan saring ini.' }}
                    />
                </div>
            );
        }
        case 'menu': {
            const menu = isi as IsiMenu;

            return (
                <div className="flex flex-col gap-3">
                    <p className="max-w-3xl text-keterangan text-teks-sekunder">
                        Menu engineering (Kasavana & Smith) memetakan produk dari dua sisi: laris bila porsi qty-nya
                        minimal {FormatPersen(menu.BatasPorsiQty)} (70% dari rata-rata), dan untung besar bila margin
                        per unit (bersih − HPP) minimal rata-rata tertimbang {FormatRupiah(menu.RataRataMargin)}.
                    </p>
                    <dl className="grid gap-3 rounded-panel border border-garis bg-permukaan p-4 md:grid-cols-2">
                        {(['Star', 'Plowhorse', 'Puzzle', 'Dog'] as const).map((k) => (
                            <div key={k} className="min-w-0">
                                <dt className="flex flex-wrap items-center gap-2 text-label font-semibold text-teks-utama">
                                    <LabelStatus jenis={infoKelasMenu[k].jenis} teks={infoKelasMenu[k].label} />
                                    {String(menu.Baris.filter((b) => b.Kelas === k).length)} produk ({k})
                                </dt>
                                <dd className="text-keterangan text-teks-sekunder">{infoKelasMenu[k].saran}</dd>
                            </div>
                        ))}
                    </dl>
                    <TabelData
                        id="laporan-penjualan-menu"
                        label="Menu engineering produk"
                        kolom={kolomMenu}
                        sumber={{ mode: 'lokal', data: menu.Baris }}
                        ambilIdBaris={(b) => String(b.IdProduk)}
                        cari="Cari nama produk"
                        ekspor={{
                            alamat: `${alamat}/ekspor`,
                            label: 'Ekspor',
                            laporan: true,
                            eksporBesar: true,
                            query: queryEkspor,
                        }}
                        kosong={{ ilustrasi: true, judul: 'Belum ada penjualan bersih pada periode dan saring ini.' }}
                    />
                </div>
            );
        }
        default:
            return (
                <TabelData
                    id="laporan-penjualan-harian"
                    label="Ringkasan penjualan harian"
                    kolom={kolomHarian}
                    sumber={{ mode: 'lokal', data: isi as BarisHarian[] }}
                    ambilIdBaris={(b) => b.Tanggal}
                    urutBawaan="Tanggal"
                    cari={false}
                    ekspor={{
                        alamat: `${alamat}/ekspor`,
                        label: 'Ekspor',
                        laporan: true,
                        eksporBesar: true,
                        query: queryEkspor,
                    }}
                    kosong={kosong}
                />
            );
    }
}

/**
 * F-14a laporan penjualan: saring periode (maks. 92 hari), outlet, kasir, kanal; tab ringkasan harian, per produk,
 * kategori, jam (heatmap), kasir, kanal, metode bayar, diskon, anti-fraud, serta X6 analisis ABC & menu engineering. Void dikeluarkan; retur mengurangi pada tanggal
 * returnya. Ekspor CSV mengikuti saring.
 */
export default function HalamanLaporanPenjualan(props: PropsLaporanPenjualan) {
    // D-48: Menu engineering hanya bermakna untuk usaha makanan & minuman (atau bila tab itu sedang dibuka).
    const sektorFnb = PakaiSektor(['FNB']);
    const tabFnb = ['menu', 'waktu-saji', 'food-cost'];
    // D-111: tab Anti-fraud adalah fitur paket (`keamanan.anti-fraud`); server menolaknya di luar paket, jadi tab disembunyikan.
    const antiFraudTerkunci = PakaiFiturTerkunci('keamanan.anti-fraud');
    // D-111: prediksi & rekomendasi bundling termasuk fitur Forecast & insight (`laporan.insight`).
    const insightTerkunci = PakaiFiturTerkunci('laporan.insight');
    const tabTerlihat = daftarTab.filter(
        (tab) =>
            (!tabFnb.includes(tab.nilai) || sektorFnb || props.Saring.Tab === tab.nilai) &&
            (tab.nilai !== 'anti-fraud' || !antiFraudTerkunci) &&
            (!['prediksi', 'bundling'].includes(tab.nilai) || !insightTerkunci),
    );
    const { Saring, Total } = props;
    const query = {
        dari: Saring.Dari,
        sampai: Saring.Sampai,
        outlet: Saring.Outlet,
        kasir: Saring.Kasir,
        kanal: Saring.Kanal,
        ...(Saring.Tab === 'waktu-saji' ? { target: String(Saring.Target ?? 15) } : {}),
    };
    const ringkasan: [string, string][] = [
        ['Penjualan bersih', FormatRupiah(Total.Bersih)],
        ['Laba kotor', FormatRupiah(Total.LabaKotor)],
        ['Transaksi', String(Total.JumlahTransaksi)],
        ['Rata-rata keranjang', FormatRupiah(Total.RataRataKeranjang)],
        ['Kotor', FormatRupiah(Total.Kotor)],
        ['Diskon', FormatRupiah(Total.Diskon)],
        ['Retur', FormatRupiah(Total.Retur)],
        ['Pajak', FormatRupiah(Total.Pajak)],
    ];

    return (
        <TataLetakAplikasi judul="Laporan penjualan">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Angka dihitung dari penjualan yang sudah diterima server. Penjualan yang di-void tidak dihitung; retur
                mengurangi penjualan pada tanggal returnya. Bersih = kotor − diskon − retur, di luar pajak dan biaya
                layanan.
            </p>

            <SaringLaporan
                alamat={alamat}
                query={{ ...query, tab: Saring.Tab }}
                maksHari={props.MaksHari}
                pilihan={[
                    ...(props.OpsiOutlet.length > 1
                        ? [{ kunci: 'outlet', label: 'Outlet', kosong: 'Semua outlet', opsi: props.OpsiOutlet }]
                        : []),
                    { kunci: 'kasir', label: 'Kasir', kosong: 'Semua kasir', opsi: props.OpsiKasir },
                    { kunci: 'kanal', label: 'Kanal', kosong: 'Semua kanal', opsi: props.OpsiKanal },
                    ...(Saring.Tab === 'waktu-saji'
                        ? [
                              {
                                  kunci: 'target',
                                  label: 'Target waktu saji',
                                  kosong: '15 menit',
                                  opsi: (props.OpsiTarget ?? [15]).map((m) => ({
                                      Nilai: String(m),
                                      Label: `${String(m)} menit`,
                                  })),
                              },
                          ]
                        : []),
                ]}
            />

            {props.Peringatan ? <Pemberitahuan jenis="peringatan">{props.Peringatan}</Pemberitahuan> : null}

            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 rounded-panel border border-garis bg-permukaan p-4 md:grid-cols-4">
                {ringkasan.map(([label, nilai]) => (
                    <div key={label} className="min-w-0">
                        <dt className="text-label text-teks-sekunder">{label}</dt>
                        <dd className="text-subjudul font-semibold break-words text-teks-utama tabular-nums">
                            {nilai}
                        </dd>
                    </div>
                ))}
            </dl>

            <div className="flex flex-col gap-3">
                <NavigasiTab
                    label="Jenis laporan penjualan"
                    alamat={alamat}
                    query={query}
                    tabAktif={Saring.Tab}
                    tab={tabTerlihat}
                />
                <IsiTab
                    key={`${Saring.Tab}-${JSON.stringify(query)}`}
                    tab={Saring.Tab}
                    isi={props.Isi}
                    queryEkspor={BuatQueryLaporan({ ...query, tab: Saring.Tab })}
                />
            </div>
            {props.InsightWhatsapp ? <PilihanInsightWhatsapp insight={props.InsightWhatsapp} /> : null}
        </TataLetakAplikasi>
    );
}
