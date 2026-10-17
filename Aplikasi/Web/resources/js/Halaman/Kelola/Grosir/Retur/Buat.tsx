import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import TombolPindaiNomorSeri from '@/Komponen/Persediaan/TombolPindaiNomorSeri';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import Tombol from '@/Komponen/Formulir/Tombol';
import {
    AlamatGrosir,
    HalamanGrosir,
    KeteranganGrosir,
    KartuKeteranganGrosir,
} from '@/Komponen/Grosir/BagianDokumenGrosir';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import Panel from '@/Komponen/Kelola/Panel';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import { TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import {
    BandingkanDesimal,
    BulatkanDesimal,
    CekDesimalValid,
    JumlahkanDesimal,
    KalikanDesimal,
    SkalaUang,
} from '@/Pustaka/HitungDesimal';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisDetailSuratJalan, PropsBuatRetur } from '@/Tipe/Grosir';

const alamat = `${AlamatGrosir}/retur`;
const kelasSel = 'px-2 py-2 align-top whitespace-normal';
const kelasKepala = 'px-2 text-label text-teks-sekunder';

/** Jumlah yang diisi operator per baris surat jalan, ditambah kondisi barangnya. */
type IsianBarisRetur = { Jumlah: string; Kondisi: string; NomorSeri: string[] };

/**
 * Membuat retur grosir atas satu surat jalan (F-12, §9.7, BR-12.7, J-12.4).
 *
 * Yang dikembalikan hanya bisa barang yang benar-benar diserahkan, jadi barisnya tetap: daftarnya adalah baris surat
 * jalan, dan yang diisi operator cuma jumlah & kondisinya. Harga, HPP, dan tarif pajaknya tidak ada di formulir ini
 * karena semuanya disalin dari snapshot penyerahan — retur harus membalik angka yang dulu diakui, bukan angka hari ini.
 */
