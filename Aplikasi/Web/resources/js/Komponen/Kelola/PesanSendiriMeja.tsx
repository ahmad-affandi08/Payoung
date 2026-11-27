import { Link, router } from '@inertiajs/react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { Skeleton } from '@/Komponen/Ui/skeleton';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { KunciKueri } from '@/Pustaka/KunciKueri';

export type PesanSendiriOutlet = { FiturAktif: boolean; Aktif: boolean };

/** D-338: satu hal yang masih kurang sebelum QRIS bisa dipakai tamu, dengan tautan tempat memperbaikinya. */
export type HambatanQris = { Kode: string; Pesan: string; Tautan: string };

/** D-338: sakelar "Bayar sekarang lewat QRIS" (satu untuk seluruh usaha) dan sebab QRIS belum bisa dipakai. */
export type PesanSendiriQris = { Aktif: boolean; Hambatan: HambatanQris[]; BolehUbah: boolean };

type QrMeja = { NamaMeja: string; Url: string; QrSvg: string };

type PropsSakelar = {
    alamatOutlet: string;
    pesanSendiri: PesanSendiriOutlet;
    bolehKelola: boolean;
    qris?: PesanSendiriQris | undefined;
};

/**
 * F-17: sakelar pesan sendiri lewat QR meja per outlet. Menghidupkan butuh fitur paket `kanal.self-order` (add-on);
 * tanpa fitur tombol hidupkan nonaktif dengan penjelasan, mematikan tetap bisa.
 */
export function SakelarPesanSendiri({ alamatOutlet, pesanSendiri, bolehKelola, qris }: PropsSakelar) {
    const [memproses, AturMemproses] = useState(false);
    const bolehHidupkan = pesanSendiri.FiturAktif;

    const Ubah = (aktif: boolean) => {
        AturMemproses(true);
        router.post(
            `${alamatOutlet}/pesan-sendiri`,
            { Aktif: aktif },
            { preserveScroll: true, onFinish: () => AturMemproses(false) },
        );
    };

    return (
        <div className="flex flex-col gap-2 rounded-panel border border-garis bg-permukaan p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <h3 className="text-isi font-semibold text-teks-utama">Pesan sendiri lewat QR meja</h3>
                    {pesanSendiri.Aktif ? (
                        <LabelStatus jenis="sukses" teks="Aktif" />
                    ) : (
                        <LabelStatus jenis="netral" teks="Mati" />
                    )}
                </div>
                <div className="flex flex-wrap gap-2">
                    <a
                        href={`${alamatOutlet}/meja/qr`}
                        className="inline-flex min-h-8 items-center px-2 text-label font-semibold text-brand underline pointer-coarse:min-h-11"
                    >
                        Cetak semua QR meja
                    </a>
                    {bolehKelola ? (
                        pesanSendiri.Aktif ? (
                            <Tombol varian="sekunder" memproses={memproses} onClick={() => Ubah(false)}>
                                Matikan pesan sendiri
                            </Tombol>
                        ) : (
                            <Tombol memproses={memproses} disabled={!bolehHidupkan} onClick={() => Ubah(true)}>
                                Hidupkan pesan sendiri
                            </Tombol>
                        )
                    ) : null}
                </div>
            </div>
            <p className="text-keterangan text-teks-sekunder">
                Tamu memindai QR di meja, memilih menu, lalu pesanan masuk ke kasir atau pelayan. Pembayaran tetap di
                kasir.
            </p>
            {bolehHidupkan ? null : (
                <Pemberitahuan jenis="info" judul="Fitur Self-order QR belum aktif">
                    Paket usaha ini belum memuat Self-order QR. Tambahkan add-on di menu Langganan untuk menerima
                    pesanan dari QR meja.
                </Pemberitahuan>
            )}
            {qris && bolehHidupkan ? <SakelarQrisMeja alamatOutlet={alamatOutlet} qris={qris} /> : null}
        </div>
    );
}

/**
 * D-338: "Bayar QRIS dari HP" untuk pesan dari meja. Tombol QRIS di halaman tamu butuh sakelar ini, gerbang pembayaran
 * aktif, dan metode QRIS dinamis aktif. Yang masih kurang dijelaskan di sini lengkap dengan tautannya, jadi pengelola
 * tidak perlu menebak kenapa tombolnya tidak muncul.
 */
