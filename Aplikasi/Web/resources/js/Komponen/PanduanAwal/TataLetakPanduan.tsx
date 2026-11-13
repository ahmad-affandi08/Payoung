import { Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import IndikatorLangkah from '@/Komponen/PanduanAwal/IndikatorLangkah';
import { buttonVariants } from '@/Komponen/Ui/button';
import { Separator } from '@/Komponen/Ui/separator';
import TataLetakPanduanAwal from '@/TataLetak/TataLetakPanduanAwal';
import {
    AlamatPanduan,
    AmbilAlamatLewati,
    AmbilAlamatTandaiSelesai,
    type LangkahPanduan,
    type ProgresPanduan,
} from '@/Tipe/PanduanAwal';

/**
 * Cara maju ke langkah berikutnya:
 * - `formulir`: tombol simpan di formulir halaman yang memajukan (server mengalihkan ke langkah berikutnya);
 * - `tandai-selesai`: tombol "Lanjutkan" menandai langkah selesai (Produk, Metode pembayaran);
 * - `selesaikan`: langkah terakhir, "Selesaikan panduan".
 */
export type CaraLanjut = 'formulir' | 'tandai-selesai' | 'selesaikan';

type PropsTataLetakPanduan = {
    progres: ProgresPanduan;
    langkah: LangkahPanduan;
    lanjut?: CaraLanjut;
    children: ReactNode;
};

/** Tautan yang tampil seperti Tombol varian sekunder (Button outline shadcn/ui). */
const kelasTautanTombol = buttonVariants({
    variant: 'outline',
    className:
        'h-8 pointer-coarse:h-11 border-garis-input px-4 text-label font-semibold text-teks-utama focus-visible:ring-offset-2',
});

/** Kerangka satu langkah panduan awal: penanda langkah, isi, lalu bilah Kembali / Lewati dulu / Lanjutkan. */
export default function TataLetakPanduan({ progres, langkah, lanjut = 'formulir', children }: PropsTataLetakPanduan) {
    const [memproses, AturMemproses] = useState<'lewati' | 'lanjut' | null>(null);
    const indeks = progres.Langkah.findIndex((item) => item.Kunci === langkah);
    const sekarang = progres.Langkah[indeks];
    const sebelumnya = indeks > 0 ? progres.Langkah[indeks - 1] : undefined;
    const berikutnya = progres.Langkah[indeks + 1];
    const wajib = progres.Wajib === true;
    // D-24: tenant baru hanya boleh melewati langkah Perangkat kasir.
    const bolehLewati = !wajib || langkah === 'Perangkat';
    const BuatOpsiKirim = (jenis: 'lewati' | 'lanjut') => ({
        onStart: () => AturMemproses(jenis),
        onFinish: () => AturMemproses(null),
    });

    const Lewati = () => {
        if (sekarang) {
            router.post(AmbilAlamatLewati(sekarang.Slug), {}, BuatOpsiKirim('lewati'));
        }
    };

    const Lanjutkan = () => {
        if (!sekarang) {
            return;
        }

        if (lanjut === 'tandai-selesai') {
            router.post(AmbilAlamatTandaiSelesai(sekarang.Slug), {}, BuatOpsiKirim('lanjut'));

            return;
        }

        const SelesaikanPanduan = () => router.post(AlamatPanduan.Selesai, {}, BuatOpsiKirim('lanjut'));

        if (sekarang.Status === 'Selesai') {
            SelesaikanPanduan();

            return;
        }

        router.post(
            AmbilAlamatTandaiSelesai(sekarang.Slug),
            {},
            { ...BuatOpsiKirim('lanjut'), onSuccess: SelesaikanPanduan },
        );
    };

    return (
        <TataLetakPanduanAwal judul={sekarang?.Judul ?? 'Panduan awal'} wajib={wajib}>
            <IndikatorLangkah langkah={progres.Langkah} aktif={langkah} />
            <p className="text-isi text-teks-sekunder">
                Panduan awal untuk outlet <span className="font-semibold text-teks-utama">{progres.Outlet.Nama}</span>{' '}
                <span className="font-mono text-label">({progres.Outlet.Kode})</span>.{' '}
                {wajib
                    ? 'Selesaikan langkah ini supaya toko siap berjualan. Perangkat kasir boleh diatur nanti.'
                    : 'Semua langkah bisa dilewati dulu dan dilanjutkan kapan saja dari '}
                {wajib ? null : (
                    <>
                        <Link href={AlamatPanduan.Indeks} className="font-semibold text-brand underline">
                            ringkasan panduan
                        </Link>
                        .
                    </>
                )}
            </p>

            {children}

            <Separator className="bg-garis data-[orientation=horizontal]:w-auto" />
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                <Link href={sebelumnya?.Tautan ?? AlamatPanduan.Indeks} className={kelasTautanTombol}>
                    {sebelumnya ? `Kembali ke ${sebelumnya.Judul}` : 'Kembali ke ringkasan'}
                </Link>
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:flex-wrap">
                    {sekarang?.Status === 'Selesai' ? (
                        lanjut === 'formulir' && berikutnya ? (
                            <Link href={berikutnya.Tautan} className={kelasTautanTombol}>
                                Ke langkah berikutnya
                            </Link>
                        ) : null
                    ) : !bolehLewati ? null : (
                        <Tombol
                            varian="sekunder"
                            onClick={Lewati}
                            memproses={memproses === 'lewati'}
                            disabled={memproses !== null}
                        >
                            {wajib ? 'Nanti saja' : 'Lewati dulu'}
                        </Tombol>
                    )}
                    {lanjut !== 'formulir' ? (
                        <Tombol onClick={Lanjutkan} memproses={memproses === 'lanjut'} disabled={memproses !== null}>
                            {lanjut === 'selesaikan' ? 'Selesaikan panduan' : 'Lanjutkan'}
                        </Tombol>
                    ) : null}
                </div>
            </div>
        </TataLetakPanduanAwal>
    );
}
