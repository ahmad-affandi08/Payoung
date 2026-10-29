import { ChevronRightIcon, MinusIcon, PlusIcon, UtensilsCrossedIcon } from 'lucide-react';

import { PerluOpsi, type ProdukToko } from '@/Komponen/TokoOnline/TipeToko';
import { FormatRupiah } from '@/Pustaka/Format';

type PropsKartuProduk = {
    produk: ProdukToko;
    /** Nama kategori produk (huruf kecil di atas nama), kosong bila produk tanpa kategori. */
    namaKategori?: string | null;
    /** Jumlah semua baris produk ini di keranjang (semua varian/pilihan). */
    jumlahDiKeranjang: number;
    /** Jumlah baris polos (tanpa varian/pilihan); dipakai pengatur jumlah di kartu. */
    jumlahPolos: number;
    saatTambah: () => void;
    saatKurang: () => void;
    saatBukaOpsi: () => void;
};

/**
 * Kartu menu (D-238): foto besar membulat di kiri, kategori, nama, harga tebal, dan kontrol di kanan. Produk polos
 * memakai tombol bulat apricot yang berubah jadi pengatur jumlah gelap, produk berpilihan membuka lembar opsi. Seluruh
 * kartu satu `article` supaya pembaca layar membacanya sebagai satu butir menu.
 */
export default function KartuProduk({
    produk,
    namaKategori,
    jumlahDiKeranjang,
    jumlahPolos,
    saatTambah,
    saatKurang,
    saatBukaOpsi,
}: PropsKartuProduk) {
    const berpilihan = PerluOpsi(produk);
    const adaVarian = (produk.Varian?.length ?? 0) > 0;

    return (
        <article
            className={`flex items-center gap-3.5 rounded-3xl border-2 bg-permukaan p-3 transition-colors ${
                jumlahDiKeranjang > 0 ? 'border-brand' : 'border-transparent'
            }`}
        >
            <div className="relative size-24 shrink-0 overflow-hidden rounded-2xl bg-brand-lembut sm:size-28">
                {produk.UrlGambar ? (
                    <img src={produk.UrlGambar} alt="" loading="lazy" className="size-full object-cover" />
                ) : (
                    <div className="grid size-full place-items-center text-brand">
                        <UtensilsCrossedIcon aria-hidden="true" className="size-9" />
                    </div>
                )}
                {jumlahDiKeranjang > 0 ? (
                    <span className="absolute top-1.5 left-1.5 rounded-full bg-brand-gelap px-2 py-0.5 text-keterangan font-bold whitespace-nowrap text-permukaan tabular-nums">
                        {jumlahDiKeranjang} di keranjang
                    </span>
                ) : null}
            </div>
            <div className="flex min-w-0 flex-1 flex-col gap-2 self-stretch py-0.5">
                <div className="flex flex-col gap-0.5">
                    {namaKategori ? (
                        <p className="truncate text-keterangan font-bold tracking-wide text-teks-sekunder uppercase">
                            {namaKategori}
                        </p>
                    ) : null}
                    <h2 className="text-subjudul font-bold text-teks-utama">{produk.Nama}</h2>
                </div>
                <div className="mt-auto flex flex-wrap items-center justify-between gap-x-2 gap-y-1.5">
                    <p className="text-subjudul font-bold whitespace-nowrap text-teks-utama tabular-nums">
                        {adaVarian ? 'Mulai ' : ''}
                        {FormatRupiah(produk.Harga)}
                    </p>
                    {berpilihan ? (
                        <button
                            type="button"
                            onClick={saatBukaOpsi}
                            className={`inline-flex min-h-11 items-center gap-1 rounded-full px-4 text-label font-bold whitespace-nowrap ${
                                jumlahDiKeranjang > 0
                                    ? 'bg-brand-gelap text-permukaan'
                                    : 'bg-brand-lembut text-brand-gelap'
                            }`}
                        >
                            Pilih opsi
                            <ChevronRightIcon aria-hidden="true" className="size-4" />
                        </button>
                    ) : jumlahPolos > 0 ? (
                        <div className="flex items-center rounded-full bg-brand-gelap p-0.5 text-permukaan">
                            <button
                                type="button"
                                aria-label={`Kurangi ${produk.Nama}`}
                                className="grid size-10 place-items-center rounded-full"
                                onClick={saatKurang}
                            >
                                <MinusIcon aria-hidden="true" className="size-4" />
                            </button>
                            <span className="w-7 text-center text-isi font-bold tabular-nums">{jumlahPolos}</span>
                            <button
                                type="button"
                                aria-label={`Tambah satu ${produk.Nama}`}
                                className="grid size-10 place-items-center rounded-full bg-aksen text-teks-utama"
                                onClick={saatTambah}
                            >
                                <PlusIcon aria-hidden="true" className="size-4" />
                            </button>
                        </div>
                    ) : (
                        <button
                            type="button"
                            aria-label="Tambah"
                            title={`Tambah ${produk.Nama}`}
                            onClick={saatTambah}
                            className="grid size-11 shrink-0 place-items-center rounded-full bg-aksen text-teks-utama"
                        >
                            <PlusIcon aria-hidden="true" className="size-6" />
                        </button>
                    )}
                </div>
            </div>
        </article>
    );
}
