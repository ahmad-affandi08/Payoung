import BingkaiCetakGrosir, {
    AlamatCetak,
    BarisKepalaCetak,
    RingkasanCetak,
} from '@/Komponen/Grosir/BingkaiCetakGrosir';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { JudulUnit, KataUnit, RincianUnit } from '@/Pustaka/UnitServis';
import type { PropsCetakPerintahKerja } from '@/Tipe/Bengkel';

/**
 * Cetak perintah kerja & estimasi bengkel (§9.10): A4, juga terbaca di printer struk lebar lewat dialog cetak peramban
 * (satu kolom di kertas sempit). Kolom "Disetujui" & tanda tangan pelanggan dipakai saat persetujuan diberikan
 * langsung di bengkel. Halaman cetak adalah pengecualian `TabelData` (rincian dokumen).
 */
export default function HalamanCetakPerintahKerja({ PerintahKerja: pk, Usaha }: PropsCetakPerintahKerja) {
    return (
        <BingkaiCetakGrosir
            judulDokumen="Perintah Kerja & Estimasi"
            judulTab={`Perintah kerja ${pk.Nomor}`}
            nomor={pk.Nomor}
            usaha={Usaha}
            dibatalkan={pk.Status === 'Dibatalkan'}
            alasanBatal={pk.AlasanBatal}
            kepala={
                <>
                    <BarisKepalaCetak label="Masuk">{FormatTanggalWaktu(pk.DibuatPada)}</BarisKepalaCetak>
                    <BarisKepalaCetak label="Perkiraan selesai">
                        {FormatTanggalWaktu(pk.EstimasiSelesaiPada)}
                    </BarisKepalaCetak>
                    <BarisKepalaCetak label="Status">{pk.LabelStatus}</BarisKepalaCetak>
                </>
            }
            tandaTangan={[
                { label: 'Pelanggan', nama: pk.Pelanggan.Nama },
                { label: 'Mekanik' },
                { label: 'Kepala bengkel' },
            ]}
        >
            <section className="grid gap-4 sm:grid-cols-2">
                <AlamatCetak judul="Pelanggan">
                    <p className="font-semibold">{pk.Pelanggan.Nama}</p>
                    {pk.Pelanggan.NoHp ? <p>{pk.Pelanggan.NoHp}</p> : null}
                </AlamatCetak>
                <AlamatCetak judul={pk.Kendaraan === null ? 'Unit servis' : KataUnit(pk.Kendaraan.Jenis)}>
                    <p className="font-mono font-semibold">{JudulUnit(pk.Kendaraan)}</p>
                    <p>{RincianUnit(pk.Kendaraan)}</p>
                    {pk.KmMasuk !== null ? <p>KM masuk {pk.KmMasuk.toLocaleString('id-ID')}</p> : null}
                </AlamatCetak>
                <AlamatCetak judul="Keluhan">
                    <p className="whitespace-pre-line">{pk.Keluhan}</p>
                </AlamatCetak>
                {pk.Diagnosis ? (
                    <AlamatCetak judul="Diagnosis">
                        <p className="whitespace-pre-line">{pk.Diagnosis}</p>
                    </AlamatCetak>
                ) : null}
            </section>

            <Table className="text-left">
                <TableHeader>
                    <TableRow>
                        <TableHead scope="col" className="w-10">
                            No
                        </TableHead>
                        <TableHead scope="col">Pekerjaan / sparepart</TableHead>
                        <TableHead scope="col" className="text-right">
                            Jumlah
                        </TableHead>
                        <TableHead scope="col" className="text-right">
                            Harga
                        </TableHead>
                        <TableHead scope="col" className="text-right">
                            Subtotal
                        </TableHead>
                        <TableHead scope="col" className="w-24">
                            Disetujui
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {pk.Baris.map((b) => (
                        <TableRow key={b.Uuid}>
                            <TableCell className="tabular-nums">{b.Urutan}</TableCell>
                            <TableCell className="whitespace-normal">
                                {b.Jenis}: {b.NamaProduk}
                                {b.Karyawan ? (
                                    <span className="block text-keterangan">Mekanik {b.Karyawan.Nama}</span>
                                ) : null}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {FormatJumlahStok(b.Jumlah, b.SimbolSatuan)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">{FormatRupiah(b.HargaSatuan)}</TableCell>
                            <TableCell className="text-right tabular-nums">{FormatRupiah(b.Subtotal)}</TableCell>
                            <TableCell>
                                {b.Disetujui ? (
                                    'Ya'
                                ) : (
                                    <span
                                        className="inline-block size-4 border border-garis"
                                        aria-label="Diisi tangan"
                                    />
                                )}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>

            <RingkasanCetak
                baris={[
                    { label: 'Subtotal', nilai: pk.Subtotal },
                    { label: 'Diskon', nilai: pk.Diskon },
                    { label: 'Pajak', nilai: pk.Pajak },
                ]}
                labelTotal="Perkiraan total"
                total={pk.Total}
            />
            <p className="text-keterangan text-teks-sekunder">
                Angka ini perkiraan. Tagihan akhir mengikuti pekerjaan yang disetujui dan dibayar di kasir.
            </p>
        </BingkaiCetakGrosir>
    );
}
