import { router, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import BidangBerkas from '@/Komponen/Formulir/BidangBerkas';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import KartuFormulir from '@/Komponen/Formulir/KartuFormulir';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import { TableBody, TableCaption, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { cn } from '@/Komponen/Ui/utils';
import { FormatRupiah } from '@/Pustaka/Format';
import {
    BandingkanDesimal,
    CekDesimalValid,
    JumlahkanDesimal,
    KurangiDesimal,
    MutlakDesimal,
} from '@/Pustaka/HitungDesimal';
import { TulisTanggal } from '@/Pustaka/Tanggal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { JenisJurnalUmum, PropsBuatJurnalUmum } from '@/Tipe/Akuntansi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

const alamat = '/kelola/akuntansi/jurnal-umum';
const batasBaris = 100;

type BarisIsian = { Kunci: number; UuidAkun: string; UuidOutlet: string; Debit: string; Kredit: string; Memo: string };

type Isian = {
    Jenis: JenisJurnalUmum;
    Tanggal: string;
    Referensi: string;
    Keterangan: string;
    UuidOutlet: string;
    Lampiran: File[];
};

/** Jumlah satu sisi; isian kosong/belum lengkap dihitung nol (pratinjau saja, server tetap penentu). */
function JumlahSisi(baris: BarisIsian[], sisi: 'Debit' | 'Kredit'): string {
    return JumlahkanDesimal(baris.map((b) => b[sisi]).filter((nilai) => nilai !== '' && CekDesimalValid(nilai)));
}

/**
 * FIN-05 halaman "Catat jurnal umum" (D-131). Akuntan/Pemilik memilih akun dan mengisi debit/kredit; total dan selisih
 * dihitung langsung di layar (BigInt berskala, tanpa float) dan tombol simpan baru aktif saat seimbang. Server menolak
 * ulang bila tidak seimbang, akun kendali, atau periode terkunci. `UuidKlien` dibuat sekali per halaman sehingga
 * kiriman ganda menghasilkan satu dokumen.
 */
export default function HalamanBuatJurnalUmum({
    UuidKlien,
    TanggalBawaan,
    OpsiJenis,
    OpsiOutlet,
    OpsiAkun,
    WajibOutlet,
    Lampiran,
}: PropsBuatJurnalUmum) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const outletAwal = WajibOutlet && OpsiOutlet.length === 1 ? (OpsiOutlet[0]?.Uuid ?? '') : '';
    const [isian, AturIsian] = useState<Isian>({
        Jenis: 'Umum',
        Tanggal: TanggalBawaan,
        Referensi: '',
        Keterangan: '',
        UuidOutlet: outletAwal,
        Lampiran: [],
    });
    const [baris, AturBaris] = useState<BarisIsian[]>([
        { Kunci: 1, UuidAkun: '', UuidOutlet: outletAwal, Debit: '', Kredit: '', Memo: '' },
        { Kunci: 2, UuidAkun: '', UuidOutlet: outletAwal, Debit: '', Kredit: '', Memo: '' },
    ]);
    const [kunciBerikut, AturKunciBerikut] = useState(3);
    const [memproses, AturMemproses] = useState(false);

    const penjelasanJenis = OpsiJenis.find((o) => o.Nilai === isian.Jenis)?.Penjelasan ?? '';
    const opsiAkun = OpsiAkun.map((a) => ({ Nilai: a.Uuid, Label: `${a.Kode} ${a.Nama}` }));
    const opsiOutlet = OpsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }));
    const perBaris = OpsiOutlet.length > 1;
    const totalDebit = JumlahSisi(baris, 'Debit');
    const totalKredit = JumlahSisi(baris, 'Kredit');
    const selisih = KurangiDesimal(totalDebit, totalKredit);
    const seimbang = BandingkanDesimal(totalDebit, totalKredit) === 0 && BandingkanDesimal(totalDebit, '0') > 0;
    const jumlahTerisi = baris.filter((b) => b.UuidAkun !== '' && (b.Debit !== '' || b.Kredit !== '')).length;
    const siapKirim = seimbang && jumlahTerisi >= 2 && isian.Keterangan.trim() !== '';

    const Ubah = (ubah: Partial<Isian>) => AturIsian({ ...isian, ...ubah });

    const UbahBaris = (kunci: number, ubah: Partial<BarisIsian>) =>
        AturBaris(baris.map((b) => (b.Kunci === kunci ? { ...b, ...ubah } : b)));

    const TambahBaris = () => {
        AturBaris([
            ...baris,
            { Kunci: kunciBerikut, UuidAkun: '', UuidOutlet: isian.UuidOutlet, Debit: '', Kredit: '', Memo: '' },
        ]);
        AturKunciBerikut(kunciBerikut + 1);
    };

    const HapusBaris = (kunci: number) => AturBaris(baris.filter((b) => b.Kunci !== kunci));

    const UbahOutletJurnal = (nilai: string) => {
        Ubah({ UuidOutlet: nilai });
        AturBaris(baris.map((b) => ({ ...b, UuidOutlet: nilai })));
    };

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        if (memproses) {
            return;
        }

        const { Jenis, Tanggal, Referensi, Keterangan, Lampiran: berkas } = isian;
        router.post(
            alamat,
            {
                UuidKlien,
                Jenis,
                Tanggal,
                Keterangan,
                Referensi: Referensi.trim() === '' ? null : Referensi.trim(),
                Baris: baris
                    .filter((b) => b.UuidAkun !== '' || b.Debit !== '' || b.Kredit !== '' || b.Memo !== '')
                    .map((b) => ({
                        UuidAkun: b.UuidAkun,
                        UuidOutlet: b.UuidOutlet === '' ? null : b.UuidOutlet,
                        Debit: b.Debit === '' ? null : b.Debit,
                        Kredit: b.Kredit === '' ? null : b.Kredit,
                        Memo: b.Memo.trim() === '' ? null : b.Memo.trim(),
                    })),
                Lampiran: berkas[0] ?? null,
            },
            {
                forceFormData: berkas.length > 0,
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
            },
        );
    };

    const GalatBaris = (indeks: number, bidang: string) => galat[`Baris.${String(indeks)}.${bidang}`];
    const kecuali = [
        ...Object.keys(isian),
        'Baris',
        'UuidKlien',
        ...Object.keys(galat).filter((k) => k.startsWith('Baris.')),
    ];
    const kelasKepala = 'h-auto px-3 py-2 text-label text-teks-sekunder';

    return (
        <TataLetakAplikasi judul="Catat jurnal umum" jejak={[{ label: 'Jurnal', href: '/kelola/akuntansi/jurnal' }]}>
            <DaftarGalatServer galat={galat} kecuali={kecuali} />
            <KartuFormulir keterangan="Untuk pencatatan yang tidak punya dokumen sendiri, misalnya penyesuaian akhir bulan. Jurnal langsung diposting saat disimpan dan tidak bisa diubah; koreksi dengan dokumen pembalik. Akun persediaan, piutang, hutang, deposit, dan aset tetap tidak tersedia di sini karena saldonya mengikuti dokumen modulnya.">
                <form onSubmit={Simpan} className="flex flex-col gap-4" aria-label="Formulir jurnal umum" noValidate>
                    <BidangOutlet
                        nilai={isian.UuidOutlet}
                        opsi={opsiOutlet}
                        {...(WajibOutlet ? {} : { kosong: 'Tingkat usaha (tanpa outlet)' })}
                        saatBerubah={UbahOutletJurnal}
                        galat={galat.UuidOutlet}
                        required={WajibOutlet}
                    />
                    <BidangPilihan
                        label="Jenis jurnal"
                        nilai={isian.Jenis}
                        opsi={OpsiJenis.map((o) => ({ Nilai: o.Nilai, Label: o.Label }))}
                        saatBerubah={(nilai) => Ubah({ Jenis: nilai as JenisJurnalUmum })}
                        galat={galat.Jenis}
                        required
                    />
                    <p className="text-label text-teks-sekunder">{penjelasanJenis}</p>
                    <PemilihTanggal
                        label="Tanggal"
                        nilai={isian.Tanggal}
                        max={TulisTanggal(new Date())}
                        saatBerubah={(nilai) => Ubah({ Tanggal: nilai })}
                        galat={galat.Tanggal}
                        required
                    />
                    <BidangTeks
                        label="Referensi"
                        nilai={isian.Referensi}
                        saatBerubah={(nilai) => Ubah({ Referensi: nilai })}
                        galat={galat.Referensi}
                        keterangan="Nomor bukti atau memo di luar Payoung. Boleh dikosongkan."
                        maxLength={100}
                    />
                    <BidangTeksPanjang
                        label="Keterangan"
                        nilai={isian.Keterangan}
                        saatBerubah={(nilai) => Ubah({ Keterangan: nilai })}
                        galat={galat.Keterangan}
                        maksimal={255}
                        required
                    />

                    <section aria-labelledby="judul-baris-jurnal" className="flex flex-col gap-2">
                        <h2 id="judul-baris-jurnal" className="text-subjudul font-semibold text-teks-utama">
                            Baris jurnal
                        </h2>
                        <div className="rounded-panel border border-garis bg-card">
                            <TabelForm label="Isian baris jurnal" lebar="lebar">
                                <TableCaption className="sr-only">
                                    Isi akun, lalu debit atau kredit. Total debit harus sama dengan total kredit.
                                </TableCaption>
                                <TableHeader>
                                    <TableRow className="border-garis hover:bg-transparent">
                                        <TableHead scope="col" className={cn(kelasKepala, 'w-[28%]')}>
                                            Akun
                                        </TableHead>
                                        {perBaris ? (
                                            <TableHead scope="col" className={cn(kelasKepala, 'w-[16%]')}>
                                                Outlet
                                            </TableHead>
                                        ) : null}
                                        <TableHead scope="col" className={cn(kelasKepala, 'w-[16%] text-right')}>
                                            Debit
                                        </TableHead>
                                        <TableHead scope="col" className={cn(kelasKepala, 'w-[16%] text-right')}>
                                            Kredit
                                        </TableHead>
                                        <TableHead scope="col" className={kelasKepala}>
                                            Memo
                                        </TableHead>
                                        <TableHead scope="col" className={cn(kelasKepala, 'w-12')}>
                                            <span className="sr-only">Hapus</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {baris.map((b, indeks) => (
                                        <TableRow key={b.Kunci} className="border-garis align-top">
                                            <TableCell className="px-3 py-2">
                                                <BidangPilihan
                                                    label={`Akun baris ${String(indeks + 1)}`}
                                                    labelTersembunyi
                                                    nilai={b.UuidAkun}
                                                    kosong="Pilih akun"
                                                    opsi={opsiAkun}
                                                    saatBerubah={(nilai) => UbahBaris(b.Kunci, { UuidAkun: nilai })}
                                                    galat={GalatBaris(indeks, 'UuidAkun')}
                                                />
                                            </TableCell>
                                            {perBaris ? (
                                                <TableCell className="px-3 py-2">
                                                    <BidangPilihan
                                                        label={`Outlet baris ${String(indeks + 1)}`}
                                                        labelTersembunyi
                                                        nilai={b.UuidOutlet}
                                                        kosong={WajibOutlet ? 'Pilih outlet' : 'Tingkat usaha'}
                                                        opsi={opsiOutlet}
                                                        saatBerubah={(nilai) =>
                                                            UbahBaris(b.Kunci, { UuidOutlet: nilai })
                                                        }
                                                        galat={GalatBaris(indeks, 'UuidOutlet')}
                                                    />
                                                </TableCell>
                                            ) : null}
                                            <TableCell className="px-3 py-2">
                                                <BidangUang
                                                    label={`Debit baris ${String(indeks + 1)}`}
                                                    labelTersembunyi
                                                    nilai={b.Debit}
                                                    saatBerubah={(nilai) =>
                                                        UbahBaris(
                                                            b.Kunci,
                                                            nilai === '' ? { Debit: '' } : { Debit: nilai, Kredit: '' },
                                                        )
                                                    }
                                                    galat={GalatBaris(indeks, 'Debit')}
                                                />
                                            </TableCell>
                                            <TableCell className="px-3 py-2">
                                                <BidangUang
                                                    label={`Kredit baris ${String(indeks + 1)}`}
                                                    labelTersembunyi
                                                    nilai={b.Kredit}
                                                    saatBerubah={(nilai) =>
                                                        UbahBaris(
                                                            b.Kunci,
                                                            nilai === ''
                                                                ? { Kredit: '' }
                                                                : { Kredit: nilai, Debit: '' },
                                                        )
                                                    }
                                                    galat={GalatBaris(indeks, 'Kredit')}
                                                />
                                            </TableCell>
                                            <TableCell className="px-3 py-2">
                                                <BidangTeks
                                                    label={`Memo baris ${String(indeks + 1)}`}
                                                    labelTersembunyi
                                                    nilai={b.Memo}
                                                    saatBerubah={(nilai) => UbahBaris(b.Kunci, { Memo: nilai })}
                                                    galat={GalatBaris(indeks, 'Memo')}
                                                    maxLength={255}
                                                />
                                            </TableCell>
                                            <TableCell className="px-3 py-2">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Hapus baris ${String(indeks + 1)}`}
                                                    disabled={baris.length <= 2}
                                                    onClick={() => HapusBaris(b.Kunci)}
                                                >
                                                    <Trash2 aria-hidden="true" />
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter className="border-garis bg-permukaan-redup">
                                    <TableRow className="hover:bg-transparent">
                                        <TableCell
                                            colSpan={perBaris ? 2 : 1}
                                            className="px-3 font-semibold text-teks-utama"
                                        >
                                            Total
                                        </TableCell>
                                        <TableCell className="px-3 text-right font-semibold whitespace-nowrap tabular-nums">
                                            {FormatRupiah(totalDebit)}
                                        </TableCell>
                                        <TableCell className="px-3 text-right font-semibold whitespace-nowrap tabular-nums">
                                            {FormatRupiah(totalKredit)}
                                        </TableCell>
                                        <TableCell colSpan={2} className="px-3" />
                                    </TableRow>
                                </TableFooter>
                            </TabelForm>
                        </div>
                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={baris.length >= batasBaris}
                                onClick={TambahBaris}
                            >
                                <Plus aria-hidden="true" />
                                Tambah baris
                            </Button>
                            <p
                                role="status"
                                className={cn(
                                    'text-isi font-semibold',
                                    seimbang ? 'text-sukses' : 'text-teks-sekunder',
                                )}
                            >
                                {seimbang
                                    ? 'Seimbang'
                                    : BandingkanDesimal(selisih, '0') === 0
                                      ? 'Isi debit dan kredit'
                                      : `Selisih ${FormatRupiah(MutlakDesimal(selisih))}: debit dan kredit harus sama`}
                            </p>
                        </div>
                        {galat.Baris ? (
                            <p role="alert" className="text-label text-bahaya">
                                {galat.Baris}
                            </p>
                        ) : null}
                    </section>

                    <BidangBerkas
                        label="Lampiran (memo atau bukti pendukung)"
                        berkas={isian.Lampiran}
                        saatBerubah={(berkas) => Ubah({ Lampiran: berkas })}
                        ekstensi={Lampiran.Ekstensi}
                        maksimal={1}
                        ukuranMaksimalKb={Lampiran.UkuranMaksimalKb}
                        galat={galat.Lampiran}
                    />
                    <BilahAksiForm>
                        <Tombol type="submit" memproses={memproses} disabled={!siapKirim}>
                            Simpan & posting
                        </Tombol>
                        <Tombol
                            type="button"
                            varian="sekunder"
                            onClick={() => router.visit('/kelola/akuntansi/jurnal')}
                        >
                            Batal
                        </Tombol>
                    </BilahAksiForm>
                </form>
            </KartuFormulir>
        </TataLetakAplikasi>
    );
}
