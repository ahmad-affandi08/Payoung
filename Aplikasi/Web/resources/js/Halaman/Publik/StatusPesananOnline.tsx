import { Head, Link, router } from '@inertiajs/react';
import { CheckIcon, ReceiptTextIcon, XCircleIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';

type Tagihan = {
    Jumlah: string;
    Status: string;
    SudahDibayar: boolean;
    KedaluwarsaPada: string;
    UrlBayar: string | null;
    Qr: string | null;
};

type Props = {
    Ditemukan: boolean;
    Slug: string;
    KodeAkses: string;
    Toko?: { Nama: string };
    Pesanan?: {
        Nomor: string;
        NamaPelanggan: string;
        JenisPemenuhan: string;
        Status: string;
        LabelStatus: string;
        Total: string;
        Ongkir: string;
        DibuatPada: string | null;
        MetodePembayaran: string;
        PerluBayar: boolean;
        SudahDibayar: boolean;
        JumlahDibayar: string | null;
        Baris: { Nama: string; Jumlah: string; Total: string }[];
        Pengiriman: {
            Status: string;
            LabelStatus: string;
            NomorResi: string | null;
            Kurir: string | null;
            PerkiraanTibaPada: string | null;
        } | null;
    };
};

const LANGKAH_PESANAN = ['Dipesan', 'Dikonfirmasi', 'Diproses', 'Siap', 'Selesai'] as const;
const INDEKS_LANGKAH: Record<string, number> = {
    MenungguPembayaran: 0,
    MenungguKonfirmasi: 0,
    Dikonfirmasi: 1,
    Diproses: 2,
    Siap: 3,
    Selesai: 4,
};

export default function StatusPesananOnline({ Ditemukan, Slug, KodeAkses, Toko, Pesanan: p }: Props) {
    const gagal = p?.Status === 'Ditolak' || p?.Status === 'Dibatalkan' || p?.Status === 'Kedaluwarsa';
    const [tagihan, AturTagihan] = useState<Tagihan | null>(null);
    const [galat, AturGalat] = useState<string | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const perluBayar = p?.PerluBayar ?? false;
    const menunggu = useRef(false);

    async function Bayar() {
        AturMemproses(true);
        AturGalat(null);
        try {
            const respons = await fetch(`/${Slug}/pesanan/${KodeAkses}/bayar`, {
                method: 'POST',
                headers: { Accept: 'application/json' },
            });
            const json = (await respons.json()) as { Galat?: { Pesan?: string } } & Tagihan;
            if (!respons.ok) throw new Error(json.Galat?.Pesan ?? 'QRIS belum bisa dibuat. Coba lagi.');
            // Gerbang yang memakai halaman bayar sendiri mengirim URL, bukan muatan QRIS.
            if (json.UrlBayar) window.location.assign(json.UrlBayar);
            else AturTagihan(json);
        } catch (e) {
            AturGalat(e instanceof Error ? e.message : 'QRIS belum bisa dibuat. Coba lagi.');
        } finally {
            AturMemproses(false);
        }
    }

    // Webhook gerbang yang memindahkan status, bukan halaman ini: halaman hanya menanyakannya tiap lima detik.
    useEffect(() => {
        if (!perluBayar || tagihan === null) return undefined;
        const jeda = window.setInterval(() => {
            if (menunggu.current) return;
            menunggu.current = true;
            void fetch(`/${Slug}/pesanan/${KodeAkses}/status-bayar`, { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? (r.json() as Promise<{ SudahDibayar: boolean }>) : null))
                .then((j) => {
                    if (j?.SudahDibayar) router.reload();
                })
                .catch(() => undefined)
                .finally(() => {
                    menunggu.current = false;
                });
        }, 5000);

        return () => window.clearInterval(jeda);
    }, [perluBayar, tagihan, Slug, KodeAkses]);

    return (
        <div className="min-h-dvh bg-latar text-teks-utama">
            <main className="mx-auto flex w-full max-w-xl flex-col gap-5 px-4 py-6 tepi-bawah-aman">
                <Head title="Status pesanan" />
                {!Ditemukan || !p ? (
                    <>
                        <JudulHalaman>Pesanan tidak ditemukan</JudulHalaman>
                        <p className="text-teks-sekunder">Periksa kembali tautan status dari toko.</p>
                    </>
                ) : (
                    <>
                        <header className="-mx-4 -mt-6 flex flex-col gap-3 bg-brand-gelap px-4 pt-6 pb-10 text-permukaan">
                            <p className="inline-flex items-center gap-1.5 text-label font-semibold text-brand-gelap-teks">
                                <ReceiptTextIcon aria-hidden="true" className="size-4" />
                                {Toko?.Nama}
                            </p>
                            <JudulHalaman skala="situs" className="[overflow-wrap:anywhere] text-permukaan">
                                Pesanan {p.Nomor}
                            </JudulHalaman>
                        </header>
                        {gagal ? (
                            <div className="flex items-center gap-3 rounded-3xl border border-bahaya bg-bahaya-lembut p-4 text-bahaya">
                                <XCircleIcon aria-hidden="true" className="size-6 shrink-0" />
                                <p className="text-isi font-semibold">{p.LabelStatus}</p>
                            </div>
                        ) : (
                            <ol
                                aria-label="Perkembangan pesanan"
                                className="-mt-9 grid grid-cols-5 gap-1 rounded-3xl bg-permukaan p-4"
                            >
                                {LANGKAH_PESANAN.map((nama, indeks) => {
                                    const sekarang = INDEKS_LANGKAH[p.Status] ?? 0;
                                    const lewat = indeks < sekarang || p.Status === 'Selesai';
                                    const aktif = indeks === sekarang && p.Status !== 'Selesai';

                                    return (
                                        <li
                                            key={nama}
                                            aria-current={aktif ? 'step' : undefined}
                                            className="flex flex-col items-center gap-1.5 text-center"
                                        >
                                            <span
                                                className={`grid size-8 place-items-center rounded-full text-keterangan font-semibold ${
                                                    lewat
                                                        ? 'bg-sukses text-permukaan'
                                                        : aktif
                                                          ? 'bg-aksen text-teks-utama ring-4 ring-aksen-lembut'
                                                          : 'bg-permukaan-sorot text-teks-sekunder'
                                                }`}
                                            >
                                                {lewat ? (
                                                    <CheckIcon aria-hidden="true" className="size-4" />
                                                ) : (
                                                    indeks + 1
                                                )}
                                            </span>
                                            <span
                                                className={`text-keterangan ${aktif ? 'font-bold text-teks-utama' : 'text-teks-sekunder'}`}
                                            >
                                                {nama}
                                            </span>
                                        </li>
                                    );
                                })}
                            </ol>
                        )}
                        <div className="flex flex-wrap items-center gap-2">
                            <LabelStatus
                                jenis={gagal ? 'bahaya' : p.Status === 'Selesai' ? 'sukses' : 'peringatan'}
                                teks={p.LabelStatus}
                            />
                            {p.Pengiriman ? (
                                <LabelStatus
                                    jenis={p.Pengiriman.Status === 'Diterima' ? 'sukses' : 'netral'}
                                    teks={p.Pengiriman.LabelStatus}
                                />
                            ) : null}
                        </div>
                        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-isi">
                            <dt className="text-teks-sekunder">Atas nama</dt>
                            <dd>{p.NamaPelanggan}</dd>
                            <dt className="text-teks-sekunder">Pemenuhan</dt>
                            <dd>{p.JenisPemenuhan}</dd>
                            <dt className="text-teks-sekunder">Pembayaran</dt>
                            <dd>{p.MetodePembayaran}</dd>
                            <dt className="text-teks-sekunder">Dipesan</dt>
                            <dd>{p.DibuatPada ? FormatTanggalWaktu(p.DibuatPada) : '-'}</dd>
                            {p.Pengiriman?.NomorResi ? (
                                <>
                                    <dt className="text-teks-sekunder">Nomor resi</dt>
                                    <dd className="font-mono">{p.Pengiriman.NomorResi}</dd>
                                </>
                            ) : null}
                            {p.Pengiriman?.Kurir ? (
                                <>
                                    <dt className="text-teks-sekunder">Kurir</dt>
                                    <dd>{p.Pengiriman.Kurir}</dd>
                                </>
                            ) : null}
                            {p.Pengiriman?.PerkiraanTibaPada ? (
                                <>
                                    <dt className="text-teks-sekunder">Perkiraan tiba</dt>
                                    <dd>{FormatTanggalWaktu(p.Pengiriman.PerkiraanTibaPada)}</dd>
                                </>
                            ) : null}
                        </dl>
                        {perluBayar ? (
                            <section className="flex flex-col items-center gap-3 rounded-3xl bg-permukaan p-5 text-center">
                                <h2 className="text-judul-bagian-hp font-extrabold">Bayar pesanan ini</h2>
                                <p className="text-keterangan text-teks-sekunder">
                                    Pesanan diproses toko setelah pembayaran masuk. Kalau QRIS lewat waktu, buat QRIS
                                    baru di halaman ini.
                                </p>
                                {galat ? <Pemberitahuan jenis="bahaya">{galat}</Pemberitahuan> : null}
                                {tagihan?.Qr ? (
                                    <>
                                        <img
                                            src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(tagihan.Qr)}`}
                                            alt={`QRIS pembayaran pesanan ${p.Nomor}`}
                                            className="size-64 max-w-full rounded-3xl border-4 border-brand-gelap bg-permukaan p-3"
                                        />
                                        <p className="text-judul-bagian-hp font-extrabold tabular-nums">
                                            {FormatRupiah(tagihan.Jumlah)}
                                        </p>
                                        <p className="text-keterangan text-teks-sekunder">
                                            Berlaku sampai {FormatTanggalWaktu(tagihan.KedaluwarsaPada)}. Halaman ini
                                            memperbarui sendiri setelah pembayaran masuk.
                                        </p>
                                    </>
                                ) : (
                                    <Tombol type="button" memproses={memproses} onClick={() => void Bayar()}>
                                        Tampilkan QRIS
                                    </Tombol>
                                )}
                            </section>
                        ) : null}
                        {p.SudahDibayar && p.JumlahDibayar ? (
                            <Pemberitahuan jenis="sukses">
                                Pembayaran {FormatRupiah(p.JumlahDibayar)} sudah kami terima.
                            </Pemberitahuan>
                        ) : null}
                        <section className="rounded-3xl bg-permukaan p-5">
                            <h2 className="mb-3 text-judul font-extrabold">Rincian</h2>
                            <ul className="divide-y divide-garis">
                                {p.Baris.map((b, i) => (
                                    <li key={`${b.Nama}-${String(i)}`} className="flex justify-between gap-3 py-2">
                                        <span>
                                            {b.Jumlah} × {b.Nama}
                                        </span>
                                        <span className="tabular-nums">{FormatRupiah(b.Total)}</span>
                                    </li>
                                ))}
                            </ul>
                            <div className="mt-3 flex justify-between border-t border-garis pt-3 text-judul font-extrabold">
                                <span>Total</span>
                                <span>{FormatRupiah(p.Total)}</span>
                            </div>
                        </section>
                        <p className="text-keterangan text-teks-sekunder">
                            Simpan tautan ini. Kode akses: <span className="font-mono">{KodeAkses}</span>
                        </p>
                        <Link href={`/${Slug}`} className="text-label font-semibold text-brand hover:underline">
                            Kembali ke toko
                        </Link>
                    </>
                )}
            </main>
        </div>
    );
}
