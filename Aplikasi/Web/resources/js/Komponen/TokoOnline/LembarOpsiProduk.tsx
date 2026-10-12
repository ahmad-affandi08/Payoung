import { CheckIcon } from 'lucide-react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
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

    return (
        <Sheet open onOpenChange={(buka) => (buka ? undefined : saatTutup())}>
            <SheetContent
                side="bottom"
                aria-describedby={undefined}
                className="mx-auto max-h-[88dvh] w-full gap-0 overflow-hidden rounded-t-panel p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b border-garis p-4 pr-12">
                    <SheetTitle className="text-subjudul">{produk.Nama}</SheetTitle>
                    <SheetDescription className="text-keterangan">
                        Pilih sesuai selera, lalu tambahkan ke keranjang.
                    </SheetDescription>
                </SheetHeader>
                <div className="flex flex-col gap-5 overflow-y-auto p-4">
                    {(produk.Varian?.length ?? 0) > 0 ? (
                        <BidangPilihan
                            label="Varian"
                            nilai={varian}
                            opsi={(produk.Varian ?? [])
                                .filter((v) => v.Tersedia)
                                .map((v) => ({
                                    Nilai: v.Uuid,
                                    Label: `${v.Nama}${v.Harga ? ` | ${FormatRupiah(v.Harga)}` : ''}`,
                                }))}
                            kosong="Pilih varian"
                            saatBerubah={(nilai) => {
                                AturVarian(nilai);
                                AturGalat(null);
                            }}
                        />
                    ) : null}
                    {produk.KelompokPilihan.map((kelompok) => (
                        <fieldset key={kelompok.Uuid} className="flex flex-col gap-2">
                            <legend className="text-label font-semibold text-teks-utama">
                                {kelompok.Nama}
                                <span className="font-normal text-teks-sekunder">
                                    {' '}
                                    (pilih {kelompok.MinimalPilih}–{kelompok.MaksimalPilih})
                                </span>
                            </legend>
                            <div className="flex flex-wrap gap-2">
                                {kelompok.Pilihan.map((x) => {
                                    const aktif = pilihan.includes(x.Uuid);

                                    return (
                                        <label
                                            key={x.Uuid}
                                            className={`flex min-h-10 cursor-pointer items-center gap-2 rounded-full border px-3 text-label has-focus-visible:ring-2 has-focus-visible:ring-brand ${
                                                aktif
                                                    ? 'border-brand bg-brand-lembut font-semibold text-teks-utama'
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
                                            {aktif ? (
                                                <CheckIcon aria-hidden="true" className="size-4 text-brand" />
                                            ) : null}
                                            {x.Nama}
                                            {Number(x.Harga) > 0 ? ` (+${FormatRupiah(x.Harga)})` : ''}
                                        </label>
                                    );
                                })}
                            </div>
                        </fieldset>
                    ))}
                    {galat ? <Pemberitahuan jenis="bahaya">{galat}</Pemberitahuan> : null}
                </div>
                <SheetFooter className="tepi-bawah-aman border-t border-garis p-4">
                    <Tombol ukuran="besar" onClick={Simpan}>
                        Tambah ke keranjang
                    </Tombol>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
