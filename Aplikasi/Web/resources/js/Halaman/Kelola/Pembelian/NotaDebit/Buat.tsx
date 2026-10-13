import { Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import { AlamatPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import { Checkbox } from '@/Komponen/Ui/checkbox';
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { BandingkanDesimal } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBuatNotaDebit } from '@/Tipe/Pembelian';

import { AlamatNotaDebit } from './Daftar';

/**
 * v6.07 (BR-04.10): nota debit atas satu faktur. Nilai dibagi server ke baris yang dipilih (semua baris bila tidak ada
 * yang dipilih) sebanding sisa nilainya; bagian stok yang masih ada menurunkan nilai persediaan, bagian yang sudah
 * terjual mengurangi HPP. Bagian yang melebihi sisa hutang kembali sebagai uang ke kas/bank.
 */
export default function HalamanBuatNotaDebit({
    Faktur,
    BatasPersetujuan,
    OpsiJenis,
    OpsiAkun,
    HariIni,
    BolehMelebihiBatas,
}: PropsBuatNotaDebit) {
    const formulir = useForm<{
        UuidFaktur: string;
        Jenis: string;
        Tanggal: string;
        NilaiKlaim: string;
        Baris: number[];
        Alasan: string;
        NomorNotaPemasok: string;
        UuidAkunRefund: string;
    }>({
        UuidFaktur: Faktur.Uuid,
        Jenis: 'SelisihHarga',
        Tanggal: HariIni,
        NilaiKlaim: '',
        Baris: [],
        Alasan: '',
        NomorNotaPemasok: '',
        UuidAkunRefund: OpsiAkun[0]?.Uuid ?? '',
    });
    const [dipilih, AturDipilih] = useState<number[]>([]);
    const melebihiSisa =
        formulir.data.NilaiKlaim !== '' && BandingkanDesimal(formulir.data.NilaiKlaim, Faktur.SisaHutang) > 0;
    const melebihiBatas =
        !BolehMelebihiBatas &&
        formulir.data.NilaiKlaim !== '' &&
        BandingkanDesimal(formulir.data.NilaiKlaim, BatasPersetujuan) > 0;

    const UbahPilihan = (id: number, centang: boolean) => {
        const baru = centang ? [...dipilih, id] : dipilih.filter((x) => x !== id);
        AturDipilih(baru);
        formulir.setData('Baris', baru);
    };

    // Isian kosong (nomor nota, akun uang kembali) dijadikan null oleh server; akun hanya dipakai bila ada uang kembali.
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(AlamatNotaDebit, { preserveScroll: true });
    };

    return (
        <TataLetakAplikasi
            judul={`Nota debit ${Faktur.Nomor}`}
            jejak={[
                { label: 'Faktur pembelian', href: `${AlamatPembelian}/faktur` },
                { label: Faktur.Nomor, href: `${AlamatPembelian}/faktur/${Faktur.Uuid}` },
            ]}
        >
            <form onSubmit={Kirim} noValidate aria-label="Buat nota debit" className="flex flex-col gap-4">
                <Panel
                    judul="Nota debit"
                    idJudul="judul-nota-debit"
                    keterangan="Mengurangi hutang faktur tanpa ada barang yang kembali. Bagian stok yang masih ada menurunkan nilai persediaan; bagian yang sudah terjual mengurangi HPP."
                >
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <BidangPilihan
                            label="Sebab"
                            nilai={formulir.data.Jenis}
                            opsi={OpsiJenis}
                            saatBerubah={(nilai) => formulir.setData('Jenis', nilai)}
                            galat={formulir.errors.Jenis}
                            required
                        />
                        <BidangUang
                            label="Nilai nota debit (sebelum PPN)"
                            nilai={formulir.data.NilaiKlaim}
                            saatBerubah={(nilai) => formulir.setData('NilaiKlaim', nilai)}
                            galat={formulir.errors.NilaiKlaim}
                            keterangan={
                                Faktur.PpnDikreditkan
                                    ? 'PPN masukan dikurangi sebanding PPN faktur.'
                                    : 'Faktur ini tanpa PPN masukan yang dikreditkan.'
                            }
                            required
                        />
                        <PemilihTanggal
                            id="tanggal-nota-debit"
                            label="Tanggal nota"
                            nilai={formulir.data.Tanggal}
                            min={Faktur.Tanggal}
                            max={HariIni}
                            saatBerubah={(nilai) => formulir.setData('Tanggal', nilai)}
                            galat={formulir.errors.Tanggal}
                            required
                        />
                        <BidangTeks
                            label="Nomor nota dari pemasok (opsional)"
                            nilai={formulir.data.NomorNotaPemasok}
                            saatBerubah={(nilai) => formulir.setData('NomorNotaPemasok', nilai)}
                            galat={formulir.errors.NomorNotaPemasok}
                            maxLength={60}
                        />
                    </div>
                    <BidangTeksPanjang
                        label="Alasan"
                        nilai={formulir.data.Alasan}
                        saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                        galat={formulir.errors.Alasan}
                        baris={2}
                        maksimal={255}
                        required
                    />
                    {melebihiSisa ? (
                        <div className="flex flex-col gap-3">
                            <Pemberitahuan jenis="info" judul="Melebihi sisa hutang faktur">
                                Sisa hutang faktur {FormatRupiah(Faktur.SisaHutang)}. Selebihnya diterima dari pemasok
                                sebagai uang; pilih akun kas/bank penerimanya.
                            </Pemberitahuan>
                            <BidangPilihan
                                label="Uang kembali ke"
                                nilai={formulir.data.UuidAkunRefund}
                                opsi={OpsiAkun.map((a) => ({ Nilai: a.Uuid, Label: `${a.Kode} ${a.Nama}` }))}
                                saatBerubah={(nilai) => formulir.setData('UuidAkunRefund', nilai)}
                                galat={formulir.errors.UuidAkunRefund}
                                required
                            />
                        </div>
                    ) : null}
                    {melebihiBatas ? (
                        <Pemberitahuan jenis="peringatan" judul="Di atas batas persetujuan pembelian">
                            Nota di atas {FormatRupiah(BatasPersetujuan)} hanya bisa dicatat oleh pemegang izin
                            menyetujui pesanan pembelian.
                        </Pemberitahuan>
                    ) : null}
                </Panel>

                <Panel
                    judul="Baris faktur yang diklaim"
                    idJudul="judul-baris-nota-debit"
                    keterangan={`${Faktur.Nomor} | ${FormatTanggal(Faktur.Tanggal)}${Faktur.NamaPemasok ? ` | ${Faktur.NamaPemasok}` : ''} | Tanpa centang = dibagi ke semua baris.`}
                >
                    <TabelForm label="Baris faktur" lebar="sedang">
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-10">
                                    <span className="sr-only">Pilih</span>
                                </TableHead>
                                <TableHead>Produk</TableHead>
                                <TableHead className="text-right">Nilai faktur</TableHead>
                                <TableHead className="text-right">Sisa bisa diklaim</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {Faktur.Baris.map((b) => (
                                <TableRow key={b.Id}>
                                    <TableCell>
                                        <Checkbox
                                            aria-label={`Pilih ${b.NamaProduk}`}
                                            checked={dipilih.includes(b.Id)}
                                            disabled={BandingkanDesimal(b.SisaKlaim, '0') <= 0}
                                            onCheckedChange={(status) => UbahPilihan(b.Id, status === true)}
                                            className="border-garis-input"
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <span className="block break-words">{b.NamaProduk}</span>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">{FormatRupiah(b.Nilai)}</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {FormatRupiah(b.SisaKlaim)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </TabelForm>
                </Panel>

                <BilahAksiForm>
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan nota debit
                    </Tombol>
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={`${AlamatPembelian}/faktur/${Faktur.Uuid}`}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
