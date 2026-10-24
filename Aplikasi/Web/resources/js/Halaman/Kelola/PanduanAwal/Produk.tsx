import { Link, useForm } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';

import AjakanTambahBatas from '@/Komponen/Kelola/AjakanTambahBatas';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import RingkasanGalatFormulir, { FokusGalatPertama } from '@/Komponen/PanduanAwal/RingkasanGalatFormulir';
import TataLetakPanduan from '@/Komponen/PanduanAwal/TataLetakPanduan';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { Card } from '@/Komponen/Ui/card';
import { Checkbox } from '@/Komponen/Ui/checkbox';
import { FieldDescription, FieldError, FieldLegend, FieldSet } from '@/Komponen/Ui/field';
import { TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { UraiDaftarTempel } from '@/Pustaka/UraiDaftarTempel';
import { CekBatasPenuh, FormatBatas, type Batas } from '@/Tipe/Organisasi';
import { AlamatPanduan, type ProdukContoh, type PropsProdukPanduan } from '@/Tipe/PanduanAwal';

const maksimalBarisManual = 20;

/** Sisa kuota SKU paket; null = tanpa batas. */
function HitungSisaSku(batas: Batas): number | null {
    return batas.Batas === null ? null : Math.max(0, batas.Batas - batas.Terpakai);
}

/** Langkah 4 F-01: (a) produk contoh dari template, (d) tambah produk cepat. Import Excel ada di F-03. */
export default function HalamanProdukPanduan({
    Progres,
    AdaTemplate,
    ProdukContoh,
    Kategori,
    Produk,
    JumlahProduk,
    BatasSku,
}: PropsProdukPanduan) {
    const kuotaPenuh = CekBatasPenuh(BatasSku);

    return (
        <TataLetakPanduan progres={Progres} langkah="Produk" lanjut="tandai-selesai">
            <p className="text-isi text-teks-sekunder">
                Tambahkan beberapa produk supaya kasir bisa langsung berjualan. Produk lengkap (varian, resep, stok
                awal) bisa diatur nanti di menu Produk. Kuota paket:{' '}
                <span className="font-semibold text-teks-utama">{FormatBatas(BatasSku, 'SKU produk')}</span>.
            </p>

            {kuotaPenuh ? (
                <Pemberitahuan jenis="peringatan" judul="Kuota produk paket sudah penuh">
                    {FormatBatas(BatasSku, 'SKU produk')}.{' '}
                    <AjakanTambahBatas teksLisensi="Minta berkas lisensi dengan batas lebih besar ke penjual lisensi Payoung.">
                        Tambah kuota di{' '}
                        <Link href="/kelola/langganan" className="font-semibold text-brand underline">
                            menu Langganan
                        </Link>{' '}
                        untuk menambah produk baru.
                    </AjakanTambahBatas>
                </Pemberitahuan>
            ) : null}

            <BagianProdukContoh
                adaTemplate={AdaTemplate}
                produkContoh={ProdukContoh}
                batasSku={BatasSku}
                kuotaPenuh={kuotaPenuh}
            />
            <FormProdukCepat kategori={Kategori} kuotaPenuh={kuotaPenuh} />
            <TabelProduk produk={Produk} jumlahProduk={JumlahProduk} />
        </TataLetakPanduan>
    );
}

type PilihanContoh = Record<string, { Dipilih: boolean; Harga: string }>;

type PropsBagianProdukContoh = {
    adaTemplate: boolean;
    produkContoh: ProdukContoh[];
    batasSku: Batas;
    kuotaPenuh: boolean;
};

function BagianProdukContoh({ adaTemplate, produkContoh, batasSku, kuotaPenuh }: PropsBagianProdukContoh) {
    const elemenFormulir = useRef<HTMLFormElement>(null);
    const [terkirim, AturTerkirim] = useState<string[]>([]);
    const formulir = useForm<{ ProdukContoh: { Nama: string; Harga: string }[] }>({ ProdukContoh: [] });
    const [pilihan, AturPilihan] = useState<PilihanContoh>(() =>
        Object.fromEntries(produkContoh.map((item) => [item.Nama, { Dipilih: !item.SudahAda, Harga: item.Harga }])),
    );
    const bisaDipilih = produkContoh.filter((item) => !item.SudahAda);
    const terpilih = bisaDipilih
        .filter((item) => pilihan[item.Nama]?.Dipilih)
        .map((item) => ({ Nama: item.Nama, Harga: pilihan[item.Nama]?.Harga ?? item.Harga }));
    const sisaSku = HitungSisaSku(batasSku);
    const melebihiKuota = sisaSku !== null && terpilih.length > sisaSku;
    const semuaDipilih = bisaDipilih.length > 0 && terpilih.length === bisaDipilih.length;
    const galat = formulir.errors as Record<string, string | undefined>;

    const UbahBaris = (nama: string, ubahan: Partial<{ Dipilih: boolean; Harga: string }>) =>
        AturPilihan((lama) => ({
            ...lama,
            [nama]: { Dipilih: lama[nama]?.Dipilih ?? false, Harga: lama[nama]?.Harga ?? '', ...ubahan },
        }));

    const PilihSemua = (dipilih: boolean) =>
        AturPilihan((lama) =>
            Object.fromEntries(
                Object.entries(lama).map(([nama, baris]) => [
                    nama,
                    bisaDipilih.some((item) => item.Nama === nama) ? { ...baris, Dipilih: dipilih } : baris,
                ]),
            ),
        );

    const AmbilGalatBaris = (nama: string, bidang: 'Nama' | 'Harga'): string | undefined => {
        const indeks = terkirim.indexOf(nama);

        return indeks < 0 ? undefined : galat[`ProdukContoh.${String(indeks)}.${bidang}`];
    };

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturTerkirim(terpilih.map((item) => item.Nama));
        formulir.transform(() => ({ ProdukContoh: terpilih }));
        formulir.post(AlamatPanduan.ProdukContoh, {
            preserveScroll: true,
            onError: () => FokusGalatPertama(elemenFormulir.current),
        });
    };

    return (
        <Card
            aria-labelledby="judul-produk-contoh"
            role="region"
            className="gap-3 p-4 sm:p-6 rounded-panel shadow-none"
        >
            <h2 id="judul-produk-contoh" className="text-subjudul font-semibold text-teks-utama">
                Produk contoh dari template
            </h2>
            {!adaTemplate || produkContoh.length === 0 ? (
                <p className="text-isi text-teks-sekunder">
                    Template Anda belum punya contoh produk. Tambahkan produk sendiri di bawah.
                </p>
            ) : (
                <form ref={elemenFormulir} onSubmit={Kirim} className="flex flex-col gap-3" noValidate>
                    <p className="text-isi text-teks-sekunder">
                        Pilih produk yang Anda jual dan sesuaikan harganya. Produk yang namanya sudah ada tidak
                        ditambahkan lagi.
                    </p>
                    <RingkasanGalatFormulir galat={formulir.errors} />
                    {galat.ProdukContoh ? (
                        <FieldError className="text-keterangan font-semibold">{galat.ProdukContoh}</FieldError>
                    ) : null}
                    <div className="max-h-120 overflow-auto rounded-panel border border-garis">
                        <TabelForm label="Produk yang akan dibuat" lebar="sedang">
                            <TableCaption className="sr-only">Produk contoh dari template</TableCaption>
                            <TableHeader className="sticky top-0 z-10 bg-permukaan">
                                <TableRow>
                                    <TableHead scope="col" className="w-12 px-4">
                                        <Checkbox
                                            aria-label="Pilih semua"
                                            checked={
                                                semuaDipilih ? true : terpilih.length > 0 ? 'indeterminate' : false
                                            }
                                            disabled={bisaDipilih.length === 0}
                                            onCheckedChange={(nilai) => PilihSemua(nilai === true)}
                                        />
                                    </TableHead>
                                    <TableHead scope="col" className="px-4">
                                        Nama
                                    </TableHead>
                                    <TableHead scope="col" className="px-4">
                                        Kategori
                                    </TableHead>
                                    <TableHead scope="col" className="px-4">
                                        Satuan
                                    </TableHead>
                                    <TableHead scope="col" className="w-48 px-4 text-right">
                                        Harga jual
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {produkContoh.map((item) => {
                                    const baris = pilihan[item.Nama];
                                    const galatNama = AmbilGalatBaris(item.Nama, 'Nama');

                                    return (
                                        <TableRow
                                            key={item.Nama}
                                            data-state={baris?.Dipilih && !item.SudahAda ? 'selected' : undefined}
                                            className="align-top"
                                        >
                                            <TableCell className="px-4">
                                                {item.SudahAda ? null : (
                                                    <Checkbox
                                                        className="mt-3"
                                                        checked={baris?.Dipilih ?? false}
                                                        onCheckedChange={(nilai) =>
                                                            UbahBaris(item.Nama, { Dipilih: nilai === true })
                                                        }
                                                        aria-label={`Pilih ${item.Nama}`}
                                                    />
                                                )}
                                            </TableCell>
                                            <TableCell className="px-4 break-words whitespace-normal text-teks-utama">
                                                <span className="block pt-2">{item.Nama}</span>
                                                {item.SudahAda ? <LabelStatus jenis="netral" teks="Sudah ada" /> : null}
                                                {galatNama ? (
                                                    <span className="block text-keterangan font-semibold text-bahaya">
                                                        {galatNama}
                                                    </span>
                                                ) : null}
                                            </TableCell>
                                            <TableCell className="px-4 pt-4 whitespace-normal text-teks-sekunder">
                                                {item.NamaKategori ?? '—'}
                                            </TableCell>
                                            <TableCell className="px-4 pt-4 font-mono text-label text-teks-sekunder">
                                                {item.KodeSatuan}
                                            </TableCell>
                                            <TableCell className="px-4 whitespace-normal">
                                                {item.SudahAda ? (
                                                    <span className="block pt-2 text-right text-teks-sekunder tabular-nums">
                                                        {FormatRupiah(item.Harga)}
                                                    </span>
                                                ) : (
                                                    <BidangUang
                                                        label={`Harga jual ${item.Nama}`}
                                                        labelTersembunyi
                                                        nilai={baris?.Harga ?? item.Harga}
                                                        saatBerubah={(nilai) => UbahBaris(item.Nama, { Harga: nilai })}
                                                        galat={AmbilGalatBaris(item.Nama, 'Harga')}
                                                        disabled={!baris?.Dipilih}
                                                    />
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </TabelForm>
                    </div>
                    <div aria-live="polite" className="flex flex-col gap-2">
                        <p className="text-label text-teks-sekunder">
                            {String(terpilih.length)} dari {String(bisaDipilih.length)} produk contoh dipilih.
                        </p>
                        {melebihiKuota ? (
                            <Pemberitahuan jenis="peringatan">
                                Sisa kuota paket {String(sisaSku)} SKU. Kurangi pilihan menjadi paling banyak{' '}
                                {String(sisaSku)} produk.
                            </Pemberitahuan>
                        ) : null}
                    </div>
                    <div>
                        <Tombol
                            type="submit"
                            memproses={formulir.processing}
                            disabled={terpilih.length === 0 || melebihiKuota || kuotaPenuh}
                        >
                            Tambahkan produk contoh
                        </Tombol>
                    </div>
                </form>
            )}
        </Card>
    );
}

type BarisManual = { Nama: string; Harga: string; Kategori: string };

const barisKosong: BarisManual = { Nama: '', Harga: '', Kategori: '' };

function FormProdukCepat({
    kategori,
    kuotaPenuh,
}: {
    kategori: { Uuid: string; Nama: string }[];
    kuotaPenuh: boolean;
}) {
    const elemenFormulir = useRef<HTMLFormElement>(null);
    const formulir = useForm<{ Produk: BarisManual[] }>({ Produk: [barisKosong] });
    const galat = formulir.errors as Record<string, string | undefined>;
    const opsiKategori = kategori.map((item) => ({ Nilai: item.Uuid, Label: item.Nama }));
    const [tempelTerbuka, AturTempelTerbuka] = useState(false);
    const [teksTempel, AturTeksTempel] = useState('');
    const [pesanTempel, AturPesanTempel] = useState<string | null>(null);

    /** D-23 A: isi baris dari daftar yang ditempel (Excel/WhatsApp); baris kosong diganti, maksimal 20 baris. */
    const MasukkanTempelan = () => {
        const hasil = UraiDaftarTempel(teksTempel, kategori);
        const terisi = formulir.data.Produk.filter((baris) => baris.Nama !== '' || baris.Harga !== '');
        const ruang = Math.max(0, maksimalBarisManual - terisi.length);
        const masuk = hasil.Baris.slice(0, ruang);
        const catatan = [
            `${String(masuk.length)} produk dimasukkan ke daftar. Periksa lalu pilih Tambah produk.`,
            hasil.Baris.length > masuk.length
                ? `${String(hasil.Baris.length - masuk.length)} produk belum masuk karena maksimal ${String(maksimalBarisManual)} per simpan; tempel lagi setelah disimpan.`
                : null,
            hasil.Dilewati.length > 0
                ? `Baris ${hasil.Dilewati.join(', ')} dilewati karena tidak ada nama atau harga.`
                : null,
            hasil.KategoriTakDikenal.length > 0
                ? `Kategori ${hasil.KategoriTakDikenal.join(', ')} belum ada, jadi produknya tanpa kategori.`
                : null,
        ].filter((teks): teks is string => teks !== null);

        if (masuk.length > 0) {
            formulir.setData('Produk', [...terisi, ...masuk]);
            AturTeksTempel(
                hasil.Baris.slice(ruang)
                    .map((b) => `${b.Nama}\t${b.Harga}`)
                    .join('\n'),
            );
        }

        AturPesanTempel(
            masuk.length > 0
                ? catatan.join(' ')
                : 'Tidak ada produk yang bisa dibaca. Tulis satu produk per baris, misal "Kopi Susu 15.000".',
        );
    };

    const UbahBaris = (indeks: number, ubahan: Partial<BarisManual>) =>
        formulir.setData(
            'Produk',
            formulir.data.Produk.map((baris, i) => (i === indeks ? { ...baris, ...ubahan } : baris)),
        );

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.transform((data) => ({
            Produk: data.Produk.map((baris) => ({ ...baris, Kategori: baris.Kategori === '' ? null : baris.Kategori })),
        }));
        formulir.post(AlamatPanduan.Produk, {
            preserveScroll: true,
            onSuccess: () => formulir.reset(),
            onError: () => FokusGalatPertama(elemenFormulir.current),
        });
    };

    return (
        <Card aria-labelledby="judul-produk-cepat" role="region" className="gap-3 p-4 sm:p-6 rounded-panel shadow-none">
            <h2 id="judul-produk-cepat" className="text-subjudul font-semibold text-teks-utama">
                Tambah produk sendiri
            </h2>
            <form ref={elemenFormulir} onSubmit={Kirim} className="flex flex-col gap-3" noValidate>
                <FieldSet disabled={kuotaPenuh} className="gap-3">
                    <FieldLegend className="sr-only">Produk baru</FieldLegend>
                    <RingkasanGalatFormulir galat={formulir.errors} />
                    {galat.Produk ? (
                        <FieldError className="text-keterangan font-semibold">{galat.Produk}</FieldError>
                    ) : null}
                    <div className="flex flex-col gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            className="self-start"
                            aria-expanded={tempelTerbuka}
                            onClick={() => AturTempelTerbuka(!tempelTerbuka)}
                        >
                            {tempelTerbuka ? 'Tutup tempel daftar' : 'Tempel daftar dari Excel atau WhatsApp'}
                        </Button>
                        {tempelTerbuka ? (
                            <div className="flex flex-col gap-2 rounded-kontrol border border-garis p-3">
                                <BidangTeksPanjang
                                    label="Daftar produk"
                                    nilai={teksTempel}
                                    saatBerubah={AturTeksTempel}
                                    keterangan="Satu produk per baris: nama lalu harga, misal Kopi Susu 15.000 atau Es Teh 5rb. Dari Excel, salin kolom Nama, Harga, dan Kategori (opsional) sekaligus."
                                    baris={6}
                                    maksimal={20000}
                                />
                                <Button
                                    type="button"
                                    className="self-start"
                                    onClick={MasukkanTempelan}
                                    disabled={teksTempel.trim() === ''}
                                >
                                    Masukkan ke daftar
                                </Button>
                                {pesanTempel ? (
                                    <p role="status" className="text-keterangan text-teks-sekunder">
                                        {pesanTempel}
                                    </p>
                                ) : null}
                            </div>
                        ) : null}
                    </div>
                    <ol className="flex flex-col gap-3">
                        {formulir.data.Produk.map((baris, indeks) => (
                            <li
                                key={indeks}
                                className="grid grid-cols-1 gap-3 border-b border-garis pb-3 last:border-b-0 md:grid-cols-[2fr_1fr_1fr_auto] md:items-start"
                            >
                                <BidangTeks
                                    label={`Nama produk ${String(indeks + 1)}`}
                                    nilai={baris.Nama}
                                    saatBerubah={(nilai) => UbahBaris(indeks, { Nama: nilai })}
                                    galat={galat[`Produk.${String(indeks)}.Nama`]}
                                    maxLength={150}
                                    required
                                />
                                <BidangUang
                                    label="Harga jual"
                                    nilai={baris.Harga}
                                    saatBerubah={(nilai) => UbahBaris(indeks, { Harga: nilai })}
                                    galat={galat[`Produk.${String(indeks)}.Harga`]}
                                    required
                                />
                                <BidangPilihan
                                    label="Kategori"
                                    nilai={baris.Kategori}
                                    opsi={opsiKategori}
                                    saatBerubah={(nilai) => UbahBaris(indeks, { Kategori: nilai })}
                                    galat={galat[`Produk.${String(indeks)}.Kategori`]}
                                    kosong="Tanpa kategori"
                                />
                                <div className="md:pt-6">
                                    {formulir.data.Produk.length > 1 ? (
                                        <Tombol
                                            type="button"
                                            varian="sekunder"
                                            onClick={() =>
                                                formulir.setData(
                                                    'Produk',
                                                    formulir.data.Produk.filter((_, i) => i !== indeks),
                                                )
                                            }
                                            aria-label={`Hapus baris produk ${String(indeks + 1)}`}
                                        >
                                            Hapus baris
                                        </Tombol>
                                    ) : null}
                                </div>
                            </li>
                        ))}
                    </ol>
                    <div className="flex flex-wrap gap-2">
                        <Tombol type="submit" memproses={formulir.processing}>
                            Tambah produk
                        </Tombol>
                        <Tombol
                            type="button"
                            varian="sekunder"
                            onClick={() => formulir.setData('Produk', [...formulir.data.Produk, barisKosong])}
                            disabled={formulir.data.Produk.length >= maksimalBarisManual}
                        >
                            Tambah baris
                        </Tombol>
                    </div>
                    <FieldDescription className="text-keterangan">
                        Maksimal {maksimalBarisManual} produk sekali simpan. Nama yang sudah ada dilewati.
                    </FieldDescription>
                </FieldSet>
            </form>
        </Card>
    );
}

const kolomProduk: KolomTabel<PropsProdukPanduan['Produk'][number]>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Nama',
        meta: { label: 'Nama', prioritas: 'utama', wajib: true, kelasSel: 'break-words text-teks-utama' },
    },
    {
        id: 'NamaKategori',
        accessorFn: (baris) => baris.NamaKategori ?? '—',
        header: 'Kategori',
        meta: { label: 'Kategori', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
    },
    {
        id: 'Harga',
        header: 'Harga jual',
        enableSorting: false,
        meta: { label: 'Harga jual', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.Harga),
    },
];

function TabelProduk({ produk, jumlahProduk }: { produk: PropsProdukPanduan['Produk']; jumlahProduk: number }) {
    return (
        <section aria-labelledby="judul-daftar-produk" className="flex flex-col gap-2">
            <h2 id="judul-daftar-produk" className="text-subjudul font-semibold text-teks-utama">
                Produk Anda
            </h2>
            {jumlahProduk > produk.length ? (
                <p className="text-label text-teks-sekunder">
                    Menampilkan {String(produk.length)} produk terbaru dari {String(jumlahProduk)}.
                </p>
            ) : null}
            <TabelData
                id="panduan-produk-terbaru"
                label="Produk terbaru"
                kolom={kolomProduk}
                sumber={{ mode: 'lokal', data: produk }}
                ambilIdBaris={(baris) => baris.Uuid}
                kosong={{ ilustrasi: true, judul: 'Belum ada produk. Tambah produk pertama Anda.' }}
            />
        </section>
    );
}
