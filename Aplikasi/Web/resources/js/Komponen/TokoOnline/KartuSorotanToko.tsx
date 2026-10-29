import { PlusIcon, SlidersHorizontalIcon, StarIcon, UtensilsCrossedIcon } from 'lucide-react';

import { PerluOpsi, type ProdukToko } from '@/Komponen/TokoOnline/TipeToko';
import { FormatRupiah } from '@/Pustaka/Format';

type PropsKartuSorotanToko = {
    produk: ProdukToko;
    jumlahDiKeranjang: number;
    saatTambah: () => void;
    saatBukaOpsi: () => void;
};

/**
 * Kartu sorotan di kepala toko: satu produk pilihan pemilik (`Produk.Favorit`). Menjorok keluar dari kepala gelap di
 * HP dan duduk di kolom kanan kepala di layar lebar. Hanya tampil bila pemilik menandai favorit; tanpa itu kepala toko
 * tetap utuh dan tidak ada klaim "paling laris" yang tidak berdasar.
 */
export default function KartuSorotanToko({
    produk,
    jumlahDiKeranjang,
    saatTambah,
    saatBukaOpsi,
}: PropsKartuSorotanToko) {
    const berpilihan = PerluOpsi(produk);
    const adaVarian = (produk.Varian?.length ?? 0) > 0;

    return (
        <article
            aria-label={`Favorit toko: ${produk.Nama}`}
            className="relative z-10 -mb-14 flex gap-3 rounded-3xl bg-permukaan p-3 text-teks-utama lg:col-start-2 lg:row-span-2 lg:row-start-2 lg:mb-0 lg:flex-col"
        >
            <div className="relative size-28 shrink-0 overflow-hidden rounded-2xl bg-brand-lembut lg:aspect-16/10 lg:size-auto">
                {produk.UrlGambar ? (
                    <img src={produk.UrlGambar} alt="" className="size-full object-cover" />
                ) : (
                    <div className="grid size-full place-items-center text-brand">
                        <UtensilsCrossedIcon aria-hidden="true" className="size-9" />
                    </div>
                )}
            </div>
            <div className="flex min-w-0 flex-1 flex-col justify-between gap-2 py-0.5 pr-1">
                <div className="flex flex-col items-start gap-1.5">
                    <span className="inline-flex items-center gap-1 rounded-full bg-aksen px-2.5 py-1 text-keterangan font-bold text-teks-utama">
                        <StarIcon aria-hidden="true" className="size-3.5" />
                        Favorit toko
                    </span>
                    <h2 className="text-subjudul font-bold text-teks-utama">{produk.Nama}</h2>
                </div>
                <div className="flex flex-wrap items-center justify-between gap-x-2 gap-y-2">
                    <p className="text-subjudul font-bold whitespace-nowrap tabular-nums">
                        {adaVarian ? 'Mulai ' : ''}
                        {FormatRupiah(produk.Harga)}
                    </p>
                    {berpilihan ? (
                        <button
                            type="button"
                            onClick={saatBukaOpsi}
                            aria-label={`Pilih opsi ${produk.Nama} (favorit toko)`}
                            className="inline-flex min-h-11 items-center gap-1.5 rounded-full bg-aksen px-4 text-label font-bold whitespace-nowrap text-teks-utama"
                        >
                            <SlidersHorizontalIcon aria-hidden="true" className="size-4" />
                            Pilih opsi
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={saatTambah}
                            aria-label={`Tambah ${produk.Nama} (favorit toko)`}
                            className="relative grid size-11 shrink-0 place-items-center rounded-full bg-aksen text-teks-utama"
                        >
                            <PlusIcon aria-hidden="true" className="size-6" />
                            {jumlahDiKeranjang > 0 ? (
                                <span className="absolute -top-1 -right-1 grid min-w-5 place-items-center rounded-full bg-brand-gelap px-1 text-keterangan font-bold text-permukaan tabular-nums">
                                    {jumlahDiKeranjang}
                                </span>
                            ) : null}
                        </button>
                    )}
                </div>
            </div>
        </article>
    );
}
