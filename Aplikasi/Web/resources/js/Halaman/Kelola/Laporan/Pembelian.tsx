import { KolomBilangan, KolomQty, KolomUang } from '@/Komponen/Laporan/KolomLaporan';
import NavigasiTab from '@/Komponen/Laporan/NavigasiTab';
import SaringLaporan from '@/Komponen/Laporan/SaringLaporan';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { BuatQueryLaporan } from '@/Pustaka/Laporan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type {
    BarisPembelianPemasok,
    BarisPembelianProduk,
    BarisPesananOutstanding,
    PropsLaporanPembelian,
    TabLaporanPembelian,
} from '@/Tipe/Laporan';

const alamat = '/kelola/laporan/pembelian';

const daftarTab: { nilai: TabLaporanPembelian; label: string }[] = [
    { nilai: 'pemasok', label: 'Per pemasok' },
    { nilai: 'produk', label: 'Per produk' },
    { nilai: 'po', label: 'PO outstanding' },
];

const kolomPemasok: KolomTabel<BarisPembelianPemasok>[] = [
    {
        id: 'NamaPemasok',
        accessorKey: 'NamaPemasok',
        header: 'Pemasok',
        meta: { label: 'Pemasok', prioritas: 'utama', wajib: true },
    },
    KolomBilangan<BarisPembelianPemasok>('JumlahFaktur', 'Faktur', 'penting'),
    KolomUang<BarisPembelianPemasok>('Subtotal', 'DPP', 'rendah'),
    KolomUang<BarisPembelianPemasok>('Pajak', 'Pajak', 'rendah'),
    KolomUang<BarisPembelianPemasok>('Total', 'Total faktur', 'rendah'),
    KolomUang<BarisPembelianPemasok>('Retur', 'Retur', 'rendah'),
    KolomUang<BarisPembelianPemasok>('Bersih', 'Pembelian bersih', 'utama'),
    KolomUang<BarisPembelianPemasok>('Dibayar', 'Dibayar', 'rendah'),
    KolomUang<BarisPembelianPemasok>('SisaHutang', 'Sisa hutang', 'penting'),
];

const kolomProduk: KolomTabel<BarisPembelianProduk>[] = [
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
    },
    KolomQty<BarisPembelianProduk>('Qty', 'Jumlah (satuan dasar)'),
    KolomUang<BarisPembelianProduk>('Nilai', 'Nilai beli', 'rendah'),
    KolomUang<BarisPembelianProduk>('NilaiRetur', 'Retur', 'rendah'),
    KolomUang<BarisPembelianProduk>('Bersih', 'Bersih', 'utama'),
    KolomUang<BarisPembelianProduk>('HargaRata', 'Harga rata-rata', 'penting'),
    KolomUang<BarisPembelianProduk>('HargaMin', 'Terendah', 'rendah'),
    KolomUang<BarisPembelianProduk>('HargaMax', 'Tertinggi', 'rendah'),
    KolomBilangan<BarisPembelianProduk>('JumlahPemasok', 'Pemasok', 'rendah'),
];

const kolomPesanan: KolomTabel<BarisPesananOutstanding>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'No PO',
        meta: { label: 'No PO', prioritas: 'utama', wajib: true, kelasSel: 'font-mono whitespace-nowrap' },
    },
    {
        id: 'NamaPemasok',
        accessorKey: 'NamaPemasok',
        header: 'Pemasok',
        meta: { label: 'Pemasok', prioritas: 'utama' },
    },
    {
        id: 'PerkiraanTiba',
        accessorKey: 'PerkiraanTiba',
        header: 'Perkiraan tiba',
        meta: { label: 'Perkiraan tiba', prioritas: 'utama', kelasSel: 'whitespace-nowrap' },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block">{b.PerkiraanTiba ? FormatTanggal(b.PerkiraanTiba) : '–'}</span>
                {b.HariTerlambat > 0 ? (
                    <LabelStatus jenis="bahaya" teks={`Terlambat ${String(b.HariTerlambat)} hari`} />
                ) : null}
            </>
        ),
    },
    {
        id: 'LabelStatus',
        accessorKey: 'LabelStatus',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
    },
    KolomBilangan<BarisPesananOutstanding>('JumlahBarisSisa', 'Baris belum lengkap', 'penting'),
    KolomUang<BarisPesananOutstanding>('Total', 'Total PO', 'rendah'),
    KolomUang<BarisPesananOutstanding>('NilaiSisa', 'Nilai sisa', 'utama'),
    KolomBilangan<BarisPesananOutstanding>('UmurHari', 'Umur (hari)', 'rendah'),
];

