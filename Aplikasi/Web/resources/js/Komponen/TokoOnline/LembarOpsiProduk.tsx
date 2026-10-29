import { CheckIcon } from 'lucide-react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import type { ProdukToko } from '@/Komponen/TokoOnline/TipeToko';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/Komponen/Ui/sheet';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';

type PropsLembarOpsiProduk = {
    produk: ProdukToko;
    saatTambah: (varian: string | null, pilihan: string[]) => void;
    saatTutup: () => void;
};

/** Pesan galat pilihan yang belum sesuai aturan produk, atau null bila sudah boleh ditambahkan. */
export function PeriksaOpsi(produk: ProdukToko, varian: string, pilihan: string[]): string | null {
    if ((produk.Varian?.length ?? 0) > 0 && varian === '') {
        return `Pilih varian ${produk.Nama}.`;
    }

    for (const kelompok of produk.KelompokPilihan) {
        const jumlah = kelompok.Pilihan.filter((x) => pilihan.includes(x.Uuid)).length;

        if (jumlah < kelompok.MinimalPilih || jumlah > kelompok.MaksimalPilih) {
            return `Pilihan ${kelompok.Nama} belum sesuai: pilih ${kelompok.MinimalPilih}–${kelompok.MaksimalPilih}.`;
        }
    }

    return null;
}

/**
 * Lembar bawah untuk produk berpilihan: varian dan kelompok tambahan dipilih di sini, bukan menumpuk di kartu menu.
 * Validasi aturan produk (minimal/maksimal pilihan) tampil di dalam lembar; server tetap menghitung ulang harga.
 */
