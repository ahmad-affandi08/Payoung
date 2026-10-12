import { MinusIcon, PlusIcon, SlidersHorizontalIcon, UtensilsCrossedIcon } from 'lucide-react';

import Tombol from '@/Komponen/Formulir/Tombol';
import { PerluOpsi, type ProdukToko } from '@/Komponen/TokoOnline/TipeToko';
import { FormatRupiah } from '@/Pustaka/Format';

type PropsKartuProduk = {
    produk: ProdukToko;
    /** Jumlah semua baris produk ini di keranjang (semua varian/pilihan). */
    jumlahDiKeranjang: number;
    /** Jumlah baris polos (tanpa varian/pilihan); dipakai pengatur jumlah di kartu. */
    jumlahPolos: number;
    saatTambah: () => void;
    saatKurang: () => void;
    saatBukaOpsi: () => void;
};

/**
 * Kartu menu modern: gambar persegi di kiri, nama, harga, dan kontrol di kanan. Produk polos memakai tombol Tambah
 * yang berubah jadi pengatur jumlah; produk berpilihan membuka lembar opsi. Seluruh kartu satu `article` supaya
 * pembaca layar membacanya sebagai satu butir menu.
 */
export default function KartuProduk({
    produk,
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
            className={`flex gap-3 rounded-panel border bg-permukaan p-3 transition-colors ${
                jumlahDiKeranjang > 0 ? 'border-brand' : 'border-garis'
            }`}
        >
            <div className="relative size-28 shrink-0 overflow-hidden rounded-panel bg-brand-lembut sm:size-32">
                {produk.UrlGambar ? (
                    <img src={produk.UrlGambar} alt="" loading="lazy" className="size-full object-cover" />
                ) : (
                    <div className="grid size-full place-items-center text-brand">
                        <UtensilsCrossedIcon aria-hidden="true" className="size-9" />
                    </div>
                )}
                {jumlahDiKeranjang > 0 ? (
                    <span className="absolute top-1.5 left-1.5 rounded-full bg-brand px-2 py-0.5 text-keterangan font-semibold whitespace-nowrap text-brand-teks tabular-nums">
                        {jumlahDiKeranjang} di keranjang
                    </span>
                ) : null}
            </div>
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                <div className="flex flex-col gap-0.5">
                    <h2 className="text-isi font-semibold text-teks-utama">{produk.Nama}</h2>
                    <p className="text-isi font-semibold text-brand tabular-nums">
                        {adaVarian ? 'Mulai ' : ''}
                        {FormatRupiah(produk.Harga)}
                    </p>
                </div>
                <div className="mt-auto flex items-center justify-end gap-2">
                    {berpilihan ? (
                        <Tombol varian={jumlahDiKeranjang > 0 ? 'sekunder' : 'utama'} onClick={saatBukaOpsi}>
                            <SlidersHorizontalIcon aria-hidden="true" className="size-4" />
                            Pilih opsi
                        </Tombol>
                    ) : jumlahPolos > 0 ? (
                        <div className="flex items-center rounded-full border border-brand bg-permukaan">
                            <button
                                type="button"
                                aria-label={`Kurangi ${produk.Nama}`}
                                className="grid size-10 place-items-center rounded-full text-brand"
                                onClick={saatKurang}
                            >
                                <MinusIcon aria-hidden="true" className="size-4" />
                            </button>
                            <span className="w-6 text-center text-isi font-semibold tabular-nums">{jumlahPolos}</span>
                            <button
                                type="button"
                                aria-label={`Tambah satu ${produk.Nama}`}
                                className="grid size-10 place-items-center rounded-full text-brand"
                                onClick={saatTambah}
                            >
                                <PlusIcon aria-hidden="true" className="size-4" />
                            </button>
                        </div>
                    ) : (
                        <Tombol onClick={saatTambah}>
                            <PlusIcon aria-hidden="true" className="size-4" />
                            Tambah
                        </Tombol>
                    )}
                </div>
            </div>
        </article>
    );
}
