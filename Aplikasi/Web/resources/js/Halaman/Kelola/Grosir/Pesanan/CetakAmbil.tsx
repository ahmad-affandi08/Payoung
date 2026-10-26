import BingkaiCetakGrosir, {
    AlamatCetak,
    AlamatPembeliCetak,
    AlamatPenjualCetak,
    BarisKepalaCetak,
} from '@/Komponen/Grosir/BingkaiCetakGrosir';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { PropsCetakAmbilBarang } from '@/Tipe/Grosir';

/**
 * Daftar ambil barang (*picking list*) dari satu pesanan grosir (F-12, §9.7). Dokumen tersendiri, bukan status draf
 * surat jalan: §9.7 sudah memutuskan surat jalan selalu tercatat, karena draf yang stoknya belum berkurang membuat
 * kartu stok berbohong. Yang dicetak di sini **sisa yang belum dikirim**, supaya pesanan bertahap tidak membuat petugas
 * mengambil ulang barang yang sudah keluar minggu lalu.
 *
 * Tanpa harga, dan kolom "Diambil" dibiarkan kosong untuk diisi tangan di gudang.
 */
export default function HalamanCetakAmbilBarang({ Pesanan, Baris, Usaha }: PropsCetakAmbilBarang) {
    return (
        <BingkaiCetakGrosir
            judulDokumen="Daftar Ambil Barang"
            judulTab={`Ambil barang ${Pesanan.Nomor}`}
            nomor={Pesanan.Nomor}
            usaha={Usaha}
            dibatalkan={Pesanan.Status === 'Dibatalkan'}
            alasanBatal={null}
            kepala={
                <>
                    <BarisKepalaCetak label="Pesanan">{FormatTanggal(Pesanan.Tanggal)}</BarisKepalaCetak>
                    {Pesanan.TanggalKirimDiminta ? (
                        <BarisKepalaCetak label="Diminta kirim">
                            {FormatTanggal(Pesanan.TanggalKirimDiminta)}
                        </BarisKepalaCetak>
                    ) : null}
                    <BarisKepalaCetak label="Status">{Pesanan.LabelStatus}</BarisKepalaCetak>
                </>
            }
            tandaTangan={[{ label: 'Diambil oleh' }, { label: 'Diperiksa oleh' }]}
        >
            <section className="grid gap-4 sm:grid-cols-2">
                <AlamatPembeliCetak judul="Untuk pesanan" pelanggan={Pesanan.Pelanggan} />
                <AlamatPenjualCetak judul="Diambil di" outlet={Pesanan.Outlet} />
            </section>

            {Baris.length === 0 ? (
                <p className="text-isi text-teks-sekunder">
                    Seluruh barang pesanan ini sudah dikirim. Tidak ada yang perlu diambil lagi.
                </p>
            ) : (
                <Table className="text-left">
                    <TableHeader>
                        <TableRow>
                            <TableHead scope="col" className="w-10">
                                No
                            </TableHead>
                            <TableHead scope="col">Barang</TableHead>
                            <TableHead scope="col" className="text-right">
                                Dipesan
                            </TableHead>
                            <TableHead scope="col" className="text-right">
                                Sudah dikirim
                            </TableHead>
                            <TableHead scope="col" className="text-right">
                                Perlu diambil
                            </TableHead>
                            <TableHead scope="col" className="w-28 text-right">
                                Diambil
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
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {FormatJumlahStok(b.Jumlah, b.SimbolSatuan)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {FormatJumlahStok(b.JumlahTerkirim, b.SimbolSatuan)}
                                </TableCell>
                                <TableCell className="text-right font-semibold tabular-nums">
                                    {FormatJumlahStok(b.SisaKirim, b.SimbolSatuan)}
                                </TableCell>
                                <TableCell aria-label="Diisi tangan di gudang">
                                    <span className="block border-b border-garis pt-4" />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}

            <p className="text-keterangan text-teks-sekunder">
                Daftar ini belum memotong stok. Stok baru berkurang saat surat jalannya dicatat, jadi barang yang
                diambil tetap harus dicatat di surat jalan sebelum meninggalkan gudang.
            </p>

            {Pesanan.Catatan ? (
                <AlamatCetak judul="Catatan pesanan">
                    <p className="whitespace-pre-line">{Pesanan.Catatan}</p>
                </AlamatCetak>
            ) : null}
        </BingkaiCetakGrosir>
    );
}
