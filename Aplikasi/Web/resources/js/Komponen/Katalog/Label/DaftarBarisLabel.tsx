import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import { SaringJumlah, type BarisCetak } from '@/Komponen/Katalog/Label/BantuanLabel';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import { TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { FormatRupiah } from '@/Pustaka/Format';

type PropsDaftarBarisLabel = {
    baris: readonly BarisCetak[];
    saatUbahJumlah: (uuid: string, jumlah: number) => void;
    saatPilihBarcode: (uuid: string, barcode: string) => void;
    saatHapus: (uuid: string) => void;
    /** Izin `produk.kelola`: tombol "Buat barcode" per baris. */
    bolehBuatBarcode: boolean;
    saatBuatBarcode: (uuid: string) => void;
};

/**
 * PRD-10: daftar barang yang akan dicetak, satu baris per satuan produk dengan jumlah stikernya sendiri. Barang tanpa
 * barcode ditandai jelas dan tetap bisa dicetak sebagai label harga; barcode internal hanya dibuat bila pengguna
 * memintanya (tombol "Buat barcode").
 */
export default function DaftarBarisLabel({
    baris,
    saatUbahJumlah,
    saatPilihBarcode,
    saatHapus,
    bolehBuatBarcode,
    saatBuatBarcode,
}: PropsDaftarBarisLabel) {
    return (
        <TabelForm label="Barang yang dicetak" lebar="lebar">
            <TableCaption className="sr-only">Barang yang dicetak beserta jumlah stikernya</TableCaption>
            <TableHeader>
                <TableRow>
                    <TableHead scope="col">Barang</TableHead>
                    <TableHead scope="col" className="w-64">
                        Barcode
                    </TableHead>
                    <TableHead scope="col" className="w-32 text-right">
                        Harga
                    </TableHead>
                    <TableHead scope="col" className="w-28">
                        Jumlah stiker
                    </TableHead>
                    <TableHead scope="col" className="w-24">
                        <span className="sr-only">Aksi</span>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {baris.map((b) => (
                    <TableRow key={b.Uuid}>
                        <TableCell className="align-top whitespace-normal">
                            <span className="block font-semibold break-words">{b.Nama}</span>
                            <span className="block text-keterangan text-teks-sekunder">
                                {[b.NamaSatuan, b.Sku].filter(Boolean).join(' | ')}
                            </span>
                        </TableCell>
                        <TableCell className="align-top whitespace-normal">
                            {b.Barcode.length === 0 ? (
                                <div className="flex flex-col items-start gap-1">
                                    <span className="text-keterangan font-semibold text-peringatan">
                                        Belum punya barcode
                                    </span>
                                    <span className="text-keterangan text-teks-sekunder">
                                        Dicetak sebagai label harga saja.
                                    </span>
                                    {bolehBuatBarcode ? (
                                        <Tombol varian="sekunder" onClick={() => saatBuatBarcode(b.Uuid)}>
                                            Buat barcode
                                        </Tombol>
                                    ) : null}
                                </div>
                            ) : b.Barcode.length === 1 ? (
                                <span className="font-mono text-label break-all">{b.Barcode[0]}</span>
                            ) : (
                                <BidangPilihan
                                    label={`Barcode ${b.Nama} ${b.NamaSatuan}`}
                                    labelTersembunyi
                                    nilai={b.BarcodeDipilih ?? ''}
                                    opsi={b.Barcode.map((kode) => ({ Nilai: kode, Label: kode }))}
                                    saatBerubah={(kode) => saatPilihBarcode(b.Uuid, kode)}
                                />
                            )}
                        </TableCell>
                        <TableCell className="text-right align-top tabular-nums">
                            {b.Harga === null ? (
                                <span className="text-teks-sekunder">Belum ada harga</span>
                            ) : (
                                FormatRupiah(b.Harga)
                            )}
                        </TableCell>
                        <TableCell className="align-top">
                            <BidangTeks
                                label={`Jumlah stiker ${b.Nama} ${b.NamaSatuan}`}
                                labelTersembunyi
                                nilai={String(b.Jumlah)}
                                saatBerubah={(teks) => saatUbahJumlah(b.Uuid, SaringJumlah(teks))}
                                inputMode="numeric"
                                maxLength={3}
                            />
                        </TableCell>
                        <TableCell className="align-top">
                            <Tombol varian="sekunder" onClick={() => saatHapus(b.Uuid)}>
                                Hapus
                            </Tombol>
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </TabelForm>
    );
}
