import { router, usePage } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { AmbilTandaDesimal, BandingkanDesimal, CekDesimalValid, KurangiDesimal } from '@/Pustaka/HitungDesimal';
import { FormatRupiah } from '@/Pustaka/Format';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { OpsiAkunInvoice } from '@/Tipe/Invoice';

import { AlamatInvoice } from './BagianInvoice';

/**
 * Catat pembayaran invoice (F-21): akun kas/bank tujuan, tanggal, jumlah yang dilunasi (kosong = seluruh sisa), serta
 * PPh yang dipotong pelanggan beserta nomor bukti potongnya. Jumlah yang dilunasi sudah **termasuk** potongan PPh;
 * uang yang benar-benar masuk ke akun = jumlah − PPh. PPh dicatat sebagai uang muka pajak (PPh dibayar di muka).
 */
export default function DialogPembayaranInvoice({
    uuidInvoice,
    nomor,
    sisa,
    kodePph,
    saranPph,
    opsiAkun,
    hariIni,
    saatTutup,
}: {
    uuidInvoice: string;
    nomor: string;
    sisa: string;
    kodePph: string | null;
    /** Sisa PPh yang diharapkan dipotong (PPh invoice dikurangi yang sudah dibukti-potongkan); hanya saran isian. */
    saranPph: string;
    opsiAkun: OpsiAkunInvoice[];
    hariIni: string;
    saatTutup: () => void;
}) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors as Record<string, string | undefined>;
    const [uuidAkun, AturAkun] = useState(opsiAkun.length === 1 ? (opsiAkun[0]?.Uuid ?? '') : '');
    const [tanggal, AturTanggal] = useState(hariIni);
    const [jumlah, AturJumlah] = useState('');
    const [pph, AturPph] = useState(AmbilTandaDesimal(saranPph) > 0 ? saranPph : '');
    const [bukti, AturBukti] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const dilunasi = jumlah === '' ? sisa : jumlah;
    const uangMasuk =
        CekDesimalValid(dilunasi) && (pph === '' || CekDesimalValid(pph))
            ? KurangiDesimal(dilunasi, pph === '' ? '0' : pph)
            : null;
    const melebihi = CekDesimalValid(dilunasi) && BandingkanDesimal(dilunasi, sisa) > 0;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.post(
            `${AlamatInvoice}/${uuidInvoice}/pembayaran`,
            {
                UuidAkun: uuidAkun,
                Tanggal: tanggal,
                Jumlah: jumlah === '' ? null : jumlah,
                PotonganPph: pph === '' ? null : pph,
                NomorBuktiPotong: bukti.trim() === '' ? null : bukti.trim(),
            },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: saatTutup,
            },
        );
    };

    return (
        <DialogFormulir
            judul={`Catat pembayaran ${nomor}`}
            keterangan={`Sisa tagihan ${FormatRupiah(sisa)}. Kosongkan jumlah bila pelanggan melunasi seluruh sisa.`}
            galatUmum={galat.Umum}
            saatTutup={saatTutup}
        >
            <form onSubmit={Kirim} noValidate className="flex flex-col gap-4" aria-label="Catat pembayaran invoice">
                <BidangPilihan
                    label="Masuk ke akun"
                    nilai={uuidAkun}
                    opsi={opsiAkun.map((a) => ({ Nilai: a.Uuid, Label: `${a.Kode} | ${a.Nama}` }))}
                    saatBerubah={AturAkun}
                    galat={galat.UuidAkun}
                    required
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <PemilihTanggal
                        id="tanggal-bayar-invoice"
                        label="Tanggal pembayaran"
                        nilai={tanggal}
                        max={hariIni}
                        required
                        saatBerubah={AturTanggal}
                        galat={galat.Tanggal}
                    />
                    <BidangUang
                        label="Jumlah dilunasi"
                        keterangan="Termasuk PPh yang dipotong, bila ada."
                        nilai={jumlah}
                        saatBerubah={AturJumlah}
                        galat={galat.Jumlah ?? (melebihi ? 'Jumlah melebihi sisa tagihan.' : undefined)}
                    />
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <BidangUang
                        label={kodePph ? `Dipotong ${kodePph}` : 'PPh yang dipotong pelanggan'}
                        keterangan="Isi bila pelanggan memotong PPh dan memberi bukti potong."
                        nilai={pph}
                        saatBerubah={AturPph}
                        galat={galat.PotonganPph}
                    />
                    <BidangTeks
                        label="No. bukti potong"
                        nilai={bukti}
                        saatBerubah={AturBukti}
                        maxLength={40}
                        kode
                        galat={galat.NomorBuktiPotong}
                    />
                </div>
                {uangMasuk !== null && AmbilTandaDesimal(uangMasuk) >= 0 ? (
                    <p className="text-isi text-teks-sekunder">
                        Uang yang masuk ke akun{' '}
                        <span className="font-semibold text-teks-utama tabular-nums">{FormatRupiah(uangMasuk)}</span>.
                    </p>
                ) : null}
                <div className="flex flex-wrap justify-end gap-2">
                    <Tombol type="button" varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                    <Tombol type="submit" memproses={memproses} disabled={uuidAkun === '' || melebihi}>
                        Catat pembayaran
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}