/**
 * §10.10 laporan pembelian (izin kelola pembelian): pembelian per pemasok dan per produk pada periode (tanggal faktur,
 * faktur batal tidak dihitung) serta pesanan pembelian yang masih menunggu barang. Hutang dan umurnya ada di Pembelian ›
 * Hutang pemasok.
 */
export default function HalamanLaporanPembelian({
    Saring,
    Peringatan,
    MaksHari,
    OpsiOutlet,
    Isi,
}: PropsLaporanPembelian) {
    const query = { dari: Saring.Dari, sampai: Saring.Sampai, outlet: Saring.Outlet };
    const queryEkspor = BuatQueryLaporan({ ...query, tab: Saring.Tab });
    const ekspor = {
        alamat: `${alamat}/ekspor`,
        label: 'Ekspor',
        laporan: true,
        eksporBesar: true,
        query: queryEkspor,
    };

    return (
        <TataLetakAplikasi judul="Laporan pembelian">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                {Saring.Tab === 'po'
                    ? 'Pesanan pembelian yang sudah disetujui dan masih menunggu barang, keadaan saat ini (periode tidak berlaku). Nilai sisa dihitung dari harga pesanan untuk barang yang belum diterima.'
                    : 'Dihitung dari faktur pembelian pada periode (tanggal faktur). Faktur yang dibatalkan tidak dihitung. Pembelian bersih = total faktur − retur. Nilai beli per produk di luar pajak dan ongkir.'}
            </p>

            <SaringLaporan
                alamat={alamat}
                query={{ ...query, tab: Saring.Tab }}
                maksHari={MaksHari}
                pilihan={
                    OpsiOutlet.length > 1
                        ? [{ kunci: 'outlet', label: 'Outlet', kosong: 'Semua outlet', opsi: OpsiOutlet }]
                        : []
                }
            />

            {Peringatan ? <Pemberitahuan jenis="peringatan">{Peringatan}</Pemberitahuan> : null}

            <div className="flex flex-col gap-3">
                <NavigasiTab
                    label="Jenis laporan pembelian"
                    alamat={alamat}
                    query={query}
                    tabAktif={Saring.Tab}
                    tab={daftarTab}
                />
                {Saring.Tab === 'produk' ? (
                    <TabelData
                        key={`produk-${JSON.stringify(query)}`}
                        id="laporan-pembelian-produk"
                        label="Pembelian per produk"
                        kolom={kolomProduk}
                        sumber={{ mode: 'lokal', data: Isi as BarisPembelianProduk[] }}
                        ambilIdBaris={(b) => b.Kunci}
                        urutBawaan="-Bersih"
                        cari="Cari nama produk"
                        ekspor={ekspor}
                        kosong={{ ilustrasi: true, judul: 'Belum ada pembelian produk pada periode ini.' }}
                    />
                ) : Saring.Tab === 'po' ? (
                    <TabelData
                        key={`po-${JSON.stringify(query)}`}
                        id="laporan-pembelian-po"
                        label="Pesanan pembelian outstanding"
                        kolom={kolomPesanan}
                        sumber={{ mode: 'lokal', data: Isi as BarisPesananOutstanding[] }}
                        ambilIdBaris={(b) => b.Kunci}
                        urutBawaan="PerkiraanTiba"
                        cari="Cari nomor PO atau pemasok"
                        alamatDetail={(b) => `/kelola/pembelian/pesanan/${b.Kunci}`}
                        ekspor={ekspor}
                        kosong={{ ilustrasi: true, judul: 'Tidak ada pesanan pembelian yang menunggu barang.' }}
                    />
                ) : (
                    <TabelData
                        key={`pemasok-${JSON.stringify(query)}`}
                        id="laporan-pembelian-pemasok"
                        label="Pembelian per pemasok"
                        kolom={kolomPemasok}
                        sumber={{ mode: 'lokal', data: Isi as BarisPembelianPemasok[] }}
                        ambilIdBaris={(b) => b.Kunci}
                        urutBawaan="-Bersih"
                        cari="Cari nama pemasok"
                        ekspor={ekspor}
                        kosong={{ ilustrasi: true, judul: 'Belum ada faktur pembelian pada periode ini.' }}
                    />
                )}
            </div>
        </TataLetakAplikasi>
    );
}