function SakelarQrisMeja({ alamatOutlet, qris }: { alamatOutlet: string; qris: PesanSendiriQris }) {
    const [memproses, AturMemproses] = useState(false);
    const siap = qris.Hambatan.length === 0;

    const Ubah = (aktif: boolean) => {
        AturMemproses(true);
        router.post(
            `${alamatOutlet}/pesan-sendiri/qris`,
            { Aktif: aktif },
            { preserveScroll: true, onFinish: () => AturMemproses(false) },
        );
    };

    return (
        <section aria-label="Bayar QRIS dari HP" className="flex flex-col gap-2 border-t border-garis pt-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <h4 className="text-isi font-semibold text-teks-utama">Bayar QRIS dari HP</h4>
                    {qris.Aktif ? (
                        siap ? (
                            <LabelStatus jenis="sukses" teks="Aktif" />
                        ) : (
                            <LabelStatus jenis="peringatan" teks="Aktif, belum siap" />
                        )
                    ) : (
                        <LabelStatus jenis="netral" teks="Mati" />
                    )}
                </div>
                {qris.BolehUbah ? (
                    qris.Aktif ? (
                        <Tombol varian="sekunder" memproses={memproses} onClick={() => Ubah(false)}>
                            Matikan QRIS dari HP
                        </Tombol>
                    ) : (
                        <Tombol memproses={memproses} disabled={!siap} onClick={() => Ubah(true)}>
                            Hidupkan QRIS dari HP
                        </Tombol>
                    )
                ) : null}
            </div>
            <p className="text-keterangan text-teks-sekunder">
                Tamu bisa membayar pesanannya sendiri lewat QRIS tanpa menunggu kasir. Pengaturan ini berlaku juga untuk
                kios dan toko online.
            </p>
            {siap ? null : (
                <Pemberitahuan
                    jenis={qris.Aktif ? 'peringatan' : 'info'}
                    judul={
                        qris.Aktif ? 'QRIS aktif tetapi belum bisa dipakai tamu' : 'Siapkan dulu sebelum menghidupkan'
                    }
                >
                    <ul className="flex flex-col gap-1">
                        {qris.Hambatan.map((hambatan) => (
                            <li key={hambatan.Kode}>
                                {hambatan.Pesan}{' '}
                                <Link href={hambatan.Tautan} className="font-semibold underline">
                                    Buka halamannya
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Pemberitahuan>
            )}
            {qris.BolehUbah ? null : (
                <p className="text-keterangan text-teks-sekunder">
                    Hanya pengguna berizin toko online yang bisa mengubah pengaturan ini.
                </p>
            )}
        </section>
    );
}

async function AmbilQrMeja(alamat: string, sinyal: AbortSignal): Promise<QrMeja> {
    const respons = await fetch(alamat, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: sinyal,
    });

    if (!respons.ok) {
        throw new Error(`QR meja gagal dimuat (${String(respons.status)})`);
    }

    return (await respons.json()) as QrMeja;
}

type PropsDialogQr = {
    alamatOutlet: string;
    uuidMeja: string;
    namaMeja: string;
    bolehKelola: boolean;
    saatTutup: () => void;
};

/**
 * F-17: QR pesan sendiri satu meja (token dibuat server saat pertama dibuka) + URL publik, dan "Buat ulang QR" (QR
 * lama langsung tidak berlaku) untuk pengelola outlet.
 */
export function DialogQrMeja({ alamatOutlet, uuidMeja, namaMeja, bolehKelola, saatTutup }: PropsDialogQr) {
    const klien = useQueryClient();
    const [konfirmasi, AturKonfirmasi] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const [tersalin, AturTersalin] = useState(false);
    const alamatQr = `${alamatOutlet}/meja/${uuidMeja}/qr`;
    const qr = useQuery({
        queryKey: KunciKueri.PesanSendiri.QrMeja(uuidMeja),
        queryFn: ({ signal }) => AmbilQrMeja(alamatQr, signal),
    });

    const Salin = (teks: string) => {
        void navigator.clipboard
            ?.writeText(teks)
            .then(() => AturTersalin(true))
            .catch(() => AturTersalin(false));
    };

    const BuatUlang = () => {
        AturMemproses(true);
        router.post(
            `${alamatQr}/buat-ulang`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    AturKonfirmasi(false);
                    AturTersalin(false);
                    void klien.invalidateQueries({ queryKey: KunciKueri.PesanSendiri.QrMeja(uuidMeja) });
                },
                onFinish: () => AturMemproses(false),
            },
        );
    };

    if (konfirmasi) {
        return (
            <DialogKonfirmasi
                judul={`Buat ulang QR meja ${namaMeja}?`}
                labelAksi="Buat ulang QR"
                memproses={memproses}
                saatKonfirmasi={BuatUlang}
                saatBatal={() => AturKonfirmasi(false)}
            >
                <p>QR lama di meja {namaMeja} langsung tidak bisa dipakai. Cetak QR baru lalu ganti QR di meja.</p>
                <p>Pesanan yang sudah masuk tidak terpengaruh.</p>
            </DialogKonfirmasi>
        );
    }

    return (
        <DialogFormulir judul={`QR pesan sendiri meja ${namaMeja}`} saatTutup={saatTutup}>
            <div className="flex flex-col items-center gap-3">
                {qr.isPending ? (
                    <Skeleton className="size-48" aria-label="Memuat QR meja" />
                ) : qr.isError ? (
                    <Pemberitahuan jenis="bahaya">
                        QR meja gagal dimuat.{' '}
                        <button type="button" className="font-semibold underline" onClick={() => void qr.refetch()}>
                            Coba lagi
                        </button>
                    </Pemberitahuan>
                ) : (
                    <>
                        <img
                            src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(qr.data.QrSvg)}`}
                            alt={`QR pesan sendiri meja ${namaMeja}`}
                            className="size-48"
                        />
                        <p className="w-full font-mono text-keterangan break-all text-teks-sekunder">{qr.data.Url}</p>
                        <div className="flex flex-wrap justify-center gap-2">
                            <Tombol varian="sekunder" onClick={() => Salin(qr.data.Url)}>
                                {tersalin ? 'Tautan disalin' : 'Salin tautan'}
                            </Tombol>
                            {bolehKelola ? (
                                <Tombol varian="bahaya" onClick={() => AturKonfirmasi(true)}>
                                    Buat ulang QR
                                </Tombol>
                            ) : null}
                        </div>
                    </>
                )}
            </div>
        </DialogFormulir>
    );
}
