import { Card } from '@/Komponen/Ui/card';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableRow } from '@/Komponen/Ui/table';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatPersen, FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { JenisLabelTagihan, type TagihanLangganan } from '@/Tipe/TagihanLangganan';

type PropsRincianTagihan = { tagihan: TagihanLangganan; namaTenant?: string };

/**
 * Rincian satu tagihan langganan (P-08): subtotal → diskon kupon → DPP → PPN → total. Angka dari server, tidak
 * dihitung di browser. Dipakai back-office tenant dan Platform Pengelola.
 */
export default function RincianTagihan({ tagihan, namaTenant }: PropsRincianTagihan) {
    const adaPpn = tagihan.JumlahPpn !== '0.00' || tagihan.DasarPengenaanPajak !== '0.00';
    const pengaliPenuh = tagihan.PengaliDppPembilang === tagihan.PengaliDppPenyebut;

    return (
        <Card role="region" aria-labelledby="judul-rincian" className="gap-0 rounded-panel py-0 shadow-none">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-garis px-4 py-3">
                <div>
                    <h2 id="judul-rincian" className="font-mono text-subjudul font-semibold text-teks-utama">
                        {tagihan.Nomor}
                    </h2>
                    <p className="text-keterangan text-teks-sekunder">
                        {namaTenant ? `${namaTenant} | ` : ''}
                        {tagihan.LabelJenis}
                    </p>
                </div>
                <LabelStatus jenis={JenisLabelTagihan(tagihan.Status)} teks={tagihan.LabelStatus} />
            </div>
            <dl className="grid gap-x-6 gap-y-2 px-4 py-3 text-isi sm:grid-cols-2">
                <div>
                    <dt className="text-keterangan text-teks-sekunder">Terbit</dt>
                    <dd>{FormatTanggalWaktu(tagihan.TerbitPada)}</dd>
                </div>
                <div>
                    <dt className="text-keterangan text-teks-sekunder">Jatuh tempo</dt>
                    <dd>{FormatTanggalWaktu(tagihan.JatuhTempoPada)}</dd>
                </div>
                {tagihan.Keterangan ? (
                    <div className="sm:col-span-2">
                        <dt className="text-keterangan text-teks-sekunder">Keterangan</dt>
                        <dd>{tagihan.Keterangan}</dd>
                    </div>
                ) : null}
                {tagihan.DihapusBukuPada ? (
                    <div className="sm:col-span-2">
                        <dt className="text-keterangan text-teks-sekunder">
                            Dihapus-buku {FormatTanggalWaktu(tagihan.DihapusBukuPada)}
                        </dt>
                        <dd>{tagihan.AlasanHapusBuku}</dd>
                    </div>
                ) : null}
                {tagihan.DikembalikanPada ? (
                    <div>
                        <dt className="text-keterangan text-teks-sekunder">Dikembalikan (refund)</dt>
                        <dd>{FormatTanggalWaktu(tagihan.DikembalikanPada)}</dd>
                    </div>
                ) : null}
                {tagihan.DibayarPada ? (
                    <div>
                        <dt className="text-keterangan text-teks-sekunder">Lunas</dt>
                        <dd>{FormatTanggalWaktu(tagihan.DibayarPada)}</dd>
                    </div>
                ) : null}
                {tagihan.PeriodeMulai && tagihan.PeriodeSelesai ? (
                    <div>
                        <dt className="text-keterangan text-teks-sekunder">Periode layanan</dt>
                        <dd>
                            {FormatTanggalWaktu(tagihan.PeriodeMulai)} – {FormatTanggalWaktu(tagihan.PeriodeSelesai)}
                        </dd>
                    </div>
                ) : null}
            </dl>
            <Table className="border-t border-garis text-left text-isi">
                <TableCaption className="sr-only">Rincian biaya tagihan {tagihan.Nomor}</TableCaption>
                <TableBody>
                    {tagihan.Jenis === 'Addon' ? null : (
                        <TableRow className="border-garis hover:bg-transparent">
                            <TableHead
                                scope="row"
                                className="h-auto px-4 py-2 font-normal whitespace-normal text-inherit"
                            >
                                {tagihan.Jenis === 'NaikPaket' ? (
                                    <>
                                        Naik paket {tagihan.NaikPaket?.NamaPaketAsal ?? ''} ke {tagihan.NamaPaket}
                                        <span className="block text-keterangan text-teks-sekunder">
                                            Selisih harga untuk lama langganan {tagihan.JumlahBulan} bulan
                                            {tagihan.NaikPaket?.HariDitagih != null &&
                                            tagihan.NaikPaket.HariPeriode != null
                                                ? `, prorata ${tagihan.NaikPaket.HariDitagih} dari ${tagihan.NaikPaket.HariPeriode} hari periode berjalan`
                                                : ''}
                                        </span>
                                    </>
                                ) : (
                                    <>
                                        Paket {tagihan.NamaPaket} | {tagihan.JumlahBulan} bulan
                                    </>
                                )}
                            </TableHead>
                            <TableCell className="px-4 py-2 text-right tabular-nums">
                                {FormatRupiah(tagihan.SubtotalPaket)}
                            </TableCell>
                        </TableRow>
                    )}
                    {tagihan.RincianAddon.map((baris) => (
                        <TableRow key={baris.KodeAddon} className="border-garis hover:bg-transparent">
                            <TableHead
                                scope="row"
                                className="h-auto px-4 py-2 font-normal whitespace-normal text-inherit"
                            >
                                Add-on {baris.NamaAddon}
                                {baris.Jumlah > 1 ? ` × ${baris.Jumlah}` : ''}
                                {baris.Prorata && baris.HariDitagih !== null && baris.HariPeriode !== null ? (
                                    <span className="block text-keterangan text-teks-sekunder">
                                        Prorata {baris.HariDitagih} dari {baris.HariPeriode} hari periode berjalan
                                    </span>
                                ) : (
                                    <span className="block text-keterangan text-teks-sekunder">
                                        {baris.JumlahBulan === 12 ? '12 bulan' : '1 bulan'}
                                    </span>
                                )}
                            </TableHead>
                            <TableCell className="px-4 py-2 text-right tabular-nums">
                                {FormatRupiah(baris.Subtotal)}
                            </TableCell>
                        </TableRow>
                    ))}
                    {tagihan.Diskon !== '0.00' ? (
                        <TableRow className="border-garis hover:bg-transparent">
                            <TableHead
                                scope="row"
                                className="h-auto px-4 py-2 font-normal whitespace-normal text-inherit"
                            >
                                Diskon kupon <span className="font-mono">{tagihan.KodeKupon}</span>
                            </TableHead>
                            <TableCell className="px-4 py-2 text-right tabular-nums">
                                −{FormatRupiah(tagihan.Diskon)}
                            </TableCell>
                        </TableRow>
                    ) : null}
                    {adaPpn ? (
                        <>
                            <TableRow className="border-garis text-teks-sekunder hover:bg-transparent">
                                <TableHead
                                    scope="row"
                                    className="h-auto px-4 py-2 font-normal whitespace-normal text-inherit"
                                >
                                    Dasar pengenaan pajak
                                    {pengaliPenuh
                                        ? ''
                                        : ` (nilai lain ${tagihan.PengaliDppPembilang}/${tagihan.PengaliDppPenyebut})`}
                                </TableHead>
                                <TableCell className="px-4 py-2 text-right tabular-nums">
                                    {FormatRupiah(tagihan.DasarPengenaanPajak)}
                                </TableCell>
                            </TableRow>
                            <TableRow className="border-garis hover:bg-transparent">
                                <TableHead
                                    scope="row"
                                    className="h-auto px-4 py-2 font-normal whitespace-normal text-inherit"
                                >
                                    PPN {FormatPersen(tagihan.TarifPpn)}%
                                </TableHead>
                                <TableCell className="px-4 py-2 text-right tabular-nums">
                                    {FormatRupiah(tagihan.JumlahPpn)}
                                </TableCell>
                            </TableRow>
                        </>
                    ) : null}
                    <TableRow className="hover:bg-transparent">
                        <TableHead scope="row" className="h-auto px-4 py-3 whitespace-normal">
                            Total tagihan
                        </TableHead>
                        <TableCell className="px-4 py-3 text-right text-subjudul font-bold tabular-nums">
                            {FormatRupiah(tagihan.Total)}
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </Card>
    );
}
