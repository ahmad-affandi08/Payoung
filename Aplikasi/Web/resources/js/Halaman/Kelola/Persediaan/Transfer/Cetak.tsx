import BingkaiCetakGrosir, { AlamatCetak, BarisKepalaCetak } from '@/Komponen/Grosir/BingkaiCetakGrosir';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { PropsCetakTransferStok } from '@/Tipe/DokumenPersediaan';

/**
 * Cetak surat jalan transfer stok (F-05b, D-94): dokumen pendamping barang yang berpindah antar gudang atau outlet milik
 * usaha yang sama. **Tanpa nilai**: pembawa dan petugas gudang tujuan hanya mencocokkan barang & jumlah. Kolom
 * "Diterima" sengaja kosong untuk diisi tangan saat serah terima; selisihnya dicatat di aplikasi lewat Terima barang.
 *
 * Transfer yang dibatalkan tetap bisa dibuka tetapi dicetak dengan tanda DIBATALKAN besar, supaya cetakan lama tidak
 * dipakai menggerakkan barang lagi.
 */
export default function HalamanCetakTransferStok({ Transfer, Baris, Usaha }: PropsCetakTransferStok) {
    const nomor = Transfer.Nomor ?? '(belum bernomor)';

    return (
        <BingkaiCetakGrosir
            judulDokumen="Surat Jalan Transfer"
            judulTab={`Surat jalan transfer ${nomor}`}
            nomor={nomor}
            usaha={Usaha}
            dibatalkan={Transfer.Status === 'Dibatalkan'}
            alasanBatal={Transfer.AlasanBatal}
            kepala={<BarisKepalaCetak label="Tanggal">{FormatTanggal(Transfer.Tanggal)}</BarisKepalaCetak>}
            tandaTangan={[
                { label: 'Pengirim', nama: Transfer.NamaPengirim },
                { label: 'Pembawa' },
                { label: 'Penerima' },
            ]}
        >
            <section className="grid gap-4 sm:grid-cols-2">
                <AlamatCetak judul="Dikirim dari">
                    <p className="font-semibold">{Transfer.NamaGudangAsal}</p>
                    {Transfer.NamaOutletAsal ? <p>{Transfer.NamaOutletAsal}</p> : null}
                </AlamatCetak>
                <AlamatCetak judul="Dikirim ke">
                    <p className="font-semibold">{Transfer.NamaGudangTujuan}</p>
                    {Transfer.NamaOutletTujuan ? <p>{Transfer.NamaOutletTujuan}</p> : null}
                </AlamatCetak>
            </section>

            <Table className="text-left">
                <TableHeader>
                    <TableRow>
                        <TableHead scope="col" className="w-10">
                            No
                        </TableHead>
                        <TableHead scope="col">Barang</TableHead>
                        <TableHead scope="col" className="text-right">
                            Dikirim
                        </TableHead>
                        <TableHead scope="col" className="w-32 text-right">
                            Diterima
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
                                {b.NomorBatch ? (
                                    <span className="block text-keterangan text-teks-sekunder">
                                        Batch {b.NomorBatch}
                                        {b.TanggalKedaluwarsa
                                            ? ` | kedaluwarsa ${FormatTanggal(b.TanggalKedaluwarsa)}`
                                            : ''}
                                    </span>
                                ) : null}
                                {b.NomorSeri ? (
                                    <span className="block font-mono text-keterangan">No. seri {b.NomorSeri}</span>
                                ) : null}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {FormatJumlahStok(b.Jumlah, b.SimbolSatuan)}
                            </TableCell>
                            <TableCell className="text-right">
                                <span className="inline-block h-6 w-24 border-b border-garis-input" aria-hidden />
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>

            <p className="text-keterangan text-teks-sekunder">
                Surat jalan ini memindahkan barang antar lokasi milik usaha yang sama, bukan tagihan, dan tidak memuat
                nilai barang. Hitung barang saat serah terima; selisih dicatat di aplikasi lewat Terima barang.
            </p>

            {Transfer.Catatan ? (
                <AlamatCetak judul="Catatan">
                    <p className="whitespace-pre-line">{Transfer.Catatan}</p>
                </AlamatCetak>
            ) : null}
        </BingkaiCetakGrosir>
    );
}
