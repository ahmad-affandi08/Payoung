import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import { AlamatPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBuatBiayaTambahan } from '@/Tipe/Pembelian';

import { AlamatBiayaTambahan } from './Daftar';

/**
 * v3.41: catat biaya pihak ketiga atas satu penerimaan barang. Server membagi biaya ke baris (sebanding nilai atau
 * jumlah), lalu memisahkan bagian stok yang masih ada (nilai stok naik) dari bagian yang sudah terjual (HPP).
 */
export default function HalamanBuatBiayaTambahan({
    Penerimaan,
    OpsiJenis,
    OpsiDasar,
    OpsiAkun,
    OpsiPemasok,
    HariIni,
}: PropsBuatBiayaTambahan) {
    const formulir = useForm({
        UuidPenerimaan: Penerimaan.Uuid,
        Jenis: 'Ongkir',
        DasarAlokasi: 'Nilai',
        Tanggal: HariIni,
        Jumlah: '',
        UuidAkun: OpsiAkun[0]?.Uuid ?? '',
        // Penagih biasanya pemasok penerimaan itu sendiri (bisa diganti atau dikosongkan).
        UuidPemasok: OpsiPemasok.some((p) => p.Uuid === Penerimaan.UuidPemasok) ? (Penerimaan.UuidPemasok ?? '') : '',
        Catatan: '',
    });
    const adaPelacakan = Penerimaan.Baris.some((b) => b.Pelacakan);

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.transform((data) => ({ ...data, UuidPemasok: data.UuidPemasok === '' ? null : data.UuidPemasok }));
        formulir.post(AlamatBiayaTambahan, { preserveScroll: true });
    };

    return (
        <TataLetakAplikasi
            judul={`Biaya tambahan ${Penerimaan.Nomor}`}
            jejak={[
                { label: 'Penerimaan barang', href: `${AlamatPembelian}/penerimaan` },
                { label: Penerimaan.Nomor, href: `${AlamatPembelian}/penerimaan/${Penerimaan.Uuid}` },
            ]}
        >
            <form onSubmit={Kirim} noValidate aria-label="Catat biaya tambahan" className="flex flex-col gap-4">
                <Panel
                    judul="Biaya"
                    idJudul="judul-biaya-tambahan"
                    keterangan="Dibayar dari kas/bank dan langsung dijurnal: bagian untuk stok yang masih ada menambah nilai persediaan, bagian untuk barang yang sudah terjual masuk HPP."
                >
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <BidangPilihan
                            label="Jenis biaya"
                            nilai={formulir.data.Jenis}
                            opsi={OpsiJenis}
                            saatBerubah={(nilai) => formulir.setData('Jenis', nilai)}
                            galat={formulir.errors.Jenis}
                            required
                        />
                        <BidangUang
                            label="Jumlah"
                            nilai={formulir.data.Jumlah}
                            saatBerubah={(nilai) => formulir.setData('Jumlah', nilai)}
                            galat={formulir.errors.Jumlah}
                            required
                        />
                        <PemilihTanggal
                            id="tanggal-biaya-tambahan"
                            label="Tanggal bayar"
                            nilai={formulir.data.Tanggal}
                            min={Penerimaan.Tanggal}
                            max={HariIni}
                            saatBerubah={(nilai) => formulir.setData('Tanggal', nilai)}
                            galat={formulir.errors.Tanggal}
                            required
                        />
                        <BidangPilihan
                            label="Dibayar dari"
                            nilai={formulir.data.UuidAkun}
                            opsi={OpsiAkun.map((a) => ({ Nilai: a.Uuid, Label: `${a.Kode} ${a.Nama}` }))}
                            saatBerubah={(nilai) => formulir.setData('UuidAkun', nilai)}
                            galat={formulir.errors.UuidAkun}
                            required
                        />
                        <BidangPilihan
                            label="Dibagi ke barang"
                            nilai={formulir.data.DasarAlokasi}
                            opsi={OpsiDasar}
                            saatBerubah={(nilai) => formulir.setData('DasarAlokasi', nilai)}
                            galat={formulir.errors.DasarAlokasi}
                            required
                        />
                        <BidangPilihan
                            label="Ditagih oleh (opsional)"
                            nilai={formulir.data.UuidPemasok}
                            kosong="Tanpa pemasok"
                            opsi={OpsiPemasok.filter((p) => p.Aktif).map((p) => ({
                                Nilai: p.Uuid,
                                Label: `${p.Nama} (${p.Kode})`,
                            }))}
                            saatBerubah={(nilai) => formulir.setData('UuidPemasok', nilai)}
                            galat={formulir.errors.UuidPemasok}
                        />
                    </div>
                    <BidangTeksPanjang
                        label="Catatan (opsional)"
                        nilai={formulir.data.Catatan}
                        saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                        galat={formulir.errors.Catatan}
                        baris={2}
                        maksimal={500}
                    />
                </Panel>

                <Panel
                    judul="Barang yang dibebani"
                    idJudul="judul-barang-biaya"
                    keterangan={`${Penerimaan.Nomor} | ${FormatTanggal(Penerimaan.Tanggal)} | ${Penerimaan.NamaGudang}${Penerimaan.NamaPemasok ? ` | ${Penerimaan.NamaPemasok}` : ''}${adaPelacakan ? ' | Barang ber-batch/nomor seri dibebankan ke HPP.' : ''}`}
                >
                    <TabelForm label="Barang penerimaan" lebar="sedang">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Produk</TableHead>
                                <TableHead className="text-right">Jumlah (setelah retur)</TableHead>
                                <TableHead className="text-right">Nilai</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {Penerimaan.Baris.map((b) => (
                                <TableRow key={b.Id}>
                                    <TableCell>
                                        <span className="block break-words">{b.NamaProduk}</span>
                                        <span className="block font-mono text-keterangan text-teks-sekunder">
                                            {b.Sku ?? 'Tanpa SKU'}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {FormatJumlahStok(b.Jumlah)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">{FormatRupiah(b.Nilai)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </TabelForm>
                </Panel>

                <BilahAksiForm>
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan biaya tambahan
                    </Tombol>
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={`${AlamatPembelian}/penerimaan/${Penerimaan.Uuid}`}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
