import BingkaiCetakGrosir, { AlamatCetak, BarisKepalaCetak } from '@/Komponen/Grosir/BingkaiCetakGrosir';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { PropsCetakTransferStok } from '@/Tipe/DokumenPersediaan';

/**
 * Daftar ambil barang transfer stok (*picking list*, D-95): dipegang petugas gudang asal saat mengambil barang dari
 * rak sebelum dikirim. Dokumen tersendiri, bukan surat jalan: tanpa nilai, dengan kotak centang "Diambil", dan hanya
 * ada kolom petugas yang mengambil dan yang memeriksa. Surat jalan yang menyertai barang dicetak terpisah.
 */
export default function HalamanCetakAmbilTransferStok({ Transfer, Baris, Usaha }: PropsCetakTransferStok) {
    const nomor = Transfer.Nomor ?? '(draf, belum bernomor)';

    return (
        <BingkaiCetakGrosir
            judulDokumen="Daftar Ambil Barang"
            judulTab={`Daftar ambil barang ${nomor}`}
            nomor={nomor}
            usaha={Usaha}
            dibatalkan={false}
            alasanBatal={null}
            kepala={
                <>
                    <BarisKepalaCetak label="Tanggal">{FormatTanggal(Transfer.Tanggal)}</BarisKepalaCetak>
                    <BarisKepalaCetak label="Status">{Transfer.LabelStatus}</BarisKepalaCetak>
                </>
            }
            tandaTangan={[{ label: 'Petugas pengambil' }, { label: 'Pemeriksa' }]}
        >
            <section className="grid gap-4 sm:grid-cols-2">
                <AlamatCetak judul="Ambil dari">
                    <p className="font-semibold">{Transfer.NamaGudangAsal}</p>
                    {Transfer.NamaOutletAsal ? <p>{Transfer.NamaOutletAsal}</p> : null}
                </AlamatCetak>
                <AlamatCetak judul="Untuk dikirim ke">
                    <p className="font-semibold">{Transfer.NamaGudangTujuan}</p>
                    {Transfer.NamaOutletTujuan ? <p>{Transfer.NamaOutletTujuan}</p> : null}
                </AlamatCetak>
            </section>

            <Table className="text-left">
                <TableHeader>
                    <TableRow>
                        <TableHead scope="col" className="w-10">
                            Diambil
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
                            <TableCell>
                                <span className="inline-block size-5 border-2 border-garis-input" aria-hidden />
                            </TableCell>
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
                        </TableRow>
                    ))}
                </TableBody>
            </Table>

            <p className="text-keterangan text-teks-sekunder">
                Centang tiap barang setelah diambil dari rak. Ini hanya daftar kerja gudang: stok baru berpindah saat
                transfer dikirim, dan surat jalan yang menyertai barang dicetak terpisah.
            </p>

            {Transfer.Catatan ? (
                <AlamatCetak judul="Catatan">
                    <p className="whitespace-pre-line">{Transfer.Catatan}</p>
                </AlamatCetak>
            ) : null}
        </BingkaiCetakGrosir>
    );
}
