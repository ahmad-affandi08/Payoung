import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import BidangBerkas from '@/Komponen/Formulir/BidangBerkas';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import KartuFormulir from '@/Komponen/Formulir/KartuFormulir';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import GrupRadio from '@/Komponen/Katalog/GrupRadio';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import Tombol from '@/Komponen/Formulir/Tombol';
import { LabelAkun, useTampilKodeAkun } from '@/Pustaka/SaranAkun';
import { TulisTanggal } from '@/Pustaka/Tanggal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { JenisTransaksiKasBank, OpsiAkunKasBank, PropsBuatTransaksiKasBank, TipeAkun } from '@/Tipe/Akuntansi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

const alamat = '/kelola/akuntansi/kas-bank';

/** Aturan akun per jenis (sama dengan server): sisi kas/bank & tipe akun lawan yang boleh. */
const aturanJenis: Record<
    JenisTransaksiKasBank,
    {
        labelSumber: string;
        labelTujuan: string;
        sumberKas: boolean;
        tujuanKas: boolean;
        lawan: TipeAkun[];
        penjelasan: string;
    }
> = {
    Pengeluaran: {
        labelSumber: 'Dibayar dari (kas/bank)',
        labelTujuan: 'Untuk akun beban/aset',
        sumberKas: true,
        tujuanKas: false,
        lawan: ['Beban', 'Aset'],
        penjelasan: 'Biaya operasional seperti sewa, listrik, gaji, atau pembelian perlengkapan.',
    },
    Penerimaan: {
        labelSumber: 'Diterima dari akun',
        labelTujuan: 'Masuk ke (kas/bank)',
        sumberKas: false,
        tujuanKas: true,
        lawan: ['Pendapatan', 'Ekuitas', 'Kewajiban', 'Aset'],
        penjelasan: 'Uang masuk di luar penjualan: setoran modal, pinjaman, bunga bank, pendapatan lain.',
    },
    Transfer: {
        labelSumber: 'Dari kas/bank',
        labelTujuan: 'Ke kas/bank',
        sumberKas: true,
        tujuanKas: true,
        lawan: [],
        penjelasan: 'Pindah dana antar akun kas/bank, misalnya setoran kas brankas ke bank.',
    },
};

function SaringAkun(akun: OpsiAkunKasBank[], kasBank: boolean, lawan: TipeAkun[]) {
    return akun.filter((a) => (kasBank ? a.KasBank : !a.KasBank && lawan.includes(a.Jenis)));
}

/** Audit kemudahan pakai #15: nama akun tanpa kode (kode hanya di mode akuntan). */
function OpsiAkun(akun: OpsiAkunKasBank[], kasBank: boolean, lawan: TipeAkun[], tampilKode: boolean) {
    return SaringAkun(akun, kasBank, lawan).map((a) => ({ Nilai: a.Uuid, Label: LabelAkun(a, tampilKode) }));
}

/** Akun kas/bank atau lawan yang hanya punya satu pilihan langsung terisi (aturan isi-otomatis v3.25). */
function IsiAkunTunggal(akun: OpsiAkunKasBank[], jenis: JenisTransaksiKasBank) {
    const aturan = aturanJenis[jenis];
    const sumber = SaringAkun(akun, aturan.sumberKas, aturan.lawan);
    const tujuan = SaringAkun(akun, aturan.tujuanKas, aturan.lawan);

    return {
        UuidAkunSumber: sumber.length === 1 ? (sumber[0]?.Uuid ?? '') : '',
        UuidAkunTujuan: tujuan.length === 1 ? (tujuan[0]?.Uuid ?? '') : '',
    };
}

type IsianTransaksi = {
    Jenis: JenisTransaksiKasBank;
    Tanggal: string;
    UuidOutlet: string;
    UuidAkunSumber: string;
    UuidAkunTujuan: string;
    Jumlah: string;
    Keterangan: string;
    Lampiran: File[];
    /** D-23 D: 'Tidak' = sekali saja. */
    Ulangi: 'Tidak' | 'Mingguan' | 'Bulanan';
};

/**
 * F-13a halaman "Catat transaksi kas & bank" (FIN-03). Setelah disimpan (langsung dijurnal), server mengarahkan ke
 * detail dokumen baru.
 */
