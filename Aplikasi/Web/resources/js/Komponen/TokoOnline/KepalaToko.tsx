import {
    ClockIcon,
    MapPinIcon,
    QrCodeIcon,
    ReceiptTextIcon,
    ShoppingBagIcon,
    StoreIcon,
    TruckIcon,
    UserRoundIcon,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import { FormatRupiah } from '@/Pustaka/Format';

type PropsKepalaToko = {
    toko: { Nama: string; NamaOutlet: string; Alamat: string | null; UrlLogo?: string | null } | null;
    aktif: boolean;
    pemenuhan: { AmbilSendiri: boolean; Kirim: boolean };
    qrisOnline: boolean;
    minimalPesanan: string;
    pembeli: { Nama: string } | null;
    slug: string;
    akunAktif: boolean;
    jumlahItem: number;
    saatBukaKeranjang: () => void;
    saatBukaMasuk: () => void;
    /** Pemilih outlet (bila toko punya lebih dari satu outlet) dan kartu sorotan, ditaruh di dalam kepala. */
    children?: ReactNode;
    /** Kartu sorotan menjorok keluar kepala di HP, jadi kepala memberi ruang bawah ekstra. */
    adaSorotan?: boolean;
};

/** Dua huruf awal nama toko untuk penanda logo bila tenant belum mengunggah logo. */
export function BuatInisial(nama: string): string {
    const huruf = nama
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((kata) => kata.charAt(0).toUpperCase());

    return huruf.join('') || 'TK';
}

/** Logo tenant di kepala toko. Gagal dimuat atau belum ada → inisial toko di kotak apricot. */
function LogoToko({ nama, url }: { nama: string; url: string | null | undefined }) {
    const [gagal, AturGagal] = useState(false);

    if (url && !gagal) {
        return (
            <img
                src={url}
                alt={`Logo ${nama}`}
                onError={() => AturGagal(true)}
                className="size-12 shrink-0 rounded-2xl bg-permukaan object-cover"
            />
        );
    }

    return (
        <span
            aria-hidden="true"
            className="grid size-12 shrink-0 place-items-center rounded-2xl bg-aksen text-subjudul font-extrabold text-teks-utama"
        >
            {BuatInisial(nama)}
        </span>
    );
}

/**
 * Kepala toko online (D-238): logo tenant, nama toko berukuran terbesar, alamat, status buka, dan cara menerima/bayar
 * dalam satu pandang. Warna tetap merek Payoung (teal gelap + apricot), hanya logo dan nama yang milik tenant.
 */
export default function KepalaToko({
    toko,
    aktif,
    pemenuhan,
    qrisOnline,
    minimalPesanan,
    pembeli,
    slug,
    akunAktif,
    jumlahItem,
    saatBukaKeranjang,
    saatBukaMasuk,
    children,
    adaSorotan = false,
}: PropsKepalaToko) {
    const nama = toko?.Nama ?? 'Toko tidak ditemukan';
    const chip =
        'inline-flex items-center gap-1.5 rounded-full border border-brand-gelap-garis px-3 py-1.5 text-label font-semibold';

    return (
        <header className={`bg-brand-gelap text-permukaan ${adaSorotan ? 'pb-4 lg:pb-12' : 'pb-10'}`}>
            <div className="mx-auto flex max-w-6xl flex-col gap-4 px-4 pt-5 lg:grid lg:grid-cols-[1fr_20rem] lg:gap-x-10">
                <div className="flex items-center justify-between gap-3 lg:col-span-2">
                    <div className="flex min-w-0 items-center gap-3">
                        <LogoToko nama={nama} url={toko?.UrlLogo} />
                        <span className="hidden min-w-0 truncate text-subjudul font-bold sm:block">{nama}</span>
                    </div>
                    <div className="flex items-center gap-2">
                        {pembeli ? (
                            <a
                                href={`/${slug}/akun`}
                                className="flex min-h-11 items-center gap-2 rounded-full bg-brand-gelap-sorot px-4 text-label font-semibold text-permukaan"
                            >
                                <UserRoundIcon aria-hidden="true" className="size-4" />
                                <span className="max-w-32 truncate">{pembeli.Nama}</span>
                            </a>
                        ) : akunAktif ? (
                            <button
                                type="button"
                                onClick={saatBukaMasuk}
                                className="flex min-h-11 items-center gap-2 rounded-full bg-brand-gelap-sorot px-4 text-label font-semibold text-permukaan"
                            >
                                <UserRoundIcon aria-hidden="true" className="size-4" />
                                Masuk
                            </button>
                        ) : null}
                        <button
                            type="button"
                            onClick={saatBukaKeranjang}
                            aria-label={`Keranjang, ${String(jumlahItem)} item`}
                            className="relative flex size-11 items-center justify-center rounded-full bg-permukaan text-brand-gelap"
                        >
                            <ShoppingBagIcon aria-hidden="true" className="size-5" />
                            {jumlahItem > 0 ? (
                                <span className="absolute -top-1 -right-1 grid min-w-5 place-items-center rounded-full border-2 border-brand-gelap bg-aksen px-1 text-keterangan font-bold text-teks-utama tabular-nums">
                                    {jumlahItem}
                                </span>
                            ) : null}
                        </button>
                    </div>
                </div>

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col gap-3">
                        <JudulHalaman skala="toko" className="text-permukaan">
                            {nama}
                        </JudulHalaman>
                        {toko ? (
                            <p className="inline-flex items-start gap-1.5 text-isi text-brand-gelap-teks">
                                <MapPinIcon aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                                <span>
                                    {toko.NamaOutlet}
                                    {toko.Alamat ? ` | ${toko.Alamat}` : ''}
                                </span>
                            </p>
                        ) : null}
                    </div>
                    {toko ? (
                        <>
                            <p
                                className={`inline-flex w-fit items-center gap-2 rounded-full px-4 py-2 text-label font-bold ${
                                    aktif ? 'bg-sukses text-permukaan' : 'bg-aksen text-teks-utama'
                                }`}
                            >
                                <ClockIcon aria-hidden="true" className="size-4" />
                                {aktif ? 'Terima pesanan' : 'Sedang tutup'}
                            </p>
                            <ul className="flex flex-wrap gap-2">
                                {pemenuhan.AmbilSendiri ? (
                                    <li className={chip}>
                                        <StoreIcon aria-hidden="true" className="size-4" />
                                        Ambil sendiri
                                    </li>
                                ) : null}
                                {pemenuhan.Kirim ? (
                                    <li className={chip}>
                                        <TruckIcon aria-hidden="true" className="size-4" />
                                        Dikirim
                                    </li>
                                ) : null}
                                {qrisOnline ? (
                                    <li className={chip}>
                                        <QrCodeIcon aria-hidden="true" className="size-4" />
                                        Bayar QRIS
                                    </li>
                                ) : null}
                                {Number(minimalPesanan) > 0 ? (
                                    <li className={chip}>
                                        <ReceiptTextIcon aria-hidden="true" className="size-4" />
                                        Min. {FormatRupiah(minimalPesanan)}
                                    </li>
                                ) : null}
                            </ul>
                        </>
                    ) : null}
                </div>
                {children}
            </div>
        </header>
    );
}
