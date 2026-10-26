import { Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import { AlamatPembelian, LabelStatusPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsDetailBiayaTambahan } from '@/Tipe/Pembelian';

import { AlamatBiayaTambahan } from './Daftar';

/** v3.41: rincian biaya tambahan — alokasi per barang, porsi nilai stok & HPP, jurnal, dan pembatalan. */
export default function HalamanDetailBiayaTambahan({ Biaya, Baris, Jurnal, Tindakan }: PropsDetailBiayaTambahan) {
    const [batal, AturBatal] = useState(false);

    return (
        <TataLetakAplikasi
            judul={`${Biaya.Nomor} ${Biaya.LabelJenis}`}
            jejak={[{ label: 'Biaya tambahan pembelian', href: AlamatBiayaTambahan }]}
        >
            {Tindakan.Batalkan ? (
                <AksiHalaman>
                    <Tombol varian="sekunder" onClick={() => AturBatal(true)}>
                        Batalkan biaya
                    </Tombol>
                </AksiHalaman>
            ) : null}

            <Panel>
                <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-isi sm:grid-cols-4">
                    <Nilai label="Status">
                        <LabelStatusPembelian
                            status={Biaya.Status}
                            label={Biaya.Status === 'Diposting' ? 'Tercatat' : 'Dibatalkan'}
                        />
                    </Nilai>
                    <Nilai label="Tanggal">{FormatTanggal(Biaya.Tanggal)}</Nilai>
                    <Nilai label="Penerimaan">
                        {Biaya.UuidPenerimaan ? (
                            <Link
                                href={`${AlamatPembelian}/penerimaan/${Biaya.UuidPenerimaan}`}
                                className="font-mono text-brand underline"
                            >
                                {Biaya.NomorPenerimaan}
                            </Link>
                        ) : (
                            Biaya.NomorPenerimaan
                        )}
                    </Nilai>
                    <Nilai label="Lokasi stok">{Biaya.NamaGudang}</Nilai>
                    <Nilai label="Jumlah">
                        <span className="font-semibold tabular-nums">{FormatRupiah(Biaya.Jumlah)}</span>
                    </Nilai>
                    <Nilai label="Ke nilai stok">
                        <span className="tabular-nums">{FormatRupiah(Biaya.KePersediaan)}</span>
                    </Nilai>
                    <Nilai label="Ke HPP (sudah terjual)">
                        <span className="tabular-nums">{FormatRupiah(Biaya.KeHpp)}</span>
                    </Nilai>
                    <Nilai label="Dibagi">{Biaya.LabelDasarAlokasi}</Nilai>
                    {Biaya.NamaPenagih ? <Nilai label="Ditagih oleh">{Biaya.NamaPenagih}</Nilai> : null}
                    <Nilai label="Jurnal">
                        <span className="flex flex-col">
                            {Jurnal.map((j) => (
                                <Link
                                    key={j.Uuid}
                                    href={`/kelola/akuntansi/jurnal/${j.Uuid}`}
                                    className="font-mono text-brand underline"
                                >
                                    {j.Nomor}
                                </Link>
                            ))}
                        </span>
                    </Nilai>
                </dl>
                {Biaya.Catatan ? <p className="mt-3 text-isi text-teks-sekunder">{Biaya.Catatan}</p> : null}
                {Biaya.AlasanBatal ? (
                    <p className="mt-3 text-isi text-bahaya">Dibatalkan: {Biaya.AlasanBatal}</p>
                ) : null}
            </Panel>

            <h2 className="text-subjudul font-semibold text-teks-utama">Alokasi per barang</h2>
            <TabelForm label="Alokasi biaya per barang" lebar="sedang">
                <TableHeader>
                    <TableRow>
                        <TableHead>Produk</TableHead>
                        <TableHead className="text-right">Alokasi</TableHead>
                        <TableHead className="text-right">Ke nilai stok</TableHead>
                        <TableHead className="text-right">Ke HPP</TableHead>
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
                            <TableCell className="text-right tabular-nums">{FormatRupiah(b.Alokasi)}</TableCell>
                            <TableCell className="text-right tabular-nums">{FormatRupiah(b.KePersediaan)}</TableCell>
                            <TableCell className="text-right tabular-nums">{FormatRupiah(b.KeHpp)}</TableCell>
                        </TableRow>
                    ))}
                </TableBody>
                <TableFooter>
                    <TableRow>
                        <TableCell>Total</TableCell>
                        <TableCell className="text-right font-semibold tabular-nums">
                            {FormatRupiah(Biaya.Jumlah)}
                        </TableCell>
                        <TableCell className="text-right font-semibold tabular-nums">
                            {FormatRupiah(Biaya.KePersediaan)}
                        </TableCell>
                        <TableCell className="text-right font-semibold tabular-nums">
                            {FormatRupiah(Biaya.KeHpp)}
                        </TableCell>
                    </TableRow>
                </TableFooter>
            </TabelForm>

            {batal ? <FormBatal uuid={Biaya.Uuid} saatSelesai={() => AturBatal(false)} /> : null}
        </TataLetakAplikasi>
    );
}

function FormBatal({ uuid, saatSelesai }: { uuid: string; saatSelesai: () => void }) {
    const formulir = useForm({ Alasan: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${AlamatBiayaTambahan}/${uuid}/batalkan`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul="Batalkan biaya tambahan"
            keterangan="Nilai stok dan jurnalnya dikembalikan. Hanya bisa selama barangnya belum terjual atau berpindah sejak biaya dicatat."
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeksPanjang
                    label="Alasan membatalkan"
                    nilai={formulir.data.Alasan}
                    saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                    maksimal={255}
                    baris={3}
                    galat={formulir.errors.Alasan}
                    required
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" varian="bahaya" memproses={formulir.processing}>
                        Batalkan biaya
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Kembali
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
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