export default function HalamanBuatReturGrosir({
    SuratJalan,
    Baris,
    OpsiKondisi,
    OpsiAkunRefund,
    HariIni,
    Izin,
}: PropsBuatRetur) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const bawaanKondisi = OpsiKondisi[0]?.Nilai ?? 'LayakJual';
    const [isian, AturIsian] = useState<Record<number, IsianBarisRetur>>({});
    const [tanggal, AturTanggal] = useState(HariIni);
    const [alasan, AturAlasan] = useState('');
    const [catatan, AturCatatan] = useState('');
    const [akunRefund, AturAkunRefund] = useState('');
    const [memproses, AturMemproses] = useState(false);

    const Ambil = (urutan: number): IsianBarisRetur =>
        isian[urutan] ?? { Jumlah: '', Kondisi: bawaanKondisi, NomorSeri: [] };
    const Ubah = (urutan: number, perubahan: Partial<IsianBarisRetur>) =>
        AturIsian((lama) => ({ ...lama, [urutan]: { ...Ambil(urutan), ...perubahan } }));

    const bolehDiretur = Baris.filter((b) => BandingkanDesimal(b.SisaRetur, '0') > 0);
    // Produk bernomor seri: jumlah retur = banyaknya nomor seri yang dicentang (satu per unit).
    const AdalahSeri = (b: BarisDetailSuratJalan) => b.SeriBisaDiretur.length > 0;
    const terisi = bolehDiretur
        .map((b) => {
            const nilai = Ambil(b.Urutan);

            return { baris: b, ...nilai, Jumlah: AdalahSeri(b) ? String(nilai.NomorSeri.length) : nilai.Jumlah };
        })
        .filter((b) => CekDesimalValid(b.Jumlah) && BandingkanDesimal(b.Jumlah, '0') > 0);
    const lebihDariSisa = terisi.filter((b) => BandingkanDesimal(b.Jumlah, b.baris.SisaRetur) > 0);
    // Perkiraan kasar: harga × jumlah. Diskon baris dialokasikan proporsional & pajaknya dihitung server.
    const perkiraan = BulatkanDesimal(
        JumlahkanDesimal(['0', ...terisi.map((b) => KalikanDesimal(b.baris.Harga, b.Jumlah, SkalaUang))]),
        SkalaUang,
    );
    const siap = terisi.length > 0 && lebihDariSisa.length === 0 && alasan.trim().length >= 5;

    const Kirim = () =>
        router.post(
            alamat,
            {
                UuidSuratJalan: SuratJalan.Uuid,
                Tanggal: tanggal,
                Alasan: alasan,
                Catatan: catatan === '' ? null : catatan,
                UuidAkunRefund: akunRefund === '' ? null : akunRefund,
                Baris: terisi.map((b) => ({
                    Urutan: b.baris.Urutan,
                    Jumlah: b.Jumlah,
                    Kondisi: b.Kondisi,
                    ...(AdalahSeri(b.baris) ? { NomorSeri: b.NomorSeri } : {}),
                })),
            },
            { onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );

    return (
        <HalamanGrosir
            judul={`Retur surat jalan ${SuratJalan.Nomor}`}
            keterangan="Isi jumlah yang dikembalikan pembeli per baris. Barang layak jual kembali ke stok Toko, barang rusak masuk lokasi stok Rusak outlet."
            izin={Izin}
            objek="retur grosir"
        >
            <Pemberitahuan jenis="info" judul="Retur membalik angka penyerahan">
                Harga & HPP-nya diambil dari surat jalan ini, bukan dari harga hari ini.{' '}
                {SuratJalan.NomorFaktur === null
                    ? 'Karena penyerahan ini belum difakturkan, returnya mengurangi akun Piutang Belum Difakturkan.'
                    : `Karena sudah difakturkan (${SuratJalan.NomorFaktur}), returnya menjadi nota kredit yang mengurangi piutang faktur itu.`}
            </Pemberitahuan>

            <KartuKeteranganGrosir>
                <KeteranganGrosir label="Pelanggan">{SuratJalan.NamaPelanggan}</KeteranganGrosir>
                <KeteranganGrosir label="Outlet penjual">{SuratJalan.KodeOutlet}</KeteranganGrosir>
                <KeteranganGrosir label="Diserahkan">{FormatTanggal(SuratJalan.Tanggal)}</KeteranganGrosir>
            </KartuKeteranganGrosir>

            <Panel judul="Barang yang dikembalikan">
                {bolehDiretur.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">
                        Seluruh barang surat jalan ini sudah diretur. Tidak ada sisa yang bisa dikembalikan lagi.
                    </p>
                ) : (
                    <TabelForm label="Barang yang dikembalikan">
                        <TableCaption className="sr-only">Barang yang dikembalikan</TableCaption>
                        <TableHeader>
                            <TableRow>
                                <TableHead className={kelasKepala}>Produk</TableHead>
                                <TableHead className={kelasKepala}>Diserahkan</TableHead>
                                <TableHead className={kelasKepala}>Sisa bisa diretur</TableHead>
                                <TableHead className={kelasKepala}>Jumlah retur</TableHead>
                                <TableHead className={kelasKepala}>Kondisi</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {bolehDiretur.map((b) => {
                                const nilai = Ambil(b.Urutan);
                                const lebih =
                                    CekDesimalValid(nilai.Jumlah) && BandingkanDesimal(nilai.Jumlah, b.SisaRetur) > 0;

                                return (
                                    <TableRow key={b.Urutan}>
                                        <TableCell className={kelasSel}>
                                            <span className="flex flex-col">
                                                <span className="font-semibold break-words">{b.NamaProduk}</span>
                                                <span className="font-mono text-keterangan text-teks-sekunder">
                                                    {b.Sku ?? 'Tanpa SKU'} | {FormatRupiah(b.Harga)}
                                                </span>
                                            </span>
                                        </TableCell>
                                        <TableCell className={`${kelasSel} text-right tabular-nums`}>
                                            {FormatJumlahStok(b.Jumlah, b.SimbolSatuan)}
                                        </TableCell>
                                        <TableCell className={`${kelasSel} text-right tabular-nums`}>
                                            {FormatJumlahStok(b.SisaRetur, b.SimbolSatuan)}
                                        </TableCell>
                                        <TableCell className={kelasSel}>
                                            {AdalahSeri(b) ? (
                                                <div className="flex flex-col gap-2">
                                                    <GrupCentang
                                                        legenda={`Nomor seri ${b.NamaProduk} yang dikembalikan`}
                                                        opsi={b.SeriBisaDiretur.map((n) => ({ nilai: n, label: n }))}
                                                        terpilih={nilai.NomorSeri}
                                                        saatBerubah={(daftar) => Ubah(b.Urutan, { NomorSeri: daftar })}
                                                    />
                                                    <TombolPindaiNomorSeri
                                                        label={`Pindai nomor seri retur ${b.NamaProduk}`}
                                                        daftar={nilai.NomorSeri}
                                                        tersedia={b.SeriBisaDiretur}
                                                        saatBerubah={(daftar) => Ubah(b.Urutan, { NomorSeri: daftar })}
                                                    />
                                                </div>
                                            ) : (
                                                <>
                                                    <BidangJumlah
                                                        label={`Jumlah retur ${b.NamaProduk}`}
                                                        labelTersembunyi
                                                        nilai={nilai.Jumlah}
                                                        saatBerubah={(teks) => Ubah(b.Urutan, { Jumlah: teks })}
                                                        akhiran={b.SimbolSatuan}
                                                        galat={lebih ? 'Melebihi sisa yang bisa diretur.' : undefined}
                                                    />
                                                    {b.Rincian !== null ? (
                                                        <p className="mt-1 text-keterangan text-teks-sekunder">
                                                            Kembali ke batch asal: {b.Rincian}
                                                        </p>
                                                    ) : null}
                                                </>
                                            )}
                                        </TableCell>
                                        <TableCell className={kelasSel}>
                                            <BidangPilihan
                                                label={`Kondisi ${b.NamaProduk}`}
                                                labelTersembunyi
                                                nilai={nilai.Kondisi}
                                                opsi={OpsiKondisi.map((o) => ({ Nilai: o.Nilai, Label: o.Label }))}
                                                saatBerubah={(teks) => Ubah(b.Urutan, { Kondisi: teks })}
                                            />
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </TabelForm>
                )}
                <p className="mt-3 text-isi text-teks-utama">
                    {terisi.length} baris diisi | perkiraan nilai barang{' '}
                    <span className="font-semibold tabular-nums">{FormatRupiah(perkiraan)}</span>
                    <span className="text-teks-sekunder"> (sebelum alokasi diskon & pajak; dihitung server)</span>
                </p>
            </Panel>

            <Panel judul="Retur">
                <div className="grid gap-4 sm:grid-cols-2">
                    <PemilihTanggal
                        label="Tanggal retur"
                        nilai={tanggal}
                        saatBerubah={AturTanggal}
                        galat={props.errors.Tanggal}
                        required
                    />
                    <BidangTeksPanjang
                        label="Alasan retur"
                        nilai={alasan}
                        saatBerubah={AturAlasan}
                        keterangan="Minimal 5 karakter. Muncul di daftar retur dan laporan, jadi tulis sebabnya, bukan cuma “retur”."
                        galat={props.errors.Alasan}
                        maksimal={255}
                        required
                    />
                </div>
                <BidangTeksPanjang
                    label="Catatan"
                    nilai={catatan}
                    saatBerubah={AturCatatan}
                    galat={props.errors.Catatan}
                    maksimal={500}
                />
                {SuratJalan.NomorFaktur !== null ? (
                    <BidangPilihan
                        label="Kembalikan uang dari akun"
                        nilai={akunRefund}
                        opsi={[
                            { Nilai: '', Label: 'Tidak ada pengembalian uang' },
                            ...OpsiAkunRefund.map((a) => ({ Nilai: a.Uuid, Label: `${a.Kode} ${a.Nama}` })),
                        ]}
                        saatBerubah={AturAkunRefund}
                        galat={props.errors.UuidAkunRefund}
                    />
                ) : null}
                {SuratJalan.NomorFaktur !== null ? (
                    <p className="text-keterangan text-teks-sekunder">
                        Isi bila pembeli sudah membayar faktur ini sehingga nilai retur melebihi sisa tagihan:
                        selebihnya dikembalikan sebagai uang dari akun ini.
                    </p>
                ) : null}
            </Panel>

            <BilahAksiForm>
                <Button asChild variant="outline" type="button">
                    <Link href={`${AlamatGrosir}/surat-jalan/${SuratJalan.Uuid}`}>Batal</Link>
                </Button>
                <Tombol type="button" onClick={Kirim} memproses={memproses} disabled={!siap}>
                    Posting retur
                </Tombol>
            </BilahAksiForm>
        </HalamanGrosir>
    );
}
