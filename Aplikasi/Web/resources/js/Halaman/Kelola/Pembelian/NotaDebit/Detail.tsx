import { Link } from '@inertiajs/react';
import { useState } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import {
    AlamatPembelian,
    DialogAlasan,
    KartuKeterangan,
    Keterangan,
    LabelStatusPembelian,
    RingkasanNilai,
} from '@/Komponen/Pembelian/BagianDokumenPembelian';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import Tombol from '@/Komponen/Formulir/Tombol';
import { TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { BandingkanDesimal } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsDetailNotaDebit } from '@/Tipe/Pembelian';

import { AlamatNotaDebit } from './Daftar';

/** v6.07: rincian nota debit; pembagian ke nilai stok & HPP per baris faktur, jurnal, dan pembatalan. */
export default function HalamanDetailNotaDebit({ Nota, Baris, Jurnal, Izin, Tindakan }: PropsDetailNotaDebit) {
    const [batal, AturBatal] = useState(false);

    return (
        <TataLetakAplikasi
            judul={`${Nota.Nomor} ${Nota.LabelJenis}`}
            jejak={[{ label: 'Nota debit pembelian', href: AlamatNotaDebit }]}
        >
            {Tindakan.Batalkan ? (
                <AksiHalaman>
                    <Tombol varian="sekunder" onClick={() => AturBatal(true)}>
                        Batalkan nota debit
                    </Tombol>
                </AksiHalaman>
            ) : null}

            <KartuKeterangan>
                <Keterangan label="Status">
                    <LabelStatusPembelian
                        status={Nota.Status}
                        label={Nota.Status === 'Diposting' ? 'Diposting' : 'Dibatalkan'}
                    />
                </Keterangan>
                <Keterangan label="Tanggal">{FormatTanggal(Nota.Tanggal)}</Keterangan>
                <Keterangan label="Faktur">
                    {Nota.UuidFaktur ? (
                        <Link
                            href={`${AlamatPembelian}/faktur/${Nota.UuidFaktur}`}
                            className="font-mono text-brand underline"
                        >
                            {Nota.NomorFaktur}
                        </Link>
                    ) : (
                        Nota.NomorFaktur
                    )}
                </Keterangan>
                <Keterangan label="Pemasok">{Nota.NamaPemasok ?? '—'}</Keterangan>
                {Nota.NomorNotaPemasok ? (
                    <Keterangan label="Nomor nota pemasok">
                        <span className="font-mono">{Nota.NomorNotaPemasok}</span>
                    </Keterangan>
                ) : null}
                <Keterangan label="Jurnal">
                    <span className="flex flex-col">
                        {Jurnal.map((j) =>
                            Izin.LihatJurnal ? (
                                <Link
                                    key={j.Uuid}
                                    href={`/kelola/akuntansi/jurnal/${j.Uuid}`}
                                    className="font-mono text-brand underline"
                                >
                                    {j.Nomor}
                                </Link>
                            ) : (
                                <span key={j.Uuid} className="font-mono">
                                    {j.Nomor}
                                </span>
                            ),
                        )}
                    </span>
                </Keterangan>
            </KartuKeterangan>
            <p className="text-isi text-teks-sekunder">Alasan: {Nota.Alasan}</p>
            {Nota.AlasanBatal ? <p className="text-isi text-bahaya">Dibatalkan: {Nota.AlasanBatal}</p> : null}

            <Panel judul="Nilai" idJudul="judul-nilai-nota-debit">
                <RingkasanNilai
                    baris={[
                        { label: 'Nilai nota debit', nilai: Nota.NilaiKlaim },
                        { label: 'PPN masukan dikurangi', nilai: Nota.Pajak },
                        { label: 'Total', nilai: Nota.Total, tebal: true },
                        { label: 'Mengurangi hutang faktur', nilai: Nota.PotongHutang },
                        ...(BandingkanDesimal(Nota.NilaiRefund, '0') > 0
                            ? [{ label: 'Uang kembali dari pemasok', nilai: Nota.NilaiRefund }]
                            : []),
                    ]}
                />
            </Panel>

            <h2 className="text-subjudul font-semibold text-teks-utama">Pembagian per barang</h2>
            <TabelForm label="Pembagian nota debit per barang" lebar="sedang">
                <TableHeader>
                    <TableRow>
                        <TableHead>Produk</TableHead>
                        <TableHead className="text-right">Nilai</TableHead>
                        <TableHead className="text-right">Ke nilai stok</TableHead>
                        <TableHead className="text-right">Ke HPP</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {Baris.map((b) => (
                        <TableRow key={b.Id}>
                            <TableCell>
                                <span className="block break-words">{b.NamaProduk}</span>
                            </TableCell>
                            <TableCell className="text-right tabular-nums">{FormatRupiah(b.NilaiKlaim)}</TableCell>
                            <TableCell className="text-right tabular-nums">{FormatRupiah(b.KePersediaan)}</TableCell>
                            <TableCell className="text-right tabular-nums">{FormatRupiah(b.KeHpp)}</TableCell>
                        </TableRow>
                    ))}
                </TableBody>
                <TableFooter>
                    <TableRow>
                        <TableCell>Total</TableCell>
                        <TableCell className="text-right font-semibold tabular-nums">
                            {FormatRupiah(Nota.NilaiKlaim)}
                        </TableCell>
                        <TableCell className="text-right font-semibold tabular-nums">
                            {FormatRupiah(Nota.KePersediaan)}
                        </TableCell>
                        <TableCell className="text-right font-semibold tabular-nums">
                            {FormatRupiah(Nota.KeHpp)}
                        </TableCell>
                    </TableRow>
                </TableFooter>
            </TabelForm>

            {batal ? (
                <DialogAlasan
                    judul={`Batalkan nota debit ${Nota.Nomor}?`}
                    keterangan="Hutang faktur, nilai stok, dan jurnalnya dikembalikan. Nilai stok hanya bisa dikembalikan selama barangnya belum terjual atau berpindah sejak nota dicatat."
                    labelAksi="Batalkan nota debit"
                    alamat={`${AlamatNotaDebit}/${Nota.Uuid}/batalkan`}
                    saatTutup={() => AturBatal(false)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
