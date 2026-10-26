import { Link } from '@inertiajs/react';

import Panel from '@/Komponen/Kelola/Panel';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import { TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatHppSatuan, FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsDetailDokumenKonsinyasi } from '@/Tipe/Pembelian';

import { AlamatKonsinyasi } from '../Daftar';

/** F-05i: rincian dokumen titipan masuk / retur ke penitip. Dokumen langsung dicatat; koreksi = dokumen berlawanan. */
export default function HalamanDetailDokumenKonsinyasi({ Dokumen, Baris }: PropsDetailDokumenKonsinyasi) {
    return (
        <TataLetakAplikasi
            judul={`${Dokumen.Nomor} ${Dokumen.LabelJenis}`}
            jejak={[
                { label: 'Konsinyasi', href: AlamatKonsinyasi },
                { label: 'Riwayat titipan', href: `${AlamatKonsinyasi}/dokumen` },
            ]}
        >
            <Panel>
                <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-isi sm:grid-cols-4">
                    <Nilai label="Jenis">
                        <LabelStatus
                            jenis={Dokumen.Jenis === 'Masuk' ? 'sukses' : 'netral'}
                            teks={Dokumen.LabelJenis}
                        />
                    </Nilai>
                    <Nilai label="Tanggal">{FormatTanggal(Dokumen.Tanggal)}</Nilai>
                    <Nilai label="Penitip">
                        {Dokumen.UuidPemasok ? (
                            <Link
                                href={`${AlamatKonsinyasi}/penitip/${Dokumen.UuidPemasok}`}
                                className="text-brand underline"
                            >
                                {Dokumen.NamaPemasok}
                            </Link>
                        ) : (
                            Dokumen.NamaPemasok
                        )}
                    </Nilai>
                    <Nilai label="Lokasi stok">
                        {Dokumen.NamaOutlet ? `${Dokumen.NamaGudang} | ${Dokumen.NamaOutlet}` : Dokumen.NamaGudang}
                    </Nilai>
                    <Nilai label="Nilai titipan">
                        <span className="font-semibold tabular-nums">{FormatRupiah(Dokumen.TotalNilai)}</span>
                    </Nilai>
                    <Nilai label="Jurnal">Tidak dijurnal (barang titipan bukan aset toko)</Nilai>
                </dl>
                {Dokumen.Catatan ? <p className="mt-3 text-isi text-teks-sekunder">{Dokumen.Catatan}</p> : null}
            </Panel>

            <h2 className="text-subjudul font-semibold text-teks-utama">Barang</h2>
            <TabelForm label="Barang titipan" lebar="sedang">
                <TableHeader>
                    <TableRow>
                        <TableHead>Produk</TableHead>
                        <TableHead className="text-right">Jumlah</TableHead>
                        <TableHead className="text-right">
                            {Dokumen.Jenis === 'Masuk' ? 'Harga titip' : 'Nilai per satuan'}
                        </TableHead>
                        <TableHead className="text-right">Nilai</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {Baris.map((b) => (
                        <TableRow key={b.Id}>
                            <TableCell>
                                <span className="block break-words">{b.NamaProduk}</span>
                                <span className="block font-mono text-keterangan text-teks-sekunder">
                                    {b.Sku ?? 'Tanpa SKU'}
                                </span>
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {FormatJumlahStok(b.Jumlah, b.SimbolSatuan)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">{FormatHppSatuan(b.HargaSatuan)}</TableCell>
                            <TableCell className="text-right tabular-nums">{FormatRupiah(b.Nilai)}</TableCell>
                        </TableRow>
                    ))}
                </TableBody>
                <TableFooter>
                    <TableRow>
                        <TableCell colSpan={3}>Total</TableCell>
                        <TableCell className="text-right font-semibold tabular-nums">
                            {FormatRupiah(Dokumen.TotalNilai)}
                        </TableCell>
                    </TableRow>
                </TableFooter>
            </TabelForm>
        </TataLetakAplikasi>
    );
}

function Nilai({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex flex-col gap-1">
            <dt className="text-teks-sekunder">{label}</dt>
            <dd className="text-teks-utama">{children}</dd>
        </div>
    );
}
