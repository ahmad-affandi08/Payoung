import { Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

import { DaftarPeringkat, IsiWidget } from '@/Komponen/Kelola/KartuWidget';
import DaftarTab from '@/Komponen/Katalog/DaftarTab';
import Panel from '@/Komponen/Kelola/Panel';
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
    JudulTabWidget,
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
        <IsiWidget angka={FormatRupiah(Total)} tautan={`${tautan}&tab=metode`} teksTautan="Buka laporan metode">
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
        </IsiWidget>
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
        <IsiWidget
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
        </IsiWidget>
    );
}

function WidgetKomisi({ data }: { data: DasborPemilik }) {
    const komisi = data.Widget.Komisi;

    if (komisi === null) {
        return null;
    }

    return (
        <IsiWidget
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
        </IsiWidget>
    );
}

/** Peringkat uang sederhana (jenis pesanan, kategori, kasir). */
function WidgetPeringkatUang({
    data,
    tautan,
    teksTautan,
    kosong,
}: {
    data: DasborPemilik['Widget']['Kasir'];
    tautan: string;
    teksTautan: string;
    kosong: string;
}) {
    return (
        <IsiWidget angka={FormatRupiah(data.Total)} tautan={tautan} teksTautan={teksTautan}>
            <DaftarPeringkat
                baris={data.Baris.map((b) => ({
                    kunci: b.Kunci,
                    nama: b.Nama,
                    kanan: FormatRupiah(b.Nilai),
                    nilai: b.Nilai,
                }))}
                kosong={kosong}
            />
        </IsiWidget>
    );
}

function WidgetTerlaris({ data }: { data: DasborPemilik }) {
    const nilai = JumlahkanDesimal(data.ProdukTerlaris.map((p) => p.Bersih));

    return (
        <IsiWidget
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
        </IsiWidget>
    );
}

function WidgetStok({ data }: { data: DasborPemilik }) {
    const stok = data.StokKritis;

    if (stok === null) {
        return null;
    }

    return (
        <IsiWidget
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
        </IsiWidget>
    );
}

/** Widget yang belum punya isi untuk periode ini tidak mendapat tab sendiri, supaya layar tidak penuh "Rp 0". */
function WidgetKosong(kode: KodeWidget, data: DasborPemilik): boolean {
    const w = data.Widget;

    switch (kode) {
        case 'metode':
            return w.MetodeBayar.Baris.length === 0;
        case 'pesanan':
            return w.JenisPesanan.Baris.length === 0;
        case 'kategori':
            return w.Kategori.Baris.length === 0;
        case 'kasir':
            return w.Kasir.Baris.length === 0;
        case 'terlaris':
            return data.ProdukTerlaris.length === 0;
        case 'komisi':
            return w.Komisi === null || w.Komisi.Baris.length === 0;
        case 'stok':
            return data.StokKritis === null || data.StokKritis.Jumlah === 0;
        case 'antifraud': {
            const a = w.AntiFraud;

            return (
                a.NamaKasirTertinggi === '' &&
                a.VoidCepatTunai === 0 &&
                a.JumlahBerdiskon === 0 &&
                a.BukaLaciManual === 0 &&
                a.ShiftSelisihKurang === 0
            );
        }
    }
}

/**
 * Ringkasan cepat di Beranda (D-203, D-296): satu panel dengan tab, bukan delapan blok bertumpuk. Hanya widget yang
 * punya isi untuk periode itu mendapat tab (urutan tetap), dan bila semuanya kosong cukup satu kalimat. Widget yang
 * datanya di luar izin pengguna (komisi, stok) tidak dirender; pengguna boleh menyembunyikan widget (disimpan per
 * peramban). Tautan ke laporan asal membawa periode yang sama.
 */
