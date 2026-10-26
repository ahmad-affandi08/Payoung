import { Trash2Icon } from 'lucide-react';
import { Fragment } from 'react';

import TabelForm from '@/Komponen/TabelData/TabelForm';
import { GalatBidang } from '@/Komponen/Formulir/BagianBidang';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import PilihanCari from '@/Komponen/Formulir/PilihanCari';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import BidangNomorSeri from '@/Komponen/Persediaan/BidangNomorSeri';
import PemilihProdukStok from '@/Komponen/Persediaan/PemilihProdukStok';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import { TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { FormatRupiah } from '@/Pustaka/Format';
import type { BarisIsianBebas, ProdukPembelian } from '@/Tipe/Pembelian';

import {
    AmbilKonversi,
    AmbilSimbol,
    BuatBarisDariProduk,
    BuatUrlCariProdukPembelian,
    HitungSubtotal,
    PeriksaBaris,
} from './AturanPembelian';

type PropsIsianBarisPembelian = {
    judul: string;
    baris: BarisIsianBebas[];
    saatBerubah: (baris: BarisIsianBebas[]) => void;
    uuidGudang: string | null;
    /** Pemasok dokumen: harga beli terakhir dari pemasok ini diutamakan (audit kemudahan pakai #21). */
    uuidPemasok?: string | null;
    /** Tampilkan isian batch/kedaluwarsa & nomor seri (penerimaan, belanja stok). */
    pelacakan: boolean;
    /** Tampilkan pemeriksaan lokal (setelah tombol simpan ditekan). */
    periksa: boolean;
    galatServer: Record<string, string>;
    maksimal: number;
};

const kelasSel = 'px-2 py-2 align-top whitespace-normal';
const kelasKepala = 'px-2 text-label text-teks-sekunder';

/**
 * Tabel isian barang pembelian (PO, penerimaan tanpa PO, belanja stok; F-04 fase 1): cari produk berstok, pilih
 * satuan pembelian (konversi ke satuan dasar), jumlah, harga per satuan, diskon; batch/kedaluwarsa & nomor seri bila
 * `pelacakan`. Subtotal = perkiraan; server menghitung ulang.
 */
export default function IsianBarisPembelian({
    judul,
    baris,
    saatBerubah,
    uuidGudang,
    uuidPemasok = null,
    pelacakan,
    periksa,
    galatServer,
    maksimal,
}: PropsIsianBarisPembelian) {
    const Ubah = (kunci: string, perubahan: Partial<BarisIsianBebas>) =>
        saatBerubah(baris.map((b) => (b.Kunci === kunci ? { ...b, ...perubahan } : b)));
    const Hapus = (kunci: string) => saatBerubah(baris.filter((b) => b.Kunci !== kunci));
    const penuh = baris.length >= maksimal;

    return (
        <div className="flex flex-col gap-3">
            <PemilihProdukStok
                label="Tambah produk"
                uuidGudang={uuidGudang}
                buatUrl={(kata, gudang) => BuatUrlCariProdukPembelian(kata, gudang, uuidPemasok)}
                saatPilih={(produk) =>
                    saatBerubah([...baris, BuatBarisDariProduk(produk as unknown as ProdukPembelian)])
                }
                kecuali={baris.filter((b) => b.Pelacakan !== 'Batch').map((b) => b.UuidProduk)}
                disabled={penuh}
                keterangan={
                    penuh
                        ? `Batas ${maksimal.toLocaleString('id-ID')} baris per dokumen tercapai.`
                        : 'Hanya produk yang punya stok. Harga per satuan yang dipilih (misal per dus). Terisi dari pembelian terakhir bila ada.'
                }
            />
            {galatServer.Baris ? <GalatBidang>{galatServer.Baris}</GalatBidang> : null}

            {baris.length === 0 ? (
                <p className="rounded-kontrol border border-dashed border-garis-input px-3 py-2 text-isi text-teks-sekunder">
                    Belum ada barang. Cari produk di atas untuk menambah baris.
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <TabelForm label="Baris dokumen pembelian" lebar="dokumen">
                        <TableCaption className="sr-only">{judul}</TableCaption>
                        <TableHeader>
                            <TableRow className="border-garis hover:bg-transparent">
                                <TableHead scope="col" className={kelasKepala}>
                                    Produk
                                </TableHead>
                                <TableHead scope="col" className={`${kelasKepala} w-32`}>
                                    Satuan
                                </TableHead>
                                <TableHead scope="col" className={`${kelasKepala} w-36 text-right`}>
                                    Jumlah
                                </TableHead>
                                <TableHead scope="col" className={`${kelasKepala} w-44 text-right`}>
                                    Harga per satuan
                                </TableHead>
                                <TableHead scope="col" className={`${kelasKepala} w-40 text-right`}>
                                    Diskon
                                </TableHead>
                                <TableHead scope="col" className={`${kelasKepala} text-right`}>
                                    Subtotal
                                </TableHead>
                                <TableHead scope="col" className="px-2">
                                    <span className="sr-only">Aksi</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {baris.map((b, i) => {
                                const lokal = periksa ? PeriksaBaris(b, { wajibHarga: true, pelacakan }) : {};
                                const AmbilGalat = (bidang: keyof typeof lokal) =>
                                    galatServer[`Baris.${String(i)}.${bidang}`] ?? lokal[bidang];
                                const subtotal = HitungSubtotal(b.Jumlah, b.Harga, b.Diskon);
                                const simbol = AmbilSimbol(b);
                                const konversi = AmbilKonversi(b);
                                const adaPelacakan = pelacakan && b.Pelacakan !== 'Tidak';

                                return (
                                    <Fragment key={b.Kunci}>
                                        <TableRow className={adaPelacakan ? 'border-0' : 'border-garis'}>
                                            <th scope="row" className={`${kelasSel} text-left font-normal`}>
                                                <span className="block font-semibold break-words text-teks-utama">
                                                    {b.NamaProduk}
                                                </span>
                                                <span className="block font-mono text-keterangan text-teks-sekunder">
                                                    {b.Sku ?? 'Tanpa SKU'}
                                                </span>
                                                {galatServer[`Baris.${String(i)}.Produk`] ? (
                                                    <GalatBidang>
                                                        {galatServer[`Baris.${String(i)}.Produk`]}
                                                    </GalatBidang>
                                                ) : null}
                                            </th>
                                            <TableCell className={kelasSel}>
                                                {b.Satuan.length > 1 ? (
                                                    <PilihanCari
                                                        label={`Satuan ${b.NamaProduk}`}
                                                        aria-label={`Satuan ${b.NamaProduk}`}
                                                        nilai={b.UuidProdukSatuan ?? ''}
                                                        kosong={b.SimbolSatuan}
                                                        opsi={b.Satuan.filter(
                                                            (s) => !/^1(\.0+)?$/.test(s.Konversi),
                                                        ).map((s) => ({
                                                            Nilai: s.Uuid,
                                                            Label: s.Simbol,
                                                            Keterangan: `isi ${s.Konversi.replace(/\.?0+$/, '')} ${b.SimbolSatuan}`,
                                                        }))}
                                                        saatBerubah={(nilai) =>
                                                            Ubah(b.Kunci, {
                                                                UuidProdukSatuan: nilai === '' ? null : nilai,
                                                            })
                                                        }
                                                    />
                                                ) : (
                                                    <span className="inline-block py-2">{simbol}</span>
                                                )}
                                                {konversi !== '1' && !/^1(\.0+)?$/.test(konversi) ? (
                                                    <span className="block text-keterangan text-teks-sekunder">
                                                        1 {simbol} = {konversi.replace(/\.?0+$/, '')} {b.SimbolSatuan}
                                                    </span>
                                                ) : null}
                                            </TableCell>
                                            <TableCell className={kelasSel}>
                                                <BidangJumlah
                                                    label={`Jumlah ${b.NamaProduk}`}
                                                    labelTersembunyi
                                                    nilai={b.Jumlah}
                                                    saatBerubah={(nilai) => Ubah(b.Kunci, { Jumlah: nilai })}
                                                    akhiran={simbol}
                                                    galat={AmbilGalat('Jumlah')}
                                                    required
                                                />
                                            </TableCell>
                                            <TableCell className={kelasSel}>
                                                <BidangUang
                                                    label={`Harga ${b.NamaProduk}`}
                                                    labelTersembunyi
                                                    nilai={b.Harga}
                                                    saatBerubah={(nilai) => Ubah(b.Kunci, { Harga: nilai })}
                                                    galat={AmbilGalat('Harga')}
                                                    required
                                                />
                                            </TableCell>
                                            <TableCell className={kelasSel}>
                                                <BidangUang
                                                    label={`Diskon ${b.NamaProduk}`}
                                                    labelTersembunyi
                                                    nilai={b.Diskon}
                                                    saatBerubah={(nilai) => Ubah(b.Kunci, { Diskon: nilai })}
                                                    galat={AmbilGalat('Diskon')}
                                                />
                                            </TableCell>
                                            <TableCell
                                                className={`${kelasSel} text-right whitespace-nowrap tabular-nums`}
                                            >
                                                <span className="inline-block py-2">
                                                    {subtotal === null ? '—' : FormatRupiah(subtotal)}
                                                </span>
                                            </TableCell>
                                            <TableCell className={`${kelasSel} text-right`}>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Hapus baris ${b.NamaProduk}`}
                                                    onClick={() => Hapus(b.Kunci)}
                                                >
                                                    <Trash2Icon aria-hidden="true" />
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                        {adaPelacakan ? (
                                            <TableRow className="border-garis hover:bg-transparent">
                                                <TableCell colSpan={7} className="px-2 pt-0 pb-3 whitespace-normal">
                                                    <div className="grid gap-3 rounded-kontrol bg-permukaan-redup p-3 sm:grid-cols-2">
                                                        {b.Pelacakan === 'Batch' ? (
                                                            <>
                                                                <BidangTeks
                                                                    label={`Nomor batch ${b.NamaProduk}`}
                                                                    nilai={b.NomorBatch}
                                                                    saatBerubah={(nilai) =>
                                                                        Ubah(b.Kunci, { NomorBatch: nilai })
                                                                    }
                                                                    galat={AmbilGalat('NomorBatch')}
                                                                    maxLength={60}
                                                                    kode
                                                                    required
                                                                />
                                                                <PemilihTanggal
                                                                    label={`Kedaluwarsa ${b.NamaProduk} (opsional)`}
                                                                    nilai={b.TanggalKedaluwarsa}
                                                                    saatBerubah={(nilai) =>
                                                                        Ubah(b.Kunci, { TanggalKedaluwarsa: nilai })
                                                                    }
                                                                />
                                                            </>
                                                        ) : (
                                                            <div className="sm:col-span-2">
                                                                <BidangNomorSeri
                                                                    label={`Nomor seri ${b.NamaProduk}`}
                                                                    nilai={b.NomorSeri}
                                                                    saatBerubah={(nomor) =>
                                                                        Ubah(b.Kunci, { NomorSeri: nomor })
                                                                    }
                                                                    maksimal={1000}
                                                                    galat={AmbilGalat('NomorSeri')}
                                                                />
                                                            </div>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ) : null}
                                    </Fragment>
                                );
                            })}
                        </TableBody>
                    </TabelForm>
                </div>
            )}
        </div>
    );
}
