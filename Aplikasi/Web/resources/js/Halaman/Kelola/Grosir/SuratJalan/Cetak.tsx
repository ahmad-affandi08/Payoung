import BingkaiCetakGrosir, {
    AlamatCetak,
    AlamatPembeliCetak,
    AlamatPenjualCetak,
    BarisKepalaCetak,
} from '@/Komponen/Grosir/BingkaiCetakGrosir';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { PropsCetakSuratJalan } from '@/Tipe/Grosir';

/**
 * Cetak surat jalan grosir (F-12, §9.7, BR-12.2). **Tanpa harga**: kertas ini dipegang sopir dan dibaca petugas gudang
 * pembeli, dan yang mereka cocokkan cuma barang & jumlah. Tagihannya ada di faktur (BR-12.4).
 *
 * Surat jalan yang sudah dibatalkan tetap bisa dibuka, tetapi dicetak dengan tanda DIBATALKAN besar — cetakan ulang
 * yang tampak sah adalah cara termudah barang keluar dua kali atas satu dokumen.
 */
export default function HalamanCetakSuratJalan({ SuratJalan, Baris, Usaha }: PropsCetakSuratJalan) {
    return (
        <BingkaiCetakGrosir
            judulDokumen="Surat Jalan"
            judulTab={`Surat jalan ${SuratJalan.Nomor}`}
            nomor={SuratJalan.Nomor}
            usaha={Usaha}
            dibatalkan={SuratJalan.Status === 'Dibatalkan'}
            alasanBatal={SuratJalan.AlasanBatal}
            kepala={
                <>
                    <BarisKepalaCetak label="Tanggal">{FormatTanggal(SuratJalan.Tanggal)}</BarisKepalaCetak>
                    {SuratJalan.NomorPesanan ? (
                        <BarisKepalaCetak label="Pesanan">{SuratJalan.NomorPesanan}</BarisKepalaCetak>
                    ) : null}
                </>
            }
            tandaTangan={[
                { label: 'Petugas gudang', nama: SuratJalan.NamaPengirim },
                { label: 'Pengemudi' },
                { label: 'Penerima', nama: SuratJalan.NamaPenerima },
            ]}
        >
            <section className="grid gap-4 sm:grid-cols-2">
                <AlamatPembeliCetak judul="Dikirim kepada" pelanggan={SuratJalan.Pelanggan} />
                <div className="flex flex-col gap-4">
                    <AlamatPenjualCetak judul="Dikirim dari" outlet={SuratJalan.Outlet} />
                    <AlamatCetak judul="Kendaraan">
                        <p>{SuratJalan.NomorKendaraan ?? '—'}</p>
                        <p className="text-teks-sekunder">Lokasi stok: {SuratJalan.NamaGudang}</p>
                    </AlamatCetak>
                </div>
            </section>

            <Table className="text-left">
                <TableHeader>
                    <TableRow>
                        <TableHead scope="col" className="w-10">
                            No
                        </TableHead>
                        <TableHead scope="col">Barang</TableHead>
                        <TableHead scope="col" className="text-right">
                            Jumlah
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {Baris.map((b) => (
                        <TableRow key={b.Urutan}>
                            <TableCell className="tabular-nums">{b.Urutan}</TableCell>
                            <TableCell className="whitespace-normal">
                                {b.NamaProduk}
                                {b.Sku ? <span className="block font-mono text-keterangan">{b.Sku}</span> : null}
                                {b.Rincian ? <span className="block text-keterangan">{b.Rincian}</span> : null}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {FormatJumlahStok(b.Jumlah, b.SimbolSatuan)}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>

            <p className="text-keterangan text-teks-sekunder">
                Surat jalan ini bukan tagihan. Nilai barang beserta PPN-nya tercantum di faktur penjualan yang dikirim
                terpisah.
            </p>

            {SuratJalan.Catatan ? (
                <AlamatCetak judul="Catatan">
                    <p className="whitespace-pre-line">{SuratJalan.Catatan}</p>
                </AlamatCetak>
            ) : null}
        </BingkaiCetakGrosir>
    );
}