export default function RingkasanCepat({ data, judulTerlaris }: { data: DasborPemilik; judulTerlaris: string }) {
    const [tersembunyi, AturTersembunyi] = useState<KodeWidget[]>(BacaWidgetTersembunyi);
    const [aturBuka, AturAturBuka] = useState(false);
    const [pilihan, AturPilihan] = useState<KodeWidget | null>(null);
    const { Periode: periode, Widget: w } = data;
    const tautan = `/kelola/laporan/penjualan?dari=${periode.Dari}&sampai=${periode.Sampai}`;
    const tersedia: KodeWidget[] = DAFTAR_WIDGET.map((d) => d.Kode).filter(
        (kode) => !(kode === 'komisi' && w.Komisi === null) && !(kode === 'stok' && data.StokKritis === null),
    );
    const berisi = tersedia.filter((kode) => !tersembunyi.includes(kode) && !WidgetKosong(kode, data));
    const aktif: KodeWidget | null = pilihan !== null && berisi.includes(pilihan) ? pilihan : (berisi[0] ?? null);
    const Ubah = (daftar: KodeWidget[]) => {
        AturTersembunyi(daftar);
        SimpanWidgetTersembunyi(daftar);
    };
    const Peringkat = (kode: 'pesanan' | 'kategori' | 'kasir'): ReactNode => (
        <WidgetPeringkatUang
            data={kode === 'pesanan' ? w.JenisPesanan : kode === 'kategori' ? w.Kategori : w.Kasir}
            tautan={`${tautan}&tab=${kode === 'pesanan' ? 'kanal' : kode}`}
            teksTautan={`Buka laporan ${JudulTabWidget(kode).toLowerCase()}`}
            kosong="Belum ada penjualan pada periode ini."
        />
    );
    const isi: Record<KodeWidget, ReactNode> = {
        metode: <WidgetMetode data={data} tautan={tautan} />,
        pesanan: Peringkat('pesanan'),
        kategori: Peringkat('kategori'),
        antifraud: <WidgetAntiFraud data={data} tautan={tautan} />,
        terlaris: <WidgetTerlaris data={data} />,
        komisi: <WidgetKomisi data={data} />,
        kasir: Peringkat('kasir'),
        stok: <WidgetStok data={data} />,
    };

    return (
        <Panel
            judul="Ringkasan cepat"
            aksi={
                <Button
                    type="button"
                    variant="outline"
                    className="h-8 pointer-coarse:h-11 px-4 text-label font-semibold"
                    onClick={() => AturAturBuka(true)}
                >
                    Atur widget
                </Button>
            }
        >
            {tersedia.every((kode) => tersembunyi.includes(kode)) ? (
                <p className="text-isi text-teks-sekunder">
                    Semua widget disembunyikan. Pilih "Atur widget" untuk menampilkannya lagi.
                </p>
            ) : aktif === null ? (
                <p className="text-isi text-teks-sekunder">
                    Belum ada data pada periode ini. Metode bayar, kategori, kasir, dan lainnya muncul di sini setelah
                    ada penjualan.
                </p>
            ) : (
                <DaftarTab
                    label="Ringkasan cepat"
                    tab={berisi.map((kode) => ({
                        Kunci: kode,
                        Label: kode === 'terlaris' ? judulTerlaris.replace(/ sampai .*/, '') : JudulTabWidget(kode),
                    }))}
                    aktif={aktif}
                    saatPilih={AturPilihan}
                    panel={
                        Object.fromEntries(
                            berisi.map((kode) => [kode, <div className="flex flex-col gap-3">{isi[kode]}</div>]),
                        ) as Record<KodeWidget, ReactNode>
                    }
                />
            )}

            {aturBuka ? (
                <DialogAturWidget
                    daftar={DAFTAR_WIDGET.filter((d) => tersedia.includes(d.Kode))}
                    tersembunyi={tersembunyi}
                    saatUbah={Ubah}
                    saatTutup={() => AturAturBuka(false)}
                />
            ) : null}
        </Panel>
    );
}
