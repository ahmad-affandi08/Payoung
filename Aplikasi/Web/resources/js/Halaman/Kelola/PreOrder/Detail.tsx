import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import { KartuKeterangan, Keterangan, RingkasanNilai } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import Tombol from '@/Komponen/Formulir/Tombol';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsDetailPreOrder } from '@/Tipe/PreOrder';

import { AlamatPreOrder, AmbilJenisStatusPreOrder } from './Daftar';

/** Angka desimal server (`12500.00`) lebih dari nol, dibaca sebagai teks (tanpa float). */
export function CekPositif(nilai: string): boolean {
    return !nilai.trim().startsWith('-') && /[1-9]/.test(nilai);
}

/**
 * F-12 bagian 2: detail pre-order (barang, uang muka, pengambilan). Tandai siap (`penjualan.buat`); batalkan atau
 * selesaikan sisa uang muka (`akuntansi.kelola`): dikembalikan dari akun kas/bank atau hangus menjadi pendapatan lain.
 */
export default function HalamanDetailPreOrder({ Pesanan: p, OpsiAkun, OpsiCara, Izin }: PropsDetailPreOrder) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [dialog, AturDialog] = useState(false);
    const [cara, AturCara] = useState('Dikembalikan');
    const [akun, AturAkun] = useState(OpsiAkun[0]?.Uuid ?? '');
    const [alasan, AturAlasan] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const terbuka = p.Status === 'Dipesan' || p.Status === 'Siap';
    const adaSisa = CekPositif(p.SisaUangMuka);
    const bolehSelesaikan = Izin.Selesaikan && (terbuka || (p.Status === 'Diambil' && adaSisa));
    const labelSelesaikan = terbuka ? 'Batalkan pre-order' : 'Selesaikan sisa uang muka';

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.post(
            `${AlamatPreOrder}/${p.Uuid}/selesaikan-uang-muka`,
            { Cara: cara, UuidAkun: cara === 'Dikembalikan' ? akun : null, Alasan: alasan },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: () => AturDialog(false),
            },
        );
    };

    return (
        <TataLetakAplikasi judul={`Pre-order ${p.Nomor}`}>
            {p.AlasanBatal ? (
                <Pemberitahuan jenis="bahaya" judul="Pre-order dibatalkan">
                    {p.AlasanBatal}
                </Pemberitahuan>
            ) : null}
            {p.Status === 'Diambil' && adaSisa ? (
                <Pemberitahuan jenis="peringatan" judul={`Sisa uang muka ${FormatRupiah(p.SisaUangMuka)}`}>
                    Uang muka lebih besar dari tagihan saat diambil. Kembalikan ke pelanggan atau catat hangus.
                </Pemberitahuan>
            ) : null}
            <AksiHalaman
                keterangan={<LabelStatus jenis={AmbilJenisStatusPreOrder(p.Status)} teks={p.LabelStatus} />}
                langkah={
                    Izin.Siap && p.Status === 'Dipesan'
                        ? [
                              {
                                  label: 'Tandai siap diambil',
                                  saatKlik: () =>
                                      router.post(`${AlamatPreOrder}/${p.Uuid}/siap`, {}, { preserveScroll: true }),
                              },
                          ]
                        : []
                }
                lainnya={
                    bolehSelesaikan
                        ? [{ label: labelSelesaikan, saatKlik: () => AturDialog(true), bahaya: terbuka }]
                        : []
                }
            />

            <KartuKeterangan>
                <Keterangan label="Nomor">
                    <span className="font-mono">{p.Nomor}</span>
                </Keterangan>
                <Keterangan label="Pelanggan">
                    {p.Pelanggan}
                    {p.NoHpPelanggan ? (
                        <span className="font-mono text-teks-sekunder"> | {p.NoHpPelanggan}</span>
                    ) : null}
                </Keterangan>
                <Keterangan label="Tanggal pesan">{FormatTanggal(p.TanggalPesan)}</Keterangan>
                <Keterangan label="Tanggal ambil">{FormatTanggal(p.TanggalAmbil)}</Keterangan>
                <Keterangan label="Outlet">{p.Outlet}</Keterangan>
                {p.Penjualan ? (
                    <Keterangan label="Penjualan pengambilan">
                        <Link href={`/kelola/penjualan/${p.Penjualan.Uuid}`} className="font-mono text-brand underline">
                            {p.Penjualan.Nomor}
                        </Link>
                    </Keterangan>
                ) : null}
                {p.Catatan ? <Keterangan label="Catatan">{p.Catatan}</Keterangan> : null}
            </KartuKeterangan>

            <Panel judul="Barang dipesan" idJudul="judul-barang-pre-order">
                <ul className="flex flex-col gap-2">
                    {p.Baris.map((b) => (
                        <li
                            key={b.Uuid}
                            className="flex flex-wrap items-center justify-between gap-2 rounded-panel border border-garis px-3 py-2"
                        >
                            <span className="flex flex-col">
                                <span className="font-semibold break-words">{b.NamaProduk}</span>
                                <span className="text-keterangan text-teks-sekunder">
                                    {[
                                        `${b.Jumlah.replace(/\.?0+$/, '')} × ${FormatRupiah(b.HargaSatuan)}`,
                                        ...b.Pilihan,
                                        b.Catatan,
                                    ]
                                        .filter(Boolean)
                                        .join(' | ')}
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>
            </Panel>

            <Panel judul="Uang muka" idJudul="judul-uang-muka-pre-order">
                <ul className="flex flex-col gap-2">
                    {p.Pembayaran.map((b) => (
                        <li key={b.Uuid} className="flex flex-wrap items-center justify-between gap-2">
                            <span>
                                {b.Metode}
                                {b.Referensi ? <span className="text-teks-sekunder"> | {b.Referensi}</span> : null}
                            </span>
                            <span className="tabular-nums">{FormatRupiah(b.Jumlah)}</span>
                        </li>
                    ))}
                </ul>
                <RingkasanNilai
                    baris={[
                        { label: 'Perkiraan total pesanan', nilai: p.TotalPesanan },
                        { label: 'Uang muka diterima', nilai: p.UangMuka },
                        ...(CekPositif(p.UangMukaTerpakai)
                            ? [{ label: 'Dipakai saat diambil', nilai: p.UangMukaTerpakai }]
                            : []),
                        ...(CekPositif(p.UangMukaDikembalikan)
                            ? [{ label: 'Dikembalikan', nilai: p.UangMukaDikembalikan }]
                            : []),
                        ...(CekPositif(p.UangMukaHangus) ? [{ label: 'Hangus', nilai: p.UangMukaHangus }] : []),
                        { label: 'Sisa uang muka', nilai: p.SisaUangMuka, tebal: true },
                    ]}
                />
            </Panel>

            {dialog ? (
                <DialogFormulir
                    judul={`${labelSelesaikan} ${p.Nomor}?`}
                    galatUmum={galat.Umum}
                    saatTutup={() => AturDialog(false)}
                >
                    <form
                        onSubmit={Kirim}
                        className="flex flex-col gap-4"
                        aria-label="Formulir uang muka pre-order"
                        noValidate
                    >
                        {adaSisa ? (
                            <BidangPilihan
                                label={`Sisa uang muka ${FormatRupiah(p.SisaUangMuka)}`}
                                nilai={cara}
                                opsi={OpsiCara}
                                saatBerubah={AturCara}
                                required
                            />
                        ) : null}
                        {adaSisa && cara === 'Dikembalikan' ? (
                            <BidangPilihan
                                label="Dikembalikan dari akun"
                                nilai={akun}
                                opsi={OpsiAkun.map((a) => ({ Nilai: a.Uuid, Label: `${a.Kode} | ${a.Nama}` }))}
                                saatBerubah={AturAkun}
                                galat={galat.UuidAkun}
                                required
                            />
                        ) : null}
                        <BidangTeksPanjang
                            label="Alasan"
                            nilai={alasan}
                            saatBerubah={AturAlasan}
                            galat={galat.Alasan}
                            maksimal={255}
                            required
                        />
                        <div className="flex flex-wrap justify-end gap-2">
                            <Tombol type="button" varian="sekunder" onClick={() => AturDialog(false)}>
                                Kembali
                            </Tombol>
                            <Tombol type="submit" varian={terbuka ? 'bahaya' : 'utama'} memproses={memproses}>
                                {labelSelesaikan}
                            </Tombol>
                        </div>
                    </form>
                </DialogFormulir>
            ) : null}
        </TataLetakAplikasi>
    );
}