export default function HalamanBuatTransaksiKasBank({
    OpsiJenis,
    OpsiOutlet,
    OpsiAkun: akun,
    WajibOutlet,
    Lampiran,
}: PropsBuatTransaksiKasBank) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [isian, AturIsian] = useState<IsianTransaksi>(() => ({
        Jenis: 'Pengeluaran',
        Tanggal: TulisTanggal(new Date()),
        UuidOutlet: WajibOutlet && OpsiOutlet.length === 1 ? (OpsiOutlet[0]?.Uuid ?? '') : '',
        ...IsiAkunTunggal(akun, 'Pengeluaran'),
        Jumlah: '',
        Keterangan: '',
        Lampiran: [],
        Ulangi: 'Tidak',
    }));
    const [memproses, AturMemproses] = useState(false);
    const [tampilKode, AturTampilKode] = useTampilKodeAkun();
    const aturan = aturanJenis[isian.Jenis];

    const Ubah = (ubah: Partial<IsianTransaksi>) => AturIsian({ ...isian, ...ubah });

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        const { Lampiran: berkas, ...data } = isian;
        router.post(
            alamat,
            {
                ...data,
                UuidOutlet: data.UuidOutlet === '' ? null : data.UuidOutlet,
                Ulangi: data.Ulangi === 'Tidak' ? null : data.Ulangi,
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

    return (
        <TataLetakAplikasi judul="Catat transaksi kas & bank">
            <DaftarGalatServer galat={galat} kecuali={Object.keys(isian)} />
            <KartuFormulir keterangan="Pengeluaran operasional, penerimaan di luar penjualan, atau transfer antar kas/bank. Transaksi langsung dijurnal saat disimpan dan tidak bisa diubah. Koreksi dengan dokumen pembalik.">
                <form
                    onSubmit={Simpan}
                    className="flex flex-col gap-4"
                    aria-label="Formulir transaksi kas & bank"
                    noValidate
                >
                    <BidangOutlet
                        nilai={isian.UuidOutlet}
                        opsi={OpsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                        {...(WajibOutlet ? {} : { kosong: 'Tingkat usaha (tanpa outlet)' })}
                        saatBerubah={(nilai) => Ubah({ UuidOutlet: nilai })}
                        galat={galat.UuidOutlet}
                        required={WajibOutlet}
                    />
                    <BidangPilihan
                        label="Jenis transaksi"
                        nilai={isian.Jenis}
                        opsi={OpsiJenis}
                        saatBerubah={(nilai) =>
                            Ubah({
                                Jenis: nilai as JenisTransaksiKasBank,
                                ...IsiAkunTunggal(akun, nilai as JenisTransaksiKasBank),
                            })
                        }
                        galat={galat.Jenis}
                        required
                    />
                    <p className="text-label text-teks-sekunder">{aturan.penjelasan}</p>
                    <PemilihTanggal
                        label="Tanggal"
                        nilai={isian.Tanggal}
                        saatBerubah={(nilai) => Ubah({ Tanggal: nilai })}
                        galat={galat.Tanggal}
                        required
                    />
                    <BidangPilihan
                        label={aturan.labelSumber}
                        nilai={isian.UuidAkunSumber}
                        kosong="Pilih akun"
                        opsi={OpsiAkun(akun, aturan.sumberKas, aturan.lawan, tampilKode)}
                        saatBerubah={(nilai) => Ubah({ UuidAkunSumber: nilai })}
                        galat={galat.UuidAkunSumber}
                        required
                    />
                    <BidangPilihan
                        label={aturan.labelTujuan}
                        nilai={isian.UuidAkunTujuan}
                        kosong="Pilih akun"
                        opsi={OpsiAkun(akun, aturan.tujuanKas, aturan.lawan, tampilKode)}
                        saatBerubah={(nilai) => Ubah({ UuidAkunTujuan: nilai })}
                        galat={galat.UuidAkunTujuan}
                        required
                    />
                    <KotakCentang
                        label="Tampilkan kode akun (untuk akuntan)"
                        nilai={tampilKode}
                        saatBerubah={AturTampilKode}
                    />
                    <BidangUang
                        label="Jumlah"
                        nilai={isian.Jumlah}
                        saatBerubah={(nilai) => Ubah({ Jumlah: nilai })}
                        galat={galat.Jumlah}
                        required
                    />
                    <BidangTeksPanjang
                        label="Keterangan"
                        nilai={isian.Keterangan}
                        saatBerubah={(nilai) => Ubah({ Keterangan: nilai })}
                        galat={galat.Keterangan}
                        maksimal={255}
                        required
                    />
                    <BidangBerkas
                        label="Lampiran (nota atau bukti transfer)"
                        berkas={isian.Lampiran}
                        saatBerubah={(berkas) => Ubah({ Lampiran: berkas })}
                        ekstensi={Lampiran.Ekstensi}
                        maksimal={1}
                        ukuranMaksimalKb={Lampiran.UkuranMaksimalKb}
                        galat={galat.Lampiran}
                    />
                    <GrupRadio<IsianTransaksi['Ulangi']>
                        legenda="Ulangi otomatis"
                        nilai={isian.Ulangi}
                        opsi={[
                            { Nilai: 'Tidak', Label: 'Tidak, sekali ini saja' },
                            {
                                Nilai: 'Bulanan',
                                Label: 'Tiap bulan',
                                Keterangan:
                                    'Misal sewa, listrik, internet: dicatat lagi pada tanggal yang sama tiap bulan.',
                            },
                            { Nilai: 'Mingguan', Label: 'Tiap minggu', Keterangan: 'Dicatat lagi 7 hari sekali.' },
                        ]}
                        saatBerubah={(nilai) => Ubah({ Ulangi: nilai })}
                        galat={galat.Ulangi}
                    />
                    <BilahAksiForm>
                        <Tombol type="button" varian="sekunder" onClick={() => router.visit(alamat)}>
                            Batal
                        </Tombol>
                        <Tombol type="submit" memproses={memproses}>
                            Simpan & jurnal
                        </Tombol>
                    </BilahAksiForm>
                </form>
            </KartuFormulir>
        </TataLetakAplikasi>
    );
}
