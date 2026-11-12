import { router } from '@inertiajs/react';
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

type QrMeja = { NamaMeja: string; Url: string; QrSvg: string };

type PropsSakelar = { alamatOutlet: string; pesanSendiri: PesanSendiriOutlet; bolehKelola: boolean };

/**
 * F-17: sakelar pesan sendiri lewat QR meja per outlet. Menghidupkan butuh fitur paket `kanal.self-order` (add-on);
 * tanpa fitur tombol hidupkan nonaktif dengan penjelasan, mematikan tetap bisa.
 */
export function SakelarPesanSendiri({ alamatOutlet, pesanSendiri, bolehKelola }: PropsSakelar) {
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
        </div>
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
