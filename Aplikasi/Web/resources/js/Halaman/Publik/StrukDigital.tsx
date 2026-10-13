import { Head, usePage } from '@inertiajs/react';

import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import StatusLacakLaundry from '@/Komponen/Laundry/StatusLacakLaundry';
import FormUmpanBalik, { type UmpanBalikStruk } from '@/Komponen/Struk/FormUmpanBalik';
import { PENUTUP_BAWAAN } from '@/Komponen/Struk/PratinjauStruk';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { StatusLaundryPublik } from '@/Tipe/Laundry';

export type StrukDigital = {
    NamaUsaha: string;
    TeksKepala: string[];
    NamaOutlet: string | null;
    Alamat: string | null;
    Npwp: string | null;
    Nomor: string;
    Waktu: string;
    NamaKasir: string | null;
    NamaPelanggan: string | null;
    Dibatalkan: boolean;
    Baris: {
        NamaProduk: string;
        Pilihan: string[];
        Jumlah: string;
        HargaSatuan: string;
        Diskon: string;
        Total: string;
        /** F-05h: nomor seri/IMEI yang dijual dan tanggal garansi berakhir (null = tanpa garansi). */
        NomorSeri: string[];
        GaransiSampai: string | null;
    }[];
    Subtotal: string;
    TotalDiskon: string;
    BiayaLayanan: string;
    /** F-17 bagian 3: ongkir kotor dan potongannya (gratis ongkir); "0.00" = tanpa ongkir. */
    BiayaKirim: string;
    DiskonKirim: string;
    Pajak: { Kode: string; Tarif: string; Jumlah: string }[];
    Pembulatan: string;
    TotalAkhir: string;
    Pembayaran: { NamaMetode: string; Jumlah: string }[];
    Kembalian: string;
    TotalRetur: string | null;
    /** F-16c bagian 4a: poin yang diperoleh pelanggan dari transaksi ini (null = tidak ada). */
    PoinDiperoleh?: number | null;
    /** D-110: tautan toko online outlet ini bila sedang menerima pesanan. */
    TokoOnline?: string | null;
    CatatanKaki: string | null;
    TeksPenutup: string | null;
    /** Laundry (§9.9): status proses cucian bila transaksi ini bertiket laundry. */
    Laundry?: StatusLaundryPublik | null;
    /** F7: form penilaian (`Diminta`) atau penilaian yang sudah dikirim; null = tidak ditawarkan. */
    UmpanBalik?: UmpanBalikStruk | null;
};

/** Nilai uang nol ("0.00", "-0.00"). */
function CekNol(nilai: string): boolean {
    return /^-?0+(\.0+)?$/.test(nilai);
}

/** Jumlah tanpa nol desimal berlebih ("2.000" → "2"). */
function FormatJumlah(nilai: string): string {
    return nilai.includes('.') ? nilai.replace(/\.?0+$/, '') : nilai;
}

function Baris({ kiri, kanan, tebal = false }: { kiri: string; kanan: string; tebal?: boolean }) {
    return (
        <div className={`flex justify-between gap-4 ${tebal ? 'font-bold text-teks-utama' : ''}`}>
            <span>{kiri}</span>
            <span className="tabular-nums">{kanan}</span>
        </div>
    );
}

/**
 * Struk digital publik (POS-11, `/s/{kodeStruk}`): isi sama dengan struk cetak, font Mono, tanpa login. Struk yang
 * belum terkirim dari kasir, dimatikan, atau tidak dikenal menampilkan keadaan "belum tersedia".
 */
