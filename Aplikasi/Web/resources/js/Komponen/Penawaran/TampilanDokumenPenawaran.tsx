import { FormatJumlahInvoice, KeteranganTermin } from '@/Komponen/Invoice/BagianInvoice';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { FormatPersen, FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { AmbilTandaDesimal } from '@/Pustaka/HitungDesimal';
import { TerbilangRupiah } from '@/Pustaka/Terbilang';
import type { DokumenPenawaran } from '@/Tipe/Penawaran';

/**
 * Badan penawaran yang dibaca pelanggan (F-21 bagian 3): dipakai halaman cetak A4 di back-office dan halaman publik
 * `/p/{kode}`, jadi yang tercetak sama persis dengan yang dilihat pelanggan. Halaman cetak adalah pengecualian
 * `TabelData` (§25.2 no. 17): rincian dokumen, bukan tabel kerja.
 */
export default function TampilanDokumenPenawaran({ dokumen }: { dokumen: DokumenPenawaran }) {
    const { Penawaran: p, Penjual, Pembeli, Baris } = dokumen;
    const batal = p.Status === 'Dibatalkan';
    const kedaluwarsa = p.StatusTampil === 'Kedaluwarsa';

    return (
        <article className="flex flex-col gap-6 text-isi text-teks-utama">
            {batal ? (
                <p className="border-2 border-bahaya p-3 text-center text-subjudul font-bold text-bahaya uppercase">
                    Dibatalkan
                    {p.AlasanBatal ? (
                        <span className="block text-isi font-normal normal-case">{p.AlasanBatal}</span>
                    ) : null}
                </p>
            ) : null}
            {kedaluwarsa ? (
                <p className="border-2 border-bahaya p-2 text-center text-subjudul font-bold text-bahaya uppercase">
                    Kedaluwarsa
                </p>
            ) : null}
            {p.Status === 'Diterima' ? (
                <p className="border-2 border-sukses p-2 text-center text-subjudul font-bold text-sukses uppercase">
                    Diterima
                </p>
            ) : null}
            {p.Status === 'Ditolak' ? (
                <p className="border-2 border-bahaya p-2 text-center text-subjudul font-bold text-bahaya uppercase">
                    Ditolak
                </p>
            ) : null}

            <header className="flex flex-wrap items-start justify-between gap-4 border-b border-garis pb-4">
                <div className="min-w-0">
                    <p className="text-subjudul font-semibold break-words">{Penjual.Nama}</p>
                    {Penjual.Alamat ? <p className="whitespace-pre-line text-teks-sekunder">{Penjual.Alamat}</p> : null}
                    {Penjual.Npwp ? <p className="text-teks-sekunder">NPWP {Penjual.Npwp}</p> : null}
                </div>
                <div className="text-right">
                    <p className="text-judul font-semibold uppercase">Penawaran</p>
                    <p className="font-mono">{p.Nomor ?? 'Draf'}</p>
                    <p>
                        Tanggal <span className="font-semibold">{FormatTanggal(p.Tanggal)}</span>
                    </p>
                    <p>
                        Berlaku sampai <span className="font-semibold">{FormatTanggal(p.BerlakuSampai)}</span>
                    </p>
                    <p className="text-teks-sekunder">Termin pembayaran {KeteranganTermin(p.TerminHari)}</p>
                </div>
            </header>

            <section className="grid gap-4 sm:grid-cols-2">
                <div>
                    <h2 className="text-label font-semibold text-teks-sekunder">Ditujukan kepada</h2>
                    <p className="font-semibold break-words">{Pembeli.Nama}</p>
                    {Pembeli.Alamat ? <p className="whitespace-pre-line">{Pembeli.Alamat}</p> : null}
                    {Pembeli.Npwp ? <p>NPWP {Pembeli.Npwp}</p> : null}
                    {Pembeli.NoHp ? <p>{Pembeli.NoHp}</p> : null}
                    {Pembeli.Email ? <p className="break-all">{Pembeli.Email}</p> : null}
                </div>
                <div>
                    {p.Perihal ? (
                        <>
                            <h2 className="text-label font-semibold text-teks-sekunder">Perihal</h2>
                            <p className="font-semibold break-words">{p.Perihal}</p>
                        </>
                    ) : null}
                    {p.NomorReferensi ? (
                        <p className="mt-2">
                            <span className="text-teks-sekunder">No. PO / referensi</span>{' '}
                            <span className="font-mono">{p.NomorReferensi}</span>
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
                <BarisRingkasan label="Subtotal" nilai={p.Subtotal} />
                {AmbilTandaDesimal(p.Diskon) > 0 ? <BarisRingkasan label="Diskon" nilai={p.Diskon} /> : null}
                {p.TarifPpn !== null || AmbilTandaDesimal(p.Pajak) > 0 ? (
                    <>
                        <BarisRingkasan label="Dasar pengenaan pajak" nilai={p.DasarPengenaanPajak} />
                        <BarisRingkasan
                            label={p.TarifPpn === null ? 'Pajak' : `PPN ${FormatPersen(p.TarifPpn)}`}
                            nilai={p.Pajak}
                        />
                    </>
                ) : null}
                <BarisRingkasan label="Total penawaran" nilai={p.Total} tebal />
            </dl>

            <p className="text-teks-sekunder">
                Terbilang: <span className="text-teks-utama italic">{TerbilangRupiah(p.Total)}</span>
            </p>

            {p.Catatan ? (
                <section>
                    <h2 className="text-label font-semibold text-teks-sekunder">Catatan</h2>
                    <p className="whitespace-pre-line">{p.Catatan}</p>
                </section>
            ) : null}
            {p.Syarat ? (
                <section>
                    <h2 className="text-label font-semibold text-teks-sekunder">Syarat & ketentuan</h2>
                    <p className="whitespace-pre-line text-teks-sekunder">{p.Syarat}</p>
                </section>
            ) : null}
            <p className="text-keterangan text-teks-sekunder">
                Harga dan pajak pada penawaran ini adalah perkiraan sampai {FormatTanggal(p.BerlakuSampai)}; angka pada
                invoice mengikuti tarif pajak yang berlaku pada tanggal invoice.
            </p>
        </article>
    );
}

function BarisRingkasan({ label, nilai, tebal = false }: { label: string; nilai: string; tebal?: boolean }) {
    return (
        <div
            className={`flex items-baseline justify-between gap-3 ${tebal ? 'border-t border-garis pt-1 font-semibold' : ''}`}
        >
            <dt className={tebal ? '' : 'text-teks-sekunder'}>{label}</dt>
            <dd className="text-right tabular-nums">{FormatRupiah(nilai)}</dd>
        </div>
    );
}
