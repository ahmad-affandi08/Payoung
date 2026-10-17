import { FormatJumlahInvoice, KeteranganTermin } from '@/Komponen/Invoice/BagianInvoice';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { FormatPersen, FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { AmbilTandaDesimal } from '@/Pustaka/HitungDesimal';
import { TerbilangRupiah } from '@/Pustaka/Terbilang';
import type { DokumenInvoice } from '@/Tipe/Invoice';

/**
 * Badan invoice yang dibaca pelanggan (F-21): dipakai halaman cetak A4 di back-office dan halaman publik `/i/{kode}`,
 * jadi yang tercetak sama persis dengan yang dilihat pelanggan. Tanpa kerangka aplikasi, hanya dokumennya. Halaman
 * cetak adalah pengecualian `TabelData` (§25.2 no. 17): rincian dokumen, bukan tabel kerja.
 */
export default function TampilanDokumenInvoice({
    dokumen,
    alamatKuitansi,
}: {
    dokumen: DokumenInvoice;
    /** Alamat kuitansi satu pembayaran (null = tanpa tautan); berbeda untuk halaman publik dan cetak back-office. */
    alamatKuitansi?: ((uuidPembayaran: string) => string) | undefined;
}) {
    const { Invoice: i, Penjual, Pembeli, Baris, UangMuka, Pelunasan } = dokumen;
    const batal = i.Status === 'Dibatalkan';
    const lunas = i.StatusBayar === 'Lunas';
    const judul = i.Jenis === 'UangMuka' ? 'Invoice Uang Muka' : 'Invoice';
    const potongUangMuka = AmbilTandaDesimal(i.PotonganUangMukaDpp) > 0;
    const adaPembayaran = AmbilTandaDesimal(i.Dibayar ?? '0') > 0;

    return (
        <article className="flex flex-col gap-6 text-isi text-teks-utama">
            {batal ? (
                <p className="border-2 border-bahaya p-3 text-center text-subjudul font-bold text-bahaya uppercase">
                    Dibatalkan
                    {i.AlasanBatal ? (
                        <span className="block text-isi font-normal normal-case">{i.AlasanBatal}</span>
                    ) : null}
                </p>
            ) : null}
            {lunas && !batal ? (
                <p className="border-2 border-sukses p-2 text-center text-subjudul font-bold text-sukses uppercase">
                    Lunas
                </p>
            ) : null}

            <header className="flex flex-wrap items-start justify-between gap-4 border-b border-garis pb-4">
                <div className="min-w-0">
                    <p className="text-subjudul font-semibold break-words">{Penjual.Nama}</p>
                    {Penjual.Alamat ? <p className="whitespace-pre-line text-teks-sekunder">{Penjual.Alamat}</p> : null}
                    {Penjual.Npwp ? <p className="text-teks-sekunder">NPWP {Penjual.Npwp}</p> : null}
                </div>
                <div className="text-right">
                    <p className="text-judul font-semibold uppercase">{judul}</p>
                    <p className="font-mono">{i.Nomor ?? 'Draf'}</p>
                    <p>
                        Tanggal <span className="font-semibold">{FormatTanggal(i.Tanggal)}</span>
                    </p>
                    <p>
                        Jatuh tempo <span className="font-semibold">{FormatTanggal(i.JatuhTempo)}</span>
                    </p>
                    <p className="text-teks-sekunder">Termin {KeteranganTermin(i.TerminHari)}</p>
                </div>
            </header>

            <section className="grid gap-4 sm:grid-cols-2">
                <div>
                    <h2 className="text-label font-semibold text-teks-sekunder">Ditagihkan kepada</h2>
                    <p className="font-semibold break-words">{Pembeli.Nama}</p>
                    {Pembeli.Alamat ? <p className="whitespace-pre-line">{Pembeli.Alamat}</p> : null}
                    {Pembeli.Npwp ? <p>NPWP {Pembeli.Npwp}</p> : null}
                    {Pembeli.NoHp ? <p>{Pembeli.NoHp}</p> : null}
                    {Pembeli.Email ? <p className="break-all">{Pembeli.Email}</p> : null}
                </div>
                <div>
                    {i.Perihal ? (
                        <>
                            <h2 className="text-label font-semibold text-teks-sekunder">Perihal</h2>
                            <p className="font-semibold break-words">{i.Perihal}</p>
                        </>
                    ) : null}
                    {i.NomorReferensi ? (
                        <p className="mt-2">
                            <span className="text-teks-sekunder">No. PO / referensi</span>{' '}
                            <span className="font-mono">{i.NomorReferensi}</span>
                        </p>
                    ) : null}
                </div>
            </section>

            <Table className="text-left">
                <TableHeader>
                    <TableRow>
                        <TableHead scope="col">Uraian</TableHead>
                        <TableHead scope="col" className="text-right">
                            Jumlah
                        </TableHead>
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
                        <TableRow key={b.Kunci}>
                            <TableCell className="whitespace-normal">
                                <span className="break-words">{b.Nama}</span>
                                {b.Keterangan ? (
                                    <span className="block text-keterangan text-teks-sekunder">{b.Keterangan}</span>
                                ) : null}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {FormatJumlahInvoice(b.Jumlah)} {b.SimbolSatuan ?? ''}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">{FormatRupiah(b.Harga)}</TableCell>
                            <TableCell className="text-right tabular-nums">{FormatRupiah(b.Diskon)}</TableCell>
                            <TableCell className="text-right tabular-nums">{FormatRupiah(b.Subtotal)}</TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>

            <dl className="ml-auto flex w-full max-w-sm flex-col gap-1">
                <BarisRingkasan label="Subtotal" nilai={i.Subtotal} />
                {AmbilTandaDesimal(i.Diskon) > 0 ? <BarisRingkasan label="Diskon" nilai={i.Diskon} /> : null}
                {i.TarifPpn !== null || AmbilTandaDesimal(i.Pajak) > 0 ? (
                    <>
                        <BarisRingkasan label="Dasar pengenaan pajak" nilai={i.DasarPengenaanPajak} />
                        <BarisRingkasan
                            label={i.TarifPpn === null ? 'Pajak' : `PPN ${FormatPersen(i.TarifPpn)}`}
                            nilai={i.Pajak}
                        />
                    </>
                ) : null}
                <BarisRingkasan label="Total" nilai={i.Total} tebal />
                {potongUangMuka ? (
                    <>
                        <BarisRingkasan label="Uang muka dipotong (DPP)" nilai={i.PotonganUangMukaDpp} negatif />
                        {AmbilTandaDesimal(i.PotonganUangMukaPajak) > 0 ? (
                            <BarisRingkasan label="Pajak uang muka dipotong" nilai={i.PotonganUangMukaPajak} negatif />
                        ) : null}
                        <BarisRingkasan label="Jumlah tagihan" nilai={i.Tagihan} tebal />
                    </>
                ) : null}
                {adaPembayaran ? <BarisRingkasan label="Sudah dibayar" nilai={i.Dibayar ?? '0.00'} negatif /> : null}
                {i.Sisa !== null && !batal ? <BarisRingkasan label="Sisa tagihan" nilai={i.Sisa} tebal /> : null}
            </dl>

            <p className="text-teks-sekunder">
                Terbilang: <span className="text-teks-utama italic">{TerbilangRupiah(i.Tagihan)}</span>
            </p>

            {i.PphDipotongPembeli !== null ? (
                <p className="text-teks-sekunder">
                    {i.KodePph ?? 'PPh'} {i.TarifPph === null ? '' : `${FormatPersen(i.TarifPph)} `}
                    sebesar{' '}
                    <span className="font-semibold text-teks-utama tabular-nums">
                        {FormatRupiah(i.PphDipotongPembeli)}
                    </span>{' '}
                    dipotong pembeli saat pembayaran; mohon sertakan bukti potong.
                </p>
            ) : null}

            {UangMuka.length > 0 ? (
                <section>
                    <h2 className="text-label font-semibold text-teks-sekunder">Uang muka yang dipotong</h2>
                    <ul className="flex flex-col gap-0.5">
                        {UangMuka.map((dp) => (
                            <li key={dp.Nomor ?? dp.Tanggal}>
                                <span className="font-mono">{dp.Nomor}</span> | {FormatTanggal(dp.Tanggal)} |{' '}
                                <span className="tabular-nums">{FormatRupiah(dp.Total)}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            {Pelunasan.length > 0 ? (
                <section>
                    <h2 className="text-label font-semibold text-teks-sekunder">Pembayaran diterima</h2>
                    <ul className="flex flex-col gap-0.5">
                        {Pelunasan.map((p) => (
                            <li key={p.Nomor}>
                                {FormatTanggal(p.Tanggal)} |{' '}
                                <span className="tabular-nums">{FormatRupiah(p.Jumlah)}</span>
                                {AmbilTandaDesimal(p.PotonganPph) > 0
                                    ? ` (termasuk PPh ${FormatRupiah(p.PotonganPph)})`
                                    : ''}
                                {alamatKuitansi ? (
                                    <>
                                        {' | '}
                                        <a
                                            href={alamatKuitansi(p.Uuid)}
                                            className="font-semibold text-brand underline print:hidden"
                                        >
                                            Kuitansi
                                        </a>
                                    </>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            {i.InfoPembayaran ? (
                <section>
                    <h2 className="text-label font-semibold text-teks-sekunder">Cara pembayaran</h2>
                    <p className="whitespace-pre-line">{i.InfoPembayaran}</p>
                </section>
            ) : null}
            {i.Catatan ? (
                <section>
                    <h2 className="text-label font-semibold text-teks-sekunder">Catatan</h2>
                    <p className="whitespace-pre-line">{i.Catatan}</p>
                </section>
            ) : null}
            {i.Syarat ? (
                <section>
                    <h2 className="text-label font-semibold text-teks-sekunder">Syarat & ketentuan</h2>
                    <p className="whitespace-pre-line text-teks-sekunder">{i.Syarat}</p>
                </section>
            ) : null}
        </article>
    );
}

function BarisRingkasan({
    label,
    nilai,
    tebal = false,
    negatif = false,
}: {
    label: string;
    nilai: string;
    tebal?: boolean;
    negatif?: boolean;
}) {
    return (
        <div
            className={`flex items-baseline justify-between gap-3 ${tebal ? 'border-t border-garis pt-1 font-semibold' : ''}`}
        >
            <dt className={tebal ? '' : 'text-teks-sekunder'}>{label}</dt>
            <dd className="text-right tabular-nums">
                {negatif ? '− ' : ''}
                {FormatRupiah(nilai)}
            </dd>
        </div>
    );
}
