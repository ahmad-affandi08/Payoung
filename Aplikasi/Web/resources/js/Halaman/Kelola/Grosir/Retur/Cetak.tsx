import BingkaiCetakGrosir, {
    AlamatCetak,
    AlamatPembeliCetak,
    AlamatPenjualCetak,
    BarisKepalaCetak,
    RingkasanCetak,
} from '@/Komponen/Grosir/BingkaiCetakGrosir';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { FormatPersen, FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { PropsCetakRetur } from '@/Tipe/Grosir';

/**
 * Cetak retur grosir (F-12, §9.7, BR-12.7). Judulnya ikut keadaan dokumennya, dan itu bukan kosmetik: retur atas
 * penyerahan yang **sudah** difakturkan memang mengurangi tagihan pembeli, jadi kertasnya **Nota Kredit**. Retur atas
 * penyerahan yang belum difakturkan tidak mengurangi tagihan apa pun karena tagihannya belum ada — menyebutnya nota
 * kredit akan membuat pembeli mengira ada potongan yang bisa dipakai, padahal fakturnya nanti hanya menagih sisanya.
 */
export default function HalamanCetakReturGrosir({ Retur, Baris, Usaha }: PropsCetakRetur) {
    const notaKredit = Retur.MengurangiPiutang;

    return (
        <BingkaiCetakGrosir
            judulDokumen={notaKredit ? 'Nota Kredit' : 'Tanda Terima Retur'}
            judulTab={`Retur ${Retur.Nomor}`}
            nomor={Retur.Nomor}
            usaha={Usaha}
            dibatalkan={Retur.Status === 'Dibatalkan'}
            alasanBatal={Retur.AlasanBatal}
            kepala={
                <>
                    <BarisKepalaCetak label="Tanggal">{FormatTanggal(Retur.Tanggal)}</BarisKepalaCetak>
                    {Retur.NomorSuratJalan ? (
                        <BarisKepalaCetak label="Surat jalan">{Retur.NomorSuratJalan}</BarisKepalaCetak>
                    ) : null}
                    {Retur.NomorFaktur ? <BarisKepalaCetak label="Faktur">{Retur.NomorFaktur}</BarisKepalaCetak> : null}
                </>
            }
            tandaTangan={[{ label: 'Diterbitkan oleh' }, { label: 'Diterima oleh' }]}
        >
            <section className="grid gap-4 sm:grid-cols-2">
                <AlamatPembeliCetak judul="Untuk" pelanggan={Retur.Pelanggan} />
                <div className="flex flex-col gap-4">
                    <AlamatPenjualCetak outlet={Retur.Outlet} />
                    <AlamatCetak judul="Alasan retur">
                        <p className="whitespace-pre-line">{Retur.Alasan}</p>
                    </AlamatCetak>
                </div>
            </section>

            <Table className="text-left">
                <TableHeader>
                    <TableRow>
                        <TableHead scope="col">Barang</TableHead>
                        <TableHead scope="col" className="text-right">
                            Jumlah
                        </TableHead>
                        <TableHead scope="col">Kondisi</TableHead>
                        <TableHead scope="col" className="text-right">
                            Harga
                        </TableHead>
                        <TableHead scope="col" className="text-right">
                            Diskon
                        </TableHead>
                        <TableHead scope="col" className="text-right">
                            Subtotal
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {Baris.map((b) => (
                        <TableRow key={b.Urutan}>
                            <TableCell className="whitespace-normal">
                                {b.NamaProduk}
                                {b.Sku ? <span className="block font-mono text-keterangan">{b.Sku}</span> : null}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {FormatJumlahStok(b.Jumlah, b.SimbolSatuan)}
                            </TableCell>
                            <TableCell>{b.LabelKondisi}</TableCell>
                            <TableCell className="text-right tabular-nums">{FormatRupiah(b.Harga)}</TableCell>
                            <TableCell className="text-right tabular-nums">{FormatRupiah(b.Diskon)}</TableCell>
                            <TableCell className="text-right tabular-nums">{FormatRupiah(b.Subtotal)}</TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>

            <RingkasanCetak
                baris={[
                    { label: 'Subtotal', nilai: Retur.Subtotal },
                    { label: 'Diskon', nilai: Retur.Diskon },
                    { label: 'Dasar pengenaan pajak', nilai: Retur.DasarPengenaanPajak },
                    {
                        label: Retur.TarifPpn === null ? 'PPN' : `PPN ${FormatPersen(Retur.TarifPpn)}`,
                        nilai: Retur.Pajak,
                    },
                ]}
                labelTotal={notaKredit ? 'Pengurangan tagihan' : 'Nilai barang kembali'}
                total={Retur.Total}
            />

            <p className="text-keterangan text-teks-sekunder">
                {notaKredit
                    ? `Nilai di atas mengurangi tagihan faktur ${Retur.NomorFaktur ?? ''}. Tidak ada uang yang berpindah, yang tersisa dari faktur itulah yang ditagihkan.`
                    : 'Penyerahannya belum difakturkan, jadi belum ada tagihan yang dikurangi. Faktur nanti hanya menagihkan barang yang tidak dikembalikan.'}
            </p>

            {Retur.Catatan ? (
                <AlamatCetak judul="Catatan">
                    <p className="whitespace-pre-line">{Retur.Catatan}</p>
                </AlamatCetak>
            ) : null}
        </BingkaiCetakGrosir>
    );
}