export default function HalamanStrukDigital({ Struk }: { Struk: StrukDigital | null }) {
    const { url } = usePage();
    if (Struk === null) {
        return (
            <>
                <Head title="Struk belum tersedia" />
                <main className="mx-auto flex min-h-screen max-w-md flex-col justify-center gap-2 px-4 py-10">
                    <JudulHalaman skala="ringkas">Struk belum tersedia</JudulHalaman>
                    <p className="text-isi text-teks-sekunder">
                        Struk ini belum terkirim dari kasir atau tautannya salah. Coba buka lagi beberapa saat kemudian.
                    </p>
                </main>
            </>
        );
    }

    return (
        <>
            <Head title={`Struk ${Struk.Nomor}`} />
            <main className="mx-auto flex max-w-md flex-col gap-4 px-4 py-8">
                {Struk.Laundry ? <StatusLacakLaundry Laundry={Struk.Laundry} /> : null}
                <article
                    aria-label={`Struk ${Struk.Nomor}`}
                    className="flex flex-col gap-3 rounded-kontrol border border-garis bg-permukaan p-4 font-mono text-keterangan text-teks-sekunder"
                >
                    <header className="text-center">
                        <JudulHalaman skala="ringkas" className="text-isi">
                            {Struk.NamaUsaha}
                        </JudulHalaman>
                        {Struk.NamaOutlet ? <p>{Struk.NamaOutlet}</p> : null}
                        {Struk.Alamat ? <p>{Struk.Alamat}</p> : null}
                        {Struk.TeksKepala.map((teks) => (
                            <p key={teks}>{teks}</p>
                        ))}
                        {Struk.Npwp ? <p>NPWP {Struk.Npwp}</p> : null}
                    </header>
                    {Struk.Dibatalkan ? (
                        <p
                            role="status"
                            className="rounded-kontrol border border-bahaya px-2 py-1 text-center font-bold text-bahaya"
                        >
                            TRANSAKSI DIBATALKAN
                        </p>
                    ) : null}
                    <section className="border-t border-dashed border-garis pt-2">
                        <Baris kiri={Struk.Nomor} kanan="" />
                        <p>{FormatTanggalWaktu(Struk.Waktu)}</p>
                        {Struk.NamaKasir ? <p>Kasir: {Struk.NamaKasir}</p> : null}
                        {Struk.NamaPelanggan ? <p>Pelanggan: {Struk.NamaPelanggan}</p> : null}
                    </section>
                    <section className="flex flex-col gap-1 border-t border-dashed border-garis pt-2">
                        {Struk.Baris.map((b, i) => (
                            <div key={i}>
                                <p className="text-teks-utama">{b.NamaProduk}</p>
                                {b.Pilihan.length > 0 ? <p>+ {b.Pilihan.join(', ')}</p> : null}
                                {b.NomorSeri.length > 0 ? (
                                    <p className="font-mono">No. seri: {b.NomorSeri.join(', ')}</p>
                                ) : null}
                                {b.GaransiSampai ? <p>Garansi sampai {FormatTanggal(b.GaransiSampai)}</p> : null}
                                <Baris
                                    kiri={`  ${FormatJumlah(b.Jumlah)} x ${FormatRupiah(b.HargaSatuan)}`}
                                    kanan={FormatRupiah(b.Total)}
                                />
                                {CekNol(b.Diskon) ? null : (
                                    <Baris kiri="  Diskon" kanan={`-${FormatRupiah(b.Diskon)}`} />
                                )}
                            </div>
                        ))}
                    </section>
                    <section className="flex flex-col gap-1 border-t border-dashed border-garis pt-2">
                        <Baris kiri="Subtotal" kanan={FormatRupiah(Struk.Subtotal)} />
                        {CekNol(Struk.TotalDiskon) ? null : (
                            <Baris kiri="Diskon" kanan={`-${FormatRupiah(Struk.TotalDiskon)}`} />
                        )}
                        {CekNol(Struk.BiayaLayanan) ? null : (
                            <Baris kiri="Biaya layanan" kanan={FormatRupiah(Struk.BiayaLayanan)} />
                        )}
                        {CekNol(Struk.BiayaKirim) ? null : (
                            <Baris kiri="Ongkir" kanan={FormatRupiah(Struk.BiayaKirim)} />
                        )}
                        {CekNol(Struk.DiskonKirim) ? null : (
                            <Baris kiri="Diskon ongkir" kanan={`-${FormatRupiah(Struk.DiskonKirim)}`} />
                        )}
                        {Struk.Pajak.map((p) => (
                            <Baris
                                key={p.Kode}
                                kiri={`${p.Kode} ${FormatJumlah(p.Tarif)}%`}
                                kanan={FormatRupiah(p.Jumlah)}
                            />
                        ))}
                        {CekNol(Struk.Pembulatan) ? null : (
                            <Baris kiri="Pembulatan" kanan={FormatRupiah(Struk.Pembulatan)} />
                        )}
                        <Baris kiri="TOTAL" kanan={FormatRupiah(Struk.TotalAkhir)} tebal />
                        {Struk.Pembayaran.map((b, i) => (
                            <Baris key={i} kiri={b.NamaMetode} kanan={FormatRupiah(b.Jumlah)} />
                        ))}
                        {CekNol(Struk.Kembalian) ? null : (
                            <Baris kiri="Kembalian" kanan={FormatRupiah(Struk.Kembalian)} />
                        )}
                        {Struk.TotalRetur ? (
                            <Baris kiri="Dikembalikan (retur)" kanan={`-${FormatRupiah(Struk.TotalRetur)}`} />
                        ) : null}
                        {Struk.PoinDiperoleh ? (
                            <Baris kiri="Poin diperoleh" kanan={Struk.PoinDiperoleh.toLocaleString('id-ID')} />
                        ) : null}
                    </section>
                    <footer className="border-t border-dashed border-garis pt-2 text-center">
                        {Struk.CatatanKaki ? <p>{Struk.CatatanKaki}</p> : null}
                        {Struk.TokoOnline ? (
                            <p className="mt-1">
                                Mau pesan lagi dari rumah?{' '}
                                <a href={Struk.TokoOnline} className="font-semibold text-brand underline">
                                    Buka toko online
                                </a>
                            </p>
                        ) : null}
                        <p>{Struk.TeksPenutup ?? PENUTUP_BAWAAN}</p>
                    </footer>
                </article>
                {Struk.UmpanBalik ? (
                    <FormUmpanBalik umpanBalik={Struk.UmpanBalik} alamat={`${url.split('?')[0] ?? url}/umpan-balik`} />
                ) : null}
            </main>
        </>
    );
}