export default function LembarOpsiProduk({ produk, saatTambah, saatTutup }: PropsLembarOpsiProduk) {
    const [varian, AturVarian] = useState('');
    const [pilihan, AturPilihan] = useState<string[]>([]);
    const [galat, AturGalat] = useState<string | null>(null);

    function Simpan() {
        const pesan = PeriksaOpsi(produk, varian, pilihan);

        if (pesan !== null) {
            AturGalat(pesan);
            return;
        }

        saatTambah(varian || null, pilihan);
    }

    // Harga yang ditampilkan di tombol hanya petunjuk. Server selalu menghitung ulang harga di keranjang.
    const hargaVarian = (produk.Varian ?? []).find((v) => v.Uuid === varian)?.Harga ?? null;
    const hargaDasar = Number(hargaVarian ?? produk.Harga);
    const hargaTambahan = produk.KelompokPilihan.flatMap((k) => k.Pilihan)
        .filter((x) => pilihan.includes(x.Uuid))
        .reduce((jumlah, x) => jumlah + Number(x.Harga), 0);
    const perkiraan = String(hargaDasar + hargaTambahan);

    return (
        <Sheet open onOpenChange={(buka) => (buka ? undefined : saatTutup())}>
            <SheetContent
                side="bottom"
                aria-describedby={undefined}
                className="mx-auto max-h-[92dvh] w-full gap-0 overflow-hidden rounded-t-3xl p-0 sm:max-w-lg"
            >
                <SheetHeader className="flex-row items-center gap-3 p-5 pr-14 pb-2">
                    {produk.UrlGambar ? (
                        <img
                            src={produk.UrlGambar}
                            alt=""
                            className="size-16 shrink-0 rounded-2xl bg-brand-lembut object-cover"
                        />
                    ) : null}
                    <div className="flex min-w-0 flex-col gap-1">
                        <SheetTitle className="text-judul-bagian-hp font-extrabold">{produk.Nama}</SheetTitle>
                        <SheetDescription className="text-isi">
                            Pilih sesuai selera, lalu tambahkan ke keranjang.
                        </SheetDescription>
                    </div>
                </SheetHeader>
                <div className="flex flex-col gap-5 overflow-y-auto p-5 pt-3">
                    {(produk.Varian?.length ?? 0) > 0 ? (
                        <fieldset className="flex flex-col gap-2">
                            <legend className="mb-1 text-label font-bold tracking-wide text-teks-sekunder uppercase">
                                Varian <span className="normal-case">| wajib pilih 1</span>
                            </legend>
                            {(produk.Varian ?? [])
                                .filter((v) => v.Tersedia)
                                .map((v) => (
                                    <label
                                        key={v.Uuid}
                                        className={`flex min-h-14 cursor-pointer items-center gap-3 rounded-2xl border-2 px-4 text-subjudul font-semibold has-focus-visible:ring-2 has-focus-visible:ring-brand ${
                                            varian === v.Uuid
                                                ? 'border-brand-gelap bg-brand-lembut text-teks-utama'
                                                : 'border-garis text-teks-utama'
                                        }`}
                                    >
                                        <input
                                            type="radio"
                                            name="varian"
                                            className="sr-only"
                                            checked={varian === v.Uuid}
                                            onChange={() => {
                                                AturVarian(v.Uuid);
                                                AturGalat(null);
                                            }}
                                        />
                                        <span
                                            aria-hidden="true"
                                            className={`grid size-6 shrink-0 place-items-center rounded-full border-2 ${
                                                varian === v.Uuid
                                                    ? 'border-brand-gelap bg-brand-gelap text-permukaan'
                                                    : 'border-garis-input'
                                            }`}
                                        >
                                            {varian === v.Uuid ? <CheckIcon className="size-3.5" /> : null}
                                        </span>
                                        {v.Nama}
                                        {v.Harga ? (
                                            <span className="ml-auto font-bold tabular-nums">
                                                {FormatRupiah(v.Harga)}
                                            </span>
                                        ) : null}
                                    </label>
                                ))}
                        </fieldset>
                    ) : null}
                    {produk.KelompokPilihan.map((kelompok) => (
                        <fieldset key={kelompok.Uuid} className="flex flex-col gap-2">
                            <legend className="mb-1 text-label font-bold tracking-wide text-teks-sekunder uppercase">
                                {kelompok.Nama}
                                <span className="font-semibold normal-case">
                                    {' '}
                                    | pilih {kelompok.MinimalPilih}–{kelompok.MaksimalPilih}
                                </span>
                            </legend>
                            {kelompok.Pilihan.map((x) => {
                                const aktif = pilihan.includes(x.Uuid);

                                return (
                                    <label
                                        key={x.Uuid}
                                        className={`flex min-h-14 cursor-pointer items-center gap-3 rounded-2xl border-2 px-4 text-subjudul font-semibold has-focus-visible:ring-2 has-focus-visible:ring-brand ${
                                            aktif
                                                ? 'border-brand-gelap bg-brand-lembut text-teks-utama'
                                                : 'border-garis text-teks-utama'
                                        }`}
                                    >
                                        <input
                                            type="checkbox"
                                            className="sr-only"
                                            checked={aktif}
                                            onChange={(e) => {
                                                AturPilihan((lama) =>
                                                    e.target.checked
                                                        ? [...lama, x.Uuid]
                                                        : lama.filter((u) => u !== x.Uuid),
                                                );
                                                AturGalat(null);
                                            }}
                                        />
                                        <span
                                            aria-hidden="true"
                                            className={`grid size-6 shrink-0 place-items-center rounded-md border-2 ${
                                                aktif
                                                    ? 'border-brand-gelap bg-brand-gelap text-permukaan'
                                                    : 'border-garis-input'
                                            }`}
                                        >
                                            {aktif ? <CheckIcon className="size-3.5" /> : null}
                                        </span>
                                        {x.Nama}
                                        {Number(x.Harga) > 0 ? (
                                            <span className="ml-auto font-bold tabular-nums">
                                                +{FormatRupiah(x.Harga)}
                                            </span>
                                        ) : null}
                                    </label>
                                );
                            })}
                        </fieldset>
                    ))}
                    {galat ? <Pemberitahuan jenis="bahaya">{galat}</Pemberitahuan> : null}
                </div>
                <SheetFooter className="tepi-bawah-aman p-4 pt-2">
                    <Tombol ukuran="besar" bentuk="pil" onClick={Simpan} aria-label="Tambah ke keranjang">
                        <span className="flex w-full items-center justify-between gap-3">
                            <span>Tambah ke keranjang</span>
                            <span className="tabular-nums">{FormatRupiah(perkiraan)}</span>
                        </span>
                    </Tombol>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
