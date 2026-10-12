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
import { PakaiFiturTerkunci } from '@/Pustaka/FiturPaket';
import { PakaiSektor } from '@/Pustaka/Sektor';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { BuatQueryLaporan } from '@/Pustaka/Laporan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type {
    BarisAbcLaporan,
    BarisAntiFraudLaporan,
    BarisDetailPenjualan,
    BarisDiskonLaporan,
    BarisHarian,
    BarisKanalLaporan,
    BarisKasirLaporan,
    BarisKategoriLaporan,
    BarisMenuLaporan,
    BarisMetodeLaporan,
    BarisProdukLaporan,
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
    { nilai: 'anti-fraud', label: 'Anti-fraud' },
    { nilai: 'abc', label: 'Analisis ABC' },
    { nilai: 'menu', label: 'Menu engineering' },
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
                    ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true }}
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
                    ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true }}
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
                    ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true, query: queryEkspor }}
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
                        ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true, query: queryEkspor }}
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
                    ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true, query: queryEkspor }}
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
                    ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true, query: queryEkspor }}
                    kosong={kosong}
                />
            );
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
                    ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true, query: queryEkspor }}
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
                    ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true, query: queryEkspor }}
                    kosong={{
                        ilustrasi: true,
                        judul: 'Belum ada penjualan berdiskon pada periode dan saring ini.',
                    }}
                />
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
                        ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true, query: queryEkspor }}
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
                        ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true, query: queryEkspor }}
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
                        ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true, query: queryEkspor }}
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
                    ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true, query: queryEkspor }}
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
    const tampilMenu = PakaiSektor(['FNB']) || props.Saring.Tab === 'menu';
    // D-111: tab Anti-fraud adalah fitur paket (`keamanan.anti-fraud`); server menolaknya di luar paket, jadi tab disembunyikan.
    const antiFraudTerkunci = PakaiFiturTerkunci('keamanan.anti-fraud');
    const tabTerlihat = daftarTab.filter(
        (tab) => (tab.nilai !== 'menu' || tampilMenu) && (tab.nilai !== 'anti-fraud' || !antiFraudTerkunci),
    );
    const { Saring, Total } = props;
    const query = {
        dari: Saring.Dari,
        sampai: Saring.Sampai,
        outlet: Saring.Outlet,
        kasir: Saring.Kasir,
        kanal: Saring.Kanal,
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
