import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { DaftarPeringkat, KartuWidget } from '@/Komponen/Kelola/KartuWidget';
import DialogAturWidget from '@/Komponen/Laporan/DialogAturWidget';
import { Button } from '@/Komponen/Ui/button';
import { cn } from '@/Komponen/Ui/utils';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { JumlahkanDesimal } from '@/Pustaka/HitungDesimal';
import {
    BacaWidgetTersembunyi,
    DAFTAR_WIDGET,
    HitungPersenBagian,
    JenisStatusStok,
    LabelTingkatAntiFraud,
    SimpanWidgetTersembunyi,
    type KodeWidget,
} from '@/Pustaka/WidgetBeranda';
import type { DasborPemilik } from '@/Tipe/Laporan';

/** Warna segmen batang proporsi metode bayar; tiap segmen juga punya nama & persen di legenda (warna bukan satu-satunya penanda). */
const WARNA_SEGMEN = ['bg-brand', 'bg-grafik-2', 'bg-grafik-3', 'bg-grafik-4', 'bg-garis-input'] as const;

function WidgetMetode({ data, tautan }: { data: DasborPemilik; tautan: string }) {
    const { Total, Baris } = data.Widget.MetodeBayar;

    return (
        <KartuWidget
            judul="Metode pembayaran"
            angka={FormatRupiah(Total)}
            tautan={`${tautan}&tab=metode`}
            teksTautan="Buka laporan metode"
        >
            {Baris.length === 0 ? (
                <p className="text-isi text-teks-sekunder">Belum ada pembayaran pada periode ini.</p>
            ) : (
                <>
                    <div className="flex h-2.5 gap-0.5 overflow-hidden rounded-full" aria-hidden="true">
                        {Baris.map((b, i) => (
                            <div
                                key={b.Kunci}
                                className={WARNA_SEGMEN[i % WARNA_SEGMEN.length]}
                                style={{ width: `${String(Math.max(1, HitungPersenBagian(b.Nilai, Total)))}%` }}
                            />
                        ))}
                    </div>
                    <ul className="flex flex-col gap-2">
                        {Baris.map((b, i) => (
                            <li key={b.Kunci} className="flex items-center gap-2 text-isi text-teks-utama">
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'size-2.5 shrink-0 rounded-sm',
                                        WARNA_SEGMEN[i % WARNA_SEGMEN.length],
                                    )}
                                />
                                <span className="min-w-0 flex-1 truncate" title={b.Nama}>
                                    {b.Nama}
                                </span>
                                <span className="font-semibold tabular-nums">{FormatRupiah(b.Nilai)}</span>
                                <span className="w-10 text-right text-keterangan text-teks-sekunder tabular-nums">
                                    {HitungPersenBagian(b.Nilai, Total)}%
                                </span>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </KartuWidget>
    );
}

function WidgetAntiFraud({ data, tautan }: { data: DasborPemilik; tautan: string }) {
    const a = data.Widget.AntiFraud;
    const indikator: { label: string; nilai: string; perhatian: boolean }[] = [
        { label: 'Void tunai cepat', nilai: `${String(a.VoidCepatTunai)} kali`, perhatian: a.VoidCepatTunai > 0 },
        { label: 'Transaksi didiskon', nilai: `${String(a.JumlahBerdiskon)} kali`, perhatian: false },
        {
            label: 'Buka laci tanpa transaksi',
            nilai: `${String(a.BukaLaciManual)} kali`,
            perhatian: a.BukaLaciManual > 0,
        },
        {
            label: 'Shift kas kurang',
            nilai: `${String(a.ShiftSelisihKurang)} shift`,
            perhatian: a.ShiftSelisihKurang > 0,
        },
    ];

    return (
        <KartuWidget
            judul="Kontrol anti-fraud"
            angka={LabelTingkatAntiFraud(a.Tingkat)}
            tautan={`${tautan}&tab=anti-fraud`}
            teksTautan="Buka tab anti-fraud"
        >
            <p className="text-keterangan text-teks-sekunder">
                {a.NamaKasirTertinggi === ''
                    ? 'Belum ada transaksi pada periode ini.'
                    : `Skor risiko tertinggi ${String(a.SkorTertinggi)} dari 100 (${a.NamaKasirTertinggi}).`}
            </p>
            <ul className="flex flex-col gap-2">
                {indikator.map((i) => (
                    <li key={i.label} className="flex items-center justify-between gap-2 text-isi text-teks-utama">
                        <span className="min-w-0 truncate">{i.label}</span>
                        <span className="flex shrink-0 items-center gap-2">
                            <span className="font-semibold tabular-nums">{i.nilai}</span>
                            <LabelStatus
                                jenis={i.perhatian ? 'peringatan' : 'sukses'}
                                teks={i.perhatian ? 'Cek' : 'Wajar'}
                            />
                        </span>
                    </li>
                ))}
            </ul>
        </KartuWidget>
    );
}

function WidgetKomisi({ data }: { data: DasborPemilik }) {
    const komisi = data.Widget.Komisi;

    if (komisi === null) {
        return null;
    }

    return (
        <KartuWidget
            judul="Komisi staf"
            angka={FormatRupiah(komisi.Total)}
            tautan="/kelola/karyawan/komisi/laporan"
            teksTautan="Buka laporan komisi"
        >
            {komisi.Baris.length === 0 ? (
                <div className="flex flex-col gap-1">
                    <p className="text-isi font-semibold text-teks-utama">Belum ada komisi pada periode ini.</p>
                    <p className="text-keterangan text-teks-sekunder">
                        Atur aturan komisi supaya penjualan staf pelayan terhitung otomatis.
                    </p>
                    <Button
                        asChild
                        variant="outline"
                        className="mt-2 h-8 w-fit pointer-coarse:h-11 px-4 text-label font-semibold"
                    >
                        <Link href="/kelola/karyawan/komisi">Atur komisi</Link>
                    </Button>
                </div>
            ) : (
                <DaftarPeringkat
                    baris={komisi.Baris.map((b) => ({
                        kunci: b.Kunci,
                        nama: b.Nama,
                        kanan: FormatRupiah(b.Nilai),
                        nilai: b.Nilai,
                    }))}
                    kosong=""
                />
            )}
        </KartuWidget>
    );
}

/** Peringkat uang sederhana (jenis pesanan, kategori, kasir). */
function WidgetPeringkatUang({
    judul,
    data,
    tautan,
    teksTautan,
    kosong,
}: {
    judul: string;
    data: DasborPemilik['Widget']['Kasir'];
    tautan: string;
    teksTautan: string;
    kosong: string;
}) {
    return (
        <KartuWidget judul={judul} angka={FormatRupiah(data.Total)} tautan={tautan} teksTautan={teksTautan}>
            <DaftarPeringkat
                baris={data.Baris.map((b) => ({
                    kunci: b.Kunci,
                    nama: b.Nama,
                    kanan: FormatRupiah(b.Nilai),
                    nilai: b.Nilai,
                }))}
                kosong={kosong}
            />
        </KartuWidget>
    );
}

function WidgetTerlaris({ data, judul }: { data: DasborPemilik; judul: string }) {
    const nilai = JumlahkanDesimal(data.ProdukTerlaris.map((p) => p.Bersih));

    return (
        <KartuWidget
            judul={judul}
            angka={FormatRupiah(nilai)}
            tautan="/kelola/laporan/penjualan?tab=produk"
            teksTautan="Buka laporan produk"
        >
            <DaftarPeringkat
                baris={data.ProdukTerlaris.map((p) => ({
                    kunci: p.Kunci,
                    nama: p.NamaProduk,
                    kanan: `${FormatJumlahStok(p.Qty)} terjual`,
                    nilai: p.Qty,
                }))}
                kosong="Belum ada penjualan pada periode ini."
            />
        </KartuWidget>
    );
}

function WidgetStok({ data }: { data: DasborPemilik }) {
    const stok = data.StokKritis;

    if (stok === null) {
        return null;
    }

    return (
        <KartuWidget
            judul="Stok kritis"
            angka={stok.Jumlah === 0 ? 'Aman' : `${String(stok.Jumlah)} produk`}
            tautan="/kelola/laporan/stok?tab=kritis"
            teksTautan="Buka laporan stok"
        >
            {stok.Baris.length === 0 ? (
                <p className="text-isi text-teks-sekunder">Semua produk di atas stok minimum.</p>
            ) : (
                <>
                    <ul className="flex flex-col gap-2">
                        {stok.Baris.map((b) => (
                            <li
                                key={b.Kunci}
                                className="flex items-center justify-between gap-2 rounded-kontrol bg-permukaan-redup px-2 py-1.5 text-isi text-teks-utama"
                            >
                                <span className="min-w-0 truncate" title={`${b.NamaProduk} | ${b.NamaOutlet}`}>
                                    {b.NamaProduk}
                                </span>
                                <span className="flex shrink-0 items-center gap-2">
                                    <span className="font-semibold tabular-nums">
                                        {FormatJumlahStok(b.Saldo, b.SimbolSatuan)}
                                    </span>
                                    <LabelStatus
                                        jenis={JenisStatusStok(b.Saldo)}
                                        teks={JenisStatusStok(b.Saldo) === 'bahaya' ? 'Minus' : 'Menipis'}
                                    />
                                </span>
                            </li>
                        ))}
                    </ul>
                    <p className="text-keterangan text-teks-sekunder">
                        Saldo minus berarti barang keluar sebelum penerimaannya dicatat.
                    </p>
                </>
            )}
        </KartuWidget>
    );
}

/**
 * Widget "Ringkasan cepat" di Beranda (D-203): delapan kartu peringkat untuk periode yang sama dengan angka di atasnya.
 * Tanpa ikon dan tanpa grafik bersumbu: nama + nilai sebaris dengan batang tipis, satu angka utama per kartu, dan
 * tautan ke laporan asal yang membawa periode yang sama. Widget yang datanya di luar izin pengguna (komisi, stok)
 * tidak dirender; pengguna boleh menyembunyikan widget (disimpan per peramban).
 */
export default function RingkasanCepat({ data, judulTerlaris }: { data: DasborPemilik; judulTerlaris: string }) {
    const [tersembunyi, AturTersembunyi] = useState<KodeWidget[]>(BacaWidgetTersembunyi);
    const [aturBuka, AturAturBuka] = useState(false);
    const { Periode: periode, Widget: w } = data;
    const tautan = `/kelola/laporan/penjualan?dari=${periode.Dari}&sampai=${periode.Sampai}`;
    const tersedia: KodeWidget[] = DAFTAR_WIDGET.map((d) => d.Kode).filter(
        (kode) => !(kode === 'komisi' && w.Komisi === null) && !(kode === 'stok' && data.StokKritis === null),
    );
    const Tampil = (kode: KodeWidget) => tersedia.includes(kode) && !tersembunyi.includes(kode);
    const Ubah = (daftar: KodeWidget[]) => {
        AturTersembunyi(daftar);
        SimpanWidgetTersembunyi(daftar);
    };

    return (
        <section aria-labelledby="judul-ringkasan-cepat" className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h3 id="judul-ringkasan-cepat" className="text-subjudul font-semibold text-teks-utama">
                    Ringkasan cepat
                </h3>
                <Button
                    type="button"
                    variant="outline"
                    className="h-8 pointer-coarse:h-11 px-4 text-label font-semibold"
                    onClick={() => AturAturBuka(true)}
                >
                    Atur widget
                </Button>
            </div>

            {tersedia.every((kode) => !Tampil(kode)) ? (
                <p className="rounded-panel border border-garis bg-permukaan px-4 py-3 text-isi text-teks-sekunder">
                    Semua widget disembunyikan. Pilih "Atur widget" untuk menampilkannya lagi.
                </p>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {Tampil('metode') ? <WidgetMetode data={data} tautan={tautan} /> : null}
                    {Tampil('pesanan') ? (
                        <WidgetPeringkatUang
                            judul="Jenis pesanan"
                            data={w.JenisPesanan}
                            tautan={`${tautan}&tab=kanal`}
                            teksTautan="Buka laporan jenis pesanan"
                            kosong="Belum ada penjualan pada periode ini."
                        />
                    ) : null}
                    {Tampil('kategori') ? (
                        <WidgetPeringkatUang
                            judul="Penjualan per kategori"
                            data={w.Kategori}
                            tautan={`${tautan}&tab=kategori`}
                            teksTautan="Buka laporan kategori"
                            kosong="Belum ada penjualan pada periode ini."
                        />
                    ) : null}
                    {Tampil('antifraud') ? <WidgetAntiFraud data={data} tautan={tautan} /> : null}
                    {Tampil('terlaris') ? <WidgetTerlaris data={data} judul={judulTerlaris} /> : null}
                    {Tampil('komisi') ? <WidgetKomisi data={data} /> : null}
                    {Tampil('kasir') ? (
                        <WidgetPeringkatUang
                            judul="Penjualan per kasir"
                            data={w.Kasir}
                            tautan={`${tautan}&tab=kasir`}
                            teksTautan="Buka laporan kasir"
                            kosong="Belum ada penjualan pada periode ini."
                        />
                    ) : null}
                    {Tampil('stok') ? <WidgetStok data={data} /> : null}
                </div>
            )}

            {aturBuka ? (
                <DialogAturWidget
                    daftar={DAFTAR_WIDGET.filter((w) => tersedia.includes(w.Kode))}
                    tersembunyi={tersembunyi}
                    saatUbah={Ubah}
                    saatTutup={() => AturAturBuka(false)}
                />
            ) : null}
        </section>
    );
}
