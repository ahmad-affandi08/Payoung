import { router, usePage } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { BandingkanDesimal, CekDesimalValid } from '@/Pustaka/HitungDesimal';
import type { KonfirmasiPublikInvoice } from '@/Tipe/Invoice';

/**
 * Formulir "Saya sudah bayar" di halaman publik invoice (F-21 bagian 4, D-169). Ini klaim yang menunggu diverifikasi
 * penjual terhadap mutasi bank; ia tidak mengubah sisa tagihan. Dikirim ke `/i/{kode}/konfirmasi-bayar`; aturan bisnis
 * (sisa, rentang tanggal, batas klaim menunggu) ditegakkan server dan galatnya tampil di bidangnya.
 */
export default function KonfirmasiBayarInvoicePublik({
    kode,
    konfirmasi,
}: {
    kode: string;
    konfirmasi: KonfirmasiPublikInvoice;
}) {
    const hariIni = konfirmasi.HariIni;
    const { props } = usePage<{ errors: Record<string, string | undefined> }>();
    const galat = props.errors;
    const sisa = konfirmasi.Sisa ?? '0';
    const [nama, AturNama] = useState('');
    const [tanggal, AturTanggal] = useState(hariIni);
    const [jumlah, AturJumlah] = useState(sisa.replace(/\.00$/, ''));
    const [catatan, AturCatatan] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const melebihi = CekDesimalValid(jumlah) && BandingkanDesimal(jumlah, sisa) > 0;
    const siap =
        nama.trim().length >= 2 &&
        CekDesimalValid(jumlah) &&
        BandingkanDesimal(jumlah, '0') > 0 &&
        !melebihi &&
        tanggal !== '';

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.post(
            `/i/${kode}/konfirmasi-bayar`,
            {
                Nama: nama.trim(),
                TanggalBayar: tanggal,
                Jumlah: jumlah,
                Catatan: catatan.trim() === '' ? null : catatan.trim(),
            },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: () => AturCatatan(''),
            },
        );
    };

    return (
        <section
            aria-label="Konfirmasi pembayaran"
            className="flex flex-col gap-4 rounded-panel border border-garis bg-permukaan p-4 print:hidden"
        >
            <h2 className="text-subjudul font-semibold text-teks-utama">Sudah membayar?</h2>
            <p className="text-isi text-teks-sekunder">
                Beri tahu penjual supaya pembayaran Anda segera dicocokkan. Ini bukan pembayaran: penjual akan memeriksa
                mutasi rekeningnya dan mencatat pembayaran Anda.
            </p>
            {konfirmasi.Menunggu.length > 0 ? (
                <Pemberitahuan jenis="info" judul="Menunggu diperiksa penjual">
                    {konfirmasi.Menunggu.map(
                        (m) => `${FormatRupiah(m.Jumlah)} pada ${FormatTanggal(m.TanggalBayar)}`,
                    ).join('; ')}
                </Pemberitahuan>
            ) : null}
            {galat.Umum ? (
                <Pemberitahuan jenis="bahaya" judul="Belum bisa diproses">
                    {galat.Umum}
                </Pemberitahuan>
            ) : null}
            <form onSubmit={Kirim} noValidate className="flex flex-col gap-4" aria-label="Konfirmasi pembayaran">
                <div className="grid gap-4 sm:grid-cols-2">
                    <BidangTeks
                        label="Nama pengirim"
                        keterangan="Nama pemilik rekening atau perusahaan yang membayar."
                        nilai={nama}
                        saatBerubah={AturNama}
                        maxLength={100}
                        galat={galat.Nama}
                        required
                    />
                    <PemilihTanggal
                        id="tanggal-bayar-publik"
                        label="Tanggal bayar"
                        nilai={tanggal}
                        max={hariIni}
                        saatBerubah={AturTanggal}
                        galat={galat.TanggalBayar}
                        required
                    />
                </div>
                <BidangUang
                    label="Jumlah dibayar"
                    keterangan={`Sisa tagihan ${FormatRupiah(sisa)}.`}
                    nilai={jumlah}
                    saatBerubah={AturJumlah}
                    galat={galat.Jumlah ?? (melebihi ? 'Jumlah melebihi sisa tagihan.' : undefined)}
                    required
                />
                <BidangTeksPanjang
                    label="Catatan"
                    keterangan="Misalnya bank pengirim atau nomor referensi transfer."
                    nilai={catatan}
                    saatBerubah={AturCatatan}
                    maksimal={300}
                    galat={galat.Catatan}
                />
                <div>
                    <Tombol type="submit" memproses={memproses} disabled={!siap}>
                        Saya sudah bayar
                    </Tombol>
                </div>
            </form>
        </section>
    );
}
