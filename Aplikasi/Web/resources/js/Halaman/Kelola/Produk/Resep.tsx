import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import TabelForm from '@/Komponen/TabelData/TabelForm';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { JenisBahan } from '@/Komponen/Katalog/BantuanKatalog';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import KeadaanKosong from '@/Komponen/Katalog/KeadaanKosong';
import KepalaProduk from '@/Komponen/Katalog/KepalaProduk';
import PemilihProduk from '@/Komponen/Katalog/PemilihProduk';
import Panel from '@/Komponen/Kelola/Panel';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import { Button } from '@/Komponen/Ui/button';
import { TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import {
    BandingkanDesimal,
    BulatkanDesimal,
    CekDesimalPositif,
    CekDesimalValid,
    FormatMasukanJumlah,
    HitungJumlahKotor,
} from '@/Pustaka/MasukanJumlah';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsResepProduk } from '@/Tipe/Katalog';

/** Rumus susut yang disetujui lead (DesainF03 H.7, diamandemen). Tampil sebagai teks bantuan. */
export const TeksRumusSusut =
    'Jumlah kotor = bersih ÷ (1 − susut/100). Contoh: 150 ml dengan susut 10 % butuh 166,6667 ml.';

type OpsiSatuanBahan = { Uuid: string; Simbol: string; BolehDesimal: boolean };

type BarisBahan = {
    UuidProdukBahan: string;
    NamaBahan: string;
    Sku: string | null;
    Jumlah: string;
    UuidSatuan: string;
    PersenSusut: string;
    OpsiSatuan: OpsiSatuanBahan[];
};

/** Galat lokal per baris bahan (server tetap memeriksa ulang). */
export function PeriksaBahan(baris: BarisBahan): { Jumlah?: string; PersenSusut?: string } {
    const galat: { Jumlah?: string; PersenSusut?: string } = {};

    if (!CekDesimalPositif(baris.Jumlah)) {
        galat.Jumlah = 'Isi jumlah lebih dari 0.';
    }

    const susut = baris.PersenSusut === '' ? '0' : baris.PersenSusut;

    if (!CekDesimalValid(susut) || BandingkanDesimal(susut, '100') >= 0) {
        galat.PersenSusut = 'Susut harus 0 sampai kurang dari 100 %.';
    }

    return galat;
}

function FormatHpp(nilai: string | null): string {
    return nilai === null ? '—' : FormatRupiah(BulatkanDesimal(nilai, 2));
}

/** F-03 resep/BOM produk: versi tak berubah (BR-03.4), susut, dan HPP resep (BR-03.5). */
export default function HalamanResepProduk({ Kepala, Resep, VersiTerbaru, DaftarVersi, Hpp, Izin }: PropsResepProduk) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const versiLama = Resep !== null && VersiTerbaru !== null && Resep.Versi !== VersiTerbaru;
    const bolehUbah = Izin.Kelola && !versiLama;
    const simbolHasil = Resep?.SimbolSatuanHasil ?? '';
    const [jumlahHasil, AturJumlahHasil] = useState(Resep?.JumlahHasil ?? '1');
    const [catatan, AturCatatan] = useState(Resep?.Catatan ?? '');
    const [bahan, AturBahan] = useState<BarisBahan[]>(
        (Resep?.Bahan ?? []).map((item) => ({
            UuidProdukBahan: item.UuidProdukBahan,
            NamaBahan: item.NamaBahan,
            Sku: item.Sku,
            Jumlah: item.Jumlah,
            UuidSatuan: item.UuidSatuan,
            PersenSusut: item.PersenSusut,
            OpsiSatuan: [{ Uuid: item.UuidSatuan, Simbol: item.SimbolSatuan, BolehDesimal: true }],
        })),
    );
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const Ubah = (indeks: number, perubahan: Partial<BarisBahan>) =>
        AturBahan(bahan.map((item, i) => (i === indeks ? { ...item, ...perubahan } : item)));

    const Simpan = () => {
        AturPeriksa(true);

        if (
            bahan.length === 0 ||
            !CekDesimalPositif(jumlahHasil) ||
            bahan.some((item) => Object.keys(PeriksaBahan(item)).length > 0)
        ) {
            return;
        }

        router.post(
            `/kelola/produk/${Kepala.Uuid}/resep`,
            {
                JumlahHasil: jumlahHasil,
                Catatan: catatan,
                Bahan: bahan.map((item) => ({
                    UuidProdukBahan: item.UuidProdukBahan,
                    Jumlah: item.Jumlah,
                    UuidSatuan: item.UuidSatuan,
                    PersenSusut: item.PersenSusut === '' ? '0' : item.PersenSusut,
                })),
            },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );
    };

    return (
        <TataLetakAplikasi judul={`Resep ${Kepala.Nama}`}>
            <KepalaProduk kepala={Kepala} tabAktif="Resep" />
            {!Izin.Kelola ? <PesanHanyaLihat izin="produk.kelola" objek="resep produk ini" /> : null}
            <DaftarGalatServer galat={galat} kecuali={Object.keys(galat).filter((kunci) => /^Bahan\./.test(kunci))} />

            {versiLama ? (
                <Pemberitahuan jenis="info" judul={`Anda melihat versi ${String(Resep.Versi)} (bukan yang terbaru)`}>
                    Versi lama hanya bisa dibaca. Transaksi lama tetap memakai versi yang berlaku saat itu.{' '}
                    <Link href={`/kelola/produk/${Kepala.Uuid}/resep`} className="font-semibold text-brand underline">
                        Lihat versi terbaru ({VersiTerbaru})
                    </Link>
                </Pemberitahuan>
            ) : null}

            {Resep === null && !Izin.Kelola ? (
                <KeadaanKosong judul="Belum ada resep untuk produk ini." />
            ) : (
                <Panel
                    judul={Resep === null ? 'Resep baru' : `Resep versi ${String(Resep.Versi)}`}
                    idJudul="judul-resep"
                    keterangan={
                        Resep !== null
                            ? `Dibuat ${FormatTanggalWaktu(Resep.DibuatPada)} oleh ${Resep.NamaPembuat ?? 'Sistem'}`
                            : undefined
                    }
                    className="gap-4"
                >
                    {Resep === null ? (
                        <p className="text-isi text-teks-sekunder">
                            Belum ada resep. Tambah bahan agar HPP dihitung dan stok bahan terpotong saat produk
                            terjual.
                        </p>
                    ) : null}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <BidangJumlah
                            label="Jumlah hasil satu resep"
                            nilai={jumlahHasil}
                            saatBerubah={AturJumlahHasil}
                            {...(simbolHasil ? { akhiran: simbolHasil } : {})}
                            keterangan="Misal 1 porsi, atau 20 bila satu adonan menjadi 20 roti."
                            galat={
                                galat.JumlahHasil ??
                                (periksa && !CekDesimalPositif(jumlahHasil)
                                    ? 'Isi jumlah hasil lebih dari 0.'
                                    : undefined)
                            }
                            disabled={!bolehUbah}
                            required
                        />
                        {bolehUbah ? (
                            <BidangTeksPanjang
                                label="Catatan (opsional)"
                                nilai={catatan}
                                saatBerubah={AturCatatan}
                                galat={galat.Catatan}
                                maksimal={500}
                            />
                        ) : (
                            <p className="text-isi text-teks-sekunder">Catatan: {catatan || '—'}</p>
                        )}
                    </div>

                    <p id="rumus-susut" className="text-keterangan text-teks-sekunder">
                        {TeksRumusSusut}
                    </p>

                    {bahan.length === 0 ? (
                        <p className="rounded-kontrol border border-dashed border-garis-input px-3 py-2 text-isi text-teks-sekunder">
                            Belum ada bahan.
                        </p>
                    ) : (
                        <TabelForm label="Bahan resep" lebar="lebar">
                            <TableCaption className="sr-only">Bahan resep</TableCaption>
                            <TableHeader>
                                <TableRow className="border-garis hover:bg-transparent">
                                    <TableHead scope="col" className="pl-0 text-label text-teks-sekunder">
                                        Bahan
                                    </TableHead>
                                    <TableHead scope="col" className="w-36 text-right text-label text-teks-sekunder">
                                        Jumlah bersih
                                    </TableHead>
                                    <TableHead scope="col" className="w-28 text-label text-teks-sekunder">
                                        Satuan
                                    </TableHead>
                                    <TableHead scope="col" className="w-28 text-right text-label text-teks-sekunder">
                                        Susut
                                    </TableHead>
                                    <TableHead scope="col" className="w-36 text-right text-label text-teks-sekunder">
                                        Jumlah kotor
                                    </TableHead>
                                    <TableHead scope="col" className="w-16 pr-0 text-label text-teks-sekunder">
                                        <span className="sr-only">Aksi</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {bahan.map((item, indeks) => {
                                    const galatLokal = periksa ? PeriksaBahan(item) : {};
                                    const satuan = item.OpsiSatuan.find((opsi) => opsi.Uuid === item.UuidSatuan);
                                    const kotor = HitungJumlahKotor(item.Jumlah || '0', item.PersenSusut || '0');

                                    return (
                                        <TableRow key={item.UuidProdukBahan} className="border-garis align-top">
                                            <th scope="row" className="p-2 pl-0 text-left align-top font-normal">
                                                <span className="block font-semibold break-words text-teks-utama">
                                                    {item.NamaBahan}
                                                </span>
                                                <span className="font-mono text-keterangan text-teks-sekunder">
                                                    {item.Sku ?? 'Tanpa SKU'}
                                                </span>
                                            </th>
                                            <TableCell className="whitespace-normal">
                                                <BidangJumlah
                                                    label={`Jumlah bersih ${item.NamaBahan}`}
                                                    labelTersembunyi
                                                    nilai={item.Jumlah}
                                                    saatBerubah={(nilai) => Ubah(indeks, { Jumlah: nilai })}
                                                    desimal={satuan?.BolehDesimal === false ? 0 : 4}
                                                    galat={galat[`Bahan.${String(indeks)}.Jumlah`] ?? galatLokal.Jumlah}
                                                    required
                                                    disabled={!bolehUbah}
                                                />
                                            </TableCell>
                                            <TableCell className="whitespace-normal">
                                                <BidangPilihan
                                                    label={`Satuan ${item.NamaBahan}`}
                                                    labelTersembunyi
                                                    nilai={item.UuidSatuan}
                                                    opsi={item.OpsiSatuan.map((opsi) => ({
                                                        Nilai: opsi.Uuid,
                                                        Label: opsi.Simbol,
                                                    }))}
                                                    saatBerubah={(nilai) => Ubah(indeks, { UuidSatuan: nilai })}
                                                    galat={galat[`Bahan.${String(indeks)}.UuidSatuan`]}
                                                    required
                                                />
                                            </TableCell>
                                            <TableCell className="whitespace-normal">
                                                <BidangJumlah
                                                    label={`Susut ${item.NamaBahan}`}
                                                    labelTersembunyi
                                                    nilai={item.PersenSusut}
                                                    saatBerubah={(nilai) => Ubah(indeks, { PersenSusut: nilai })}
                                                    desimal={6}
                                                    digitBulat={3}
                                                    akhiran="%"
                                                    galat={
                                                        galat[`Bahan.${String(indeks)}.PersenSusut`] ??
                                                        galatLokal.PersenSusut
                                                    }
                                                    disabled={!bolehUbah}
                                                />
                                            </TableCell>
                                            <TableCell
                                                className="text-right whitespace-nowrap tabular-nums"
                                                aria-describedby="rumus-susut"
                                            >
                                                <div className="flex h-8 pointer-coarse:h-11 items-center justify-end text-isi text-teks-utama">
                                                    {kotor === null
                                                        ? '—'
                                                        : `${FormatMasukanJumlah(kotor)} ${satuan?.Simbol ?? ''}`}
                                                </div>
                                            </TableCell>
                                            <TableCell className="pr-0">
                                                {bolehUbah ? (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        onClick={() => AturBahan(bahan.filter((_, i) => i !== indeks))}
                                                        className="h-8 pointer-coarse:h-11 text-destructive"
                                                        aria-label={`Hapus bahan ${item.NamaBahan}`}
                                                    >
                                                        Hapus
                                                    </Button>
                                                ) : null}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </TabelForm>
                    )}
                    <div aria-live="polite">
                        {periksa && bahan.length === 0 ? (
                            <p className="text-keterangan font-semibold text-bahaya">Tambah minimal satu bahan.</p>
                        ) : null}
                    </div>

                    {bolehUbah ? (
                        <>
                            <PemilihProduk
                                label="Tambah bahan"
                                jenis={JenisBahan}
                                kecuali={[Kepala.Uuid, ...bahan.map((item) => item.UuidProdukBahan)]}
                                keterangan="Bahan baku, barang stok, atau barang produksi."
                                saatPilih={(produk) =>
                                    AturBahan([
                                        ...bahan,
                                        {
                                            UuidProdukBahan: produk.Uuid,
                                            NamaBahan: produk.Nama,
                                            Sku: produk.Sku,
                                            Jumlah: '',
                                            UuidSatuan: produk.UuidSatuanDasar,
                                            PersenSusut: '0',
                                            OpsiSatuan: produk.Satuan.map((satuan) => ({
                                                Uuid: satuan.Uuid,
                                                Simbol: satuan.Simbol,
                                                BolehDesimal: satuan.BolehDesimal,
                                            })),
                                        },
                                    ])
                                }
                            />
                            <div className="flex flex-col gap-1">
                                <div>
                                    <Tombol onClick={Simpan} memproses={memproses}>
                                        Simpan sebagai versi baru
                                    </Tombol>
                                </div>
                                <p className="text-keterangan text-teks-sekunder">
                                    Menyimpan membuat versi {String((VersiTerbaru ?? 0) + 1)}. Versi lama tidak berubah
                                    dan tetap dipakai transaksi lama. Tanpa perubahan, versi tidak bertambah.
                                </p>
                            </div>
                        </>
                    ) : null}
                </Panel>
            )}

            {Hpp.Status !== 'TanpaResep' ? (
                <Panel judul="HPP resep (versi terbaru)" idJudul="judul-hpp">
                    {Hpp.Status === 'BelumTersedia' ? (
                        <p className="text-isi text-teks-sekunder">
                            HPP belum tersedia. HPP bahan muncul setelah stok awal diisi.
                        </p>
                    ) : (
                        <p className="text-isi text-teks-utama">
                            HPP per {simbolHasil || 'satuan hasil'}:{' '}
                            <span className="font-semibold tabular-nums">{FormatHpp(Hpp.HppSatuan)}</span>
                        </p>
                    )}
                    {Hpp.Baris.length > 0 ? (
                        <TabelForm label="Ringkasan biaya resep" lebar="sedang" kelasTabel="text-label">
                            <TableCaption className="sr-only">Rincian HPP per bahan</TableCaption>
                            <TableHeader>
                                <TableRow className="border-garis hover:bg-transparent">
                                    <TableHead scope="col" className="pl-0 text-teks-sekunder">
                                        Bahan
                                    </TableHead>
                                    <TableHead scope="col" className="text-right text-teks-sekunder">
                                        Jumlah kotor (satuan dasar)
                                    </TableHead>
                                    <TableHead scope="col" className="text-right text-teks-sekunder">
                                        HPP per satuan dasar
                                    </TableHead>
                                    <TableHead scope="col" className="pr-0 text-right text-teks-sekunder">
                                        Subtotal
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {Hpp.Baris.map((item, indeks) => (
                                    <TableRow key={`${item.NamaBahan}-${String(indeks)}`} className="border-garis">
                                        <TableCell className="pl-0 whitespace-normal">{item.NamaBahan}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {FormatMasukanJumlah(item.JumlahKotor)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {FormatHpp(item.HppSatuanBahan)}
                                        </TableCell>
                                        <TableCell className="pr-0 text-right tabular-nums">
                                            {FormatHpp(item.Subtotal)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </TabelForm>
                    ) : null}
                </Panel>
            ) : null}

            {DaftarVersi.length > 0 ? (
                <Panel judul="Riwayat versi" idJudul="judul-versi">
                    <ol className="flex flex-col divide-y divide-garis">
                        {DaftarVersi.map((versi) => (
                            <li
                                key={versi.Versi}
                                className="flex flex-wrap items-center justify-between gap-2 py-2 text-isi"
                            >
                                {Resep?.Versi === versi.Versi ? (
                                    <span className="font-semibold text-teks-utama" aria-current="page">
                                        Versi {versi.Versi} (sedang dilihat)
                                    </span>
                                ) : (
                                    <Link
                                        href={`/kelola/produk/${Kepala.Uuid}/resep?versi=${String(versi.Versi)}`}
                                        className="font-semibold text-brand underline"
                                    >
                                        Versi {versi.Versi}
                                    </Link>
                                )}
                                <span className="text-keterangan text-teks-sekunder">
                                    {FormatTanggalWaktu(versi.DibuatPada)} | {versi.NamaPembuat ?? 'Sistem'}
                                </span>
                            </li>
                        ))}
                    </ol>
                </Panel>
            ) : null}
        </TataLetakAplikasi>
    );
}
