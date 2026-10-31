import { Trash2Icon } from 'lucide-react';

import { GalatBidang } from '@/Komponen/Formulir/BagianBidang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import PilihanCari from '@/Komponen/Formulir/PilihanCari';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import { useEntriCepat } from '@/Komponen/Persediaan/EntriCepat';
import PemilihProdukStok from '@/Komponen/Persediaan/PemilihProdukStok';
import { TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import { Button } from '@/Komponen/Ui/button';
import type { HasilCariProdukGrosir } from '@/Tipe/Grosir';

/**
 * Tabel isian barang pesanan grosir (F-12, §9.7): cari produk berstok, pilih satuan jual (dus/karton/pcs), jumlah, dan
 * diskon baris.
 *
 * **Tanpa kolom harga, dan itu bukan kelalaian.** Harga grosir ditentukan server lewat price engine (daftar harga
 * bertingkat per jumlah + tier pelanggan) saat draf disimpan, lalu di-snapshot di barisnya. Kolom harga di sini akan
 * menjadi sumber kebenaran kedua yang bisa berbeda dari yang tersimpan; operator melihat harga & totalnya di halaman
 * draf begitu disimpan, sebelum mengonfirmasi.
 */

export type BarisIsianGrosir = {
    Kunci: string;
    UuidProduk: string;
    UuidProdukSatuan: string;
    NamaProduk: string;
    SimbolSatuan: string;
    Jumlah: string;
    Diskon: string;
    Satuan: { Nilai: string; Label: string }[];
};

const kelasSel = 'px-2 py-2 align-top whitespace-normal';
const kelasKepala = 'px-2 text-label text-teks-sekunder';

let urut = 0;

export function BuatKunciBarisGrosir(): string {
    urut += 1;

    return `baris-${String(urut)}`;
}

/** URL pencarian produk grosir (`GET /kelola/grosir/produk/cari?kata=&gudang=`). */
export function BuatUrlCariProdukGrosir(kata: string, uuidGudang: string | null): string {
    const parameter = new URLSearchParams({ kata, batas: '20' });

    if (uuidGudang) {
        parameter.set('gudang', uuidGudang);
    }

    return `/kelola/grosir/produk/cari?${parameter.toString()}`;
}

export function BuatBarisDariProdukGrosir(produk: HasilCariProdukGrosir): BarisIsianGrosir {
    const satuan = produk.Satuan.map((s) => ({
        Nilai: s.Uuid,
        Label: s.Konversi === '1.0000' ? s.Simbol : `${s.Simbol} (isi ${s.Konversi} ${produk.SimbolSatuan})`,
    }));

    return {
        Kunci: BuatKunciBarisGrosir(),
        UuidProduk: produk.Uuid,
        UuidProdukSatuan: produk.Satuan[0]?.Uuid ?? '',
        NamaProduk: produk.Nama,
        SimbolSatuan: produk.Satuan[0]?.Simbol ?? produk.SimbolSatuan,
        Jumlah: '',
        Diskon: '',
        Satuan: satuan,
    };
}

export default function IsianBarisGrosir({
    baris,
    saatBerubah,
    uuidGudang,
    periksa,
    galatServer,
    maksimal,
}: {
    baris: BarisIsianGrosir[];
    saatBerubah: (baris: BarisIsianGrosir[]) => void;
    uuidGudang: string | null;
    periksa: boolean;
    galatServer: Record<string, string>;
    maksimal: number;
}) {
    const Ubah = (kunci: string, perubahan: Partial<BarisIsianGrosir>) =>
        saatBerubah(baris.map((b) => (b.Kunci === kunci ? { ...b, ...perubahan } : b)));
    const Hapus = (kunci: string) => saatBerubah(baris.filter((b) => b.Kunci !== kunci));
    const penuh = baris.length >= maksimal;
    const entri = useEntriCepat('grosir');

    return (
        <div className="flex flex-col gap-3">
            <PemilihProdukStok
                label="Tambah produk"
                id={entri.idCari}
                alihkanFokusSetelahPilih={entri.AlihkanFokusSetelahPilih}
                uuidGudang={uuidGudang}
                buatUrl={BuatUrlCariProdukGrosir}
                saatPilih={(produk) => {
                    // Entri cepat: barang baru di paling atas (dekat kotak cari) dan jumlahnya langsung difokuskan.
                    const baru = BuatBarisDariProdukGrosir(produk as unknown as HasilCariProdukGrosir);

                    entri.CatatBarisBaru(baru.Kunci);
                    saatBerubah([baru, ...baris]);
                }}
                disabled={penuh}
                {...(penuh
                    ? {}
                    : {
                          keterangan:
                              'Barang yang dipilih muncul paling atas. Ketik jumlahnya lalu tekan Enter untuk lanjut ke barang berikutnya.',
                      })}
            />
            {penuh ? (
                <p className="text-keterangan text-teks-sekunder">
                    Maksimal {maksimal} baris per pesanan. Pecah menjadi beberapa pesanan bila lebih.
                </p>
            ) : null}

            <TabelForm label="Barang pesanan grosir">
                <TableCaption className="sr-only">Barang pesanan grosir</TableCaption>
                <TableHeader>
                    <TableRow>
                        <TableHead className={kelasKepala}>Produk</TableHead>
                        <TableHead className={kelasKepala}>Satuan</TableHead>
                        <TableHead className={kelasKepala}>Jumlah</TableHead>
                        <TableHead className={kelasKepala}>Diskon baris</TableHead>
                        <TableHead className={kelasKepala}>
                            <span className="sr-only">Hapus</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {baris.length === 0 ? (
                        <TableRow>
                            <TableCell colSpan={5} className="px-2 py-4 text-isi text-teks-sekunder">
                                Belum ada barang. Cari produk di atas untuk menambahkannya.
                            </TableCell>
                        </TableRow>
                    ) : (
                        baris.map((b, indeks) => (
                            <TableRow key={b.Kunci}>
                                <TableCell className={kelasSel}>
                                    <span className="font-semibold break-words">{b.NamaProduk}</span>
                                    {galatServer[`Baris.${String(indeks)}.UuidProduk`] ? (
                                        <GalatBidang>{galatServer[`Baris.${String(indeks)}.UuidProduk`]}</GalatBidang>
                                    ) : null}
                                </TableCell>
                                <TableCell className={kelasSel}>
                                    <PilihanCari
                                        label="Satuan"
                                        nilai={b.UuidProdukSatuan}
                                        opsi={b.Satuan}
                                        saatBerubah={(nilai) => Ubah(b.Kunci, { UuidProdukSatuan: nilai })}
                                    />
                                </TableCell>
                                <TableCell className={kelasSel}>
                                    <BidangJumlah
                                        label={`Jumlah ${b.NamaProduk}`}
                                        labelTersembunyi
                                        id={entri.IdJumlah(b.Kunci)}
                                        saatEnter={entri.KembaliKeCari}
                                        nilai={b.Jumlah}
                                        saatBerubah={(nilai) => Ubah(b.Kunci, { Jumlah: nilai })}
                                        galat={
                                            galatServer[`Baris.${String(indeks)}.Jumlah`] ??
                                            (periksa && Number(b.Jumlah) <= 0
                                                ? 'Jumlah harus lebih dari nol.'
                                                : undefined)
                                        }
                                        akhiran={b.SimbolSatuan}
                                    />
                                </TableCell>
                                <TableCell className={kelasSel}>
                                    <BidangUang
                                        label={`Diskon ${b.NamaProduk}`}
                                        labelTersembunyi
                                        nilai={b.Diskon}
                                        saatBerubah={(nilai) => Ubah(b.Kunci, { Diskon: nilai })}
                                        galat={galatServer[`Baris.${String(indeks)}.Diskon`]}
                                    />
                                </TableCell>
                                <TableCell className={kelasSel}>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => Hapus(b.Kunci)}
                                        aria-label={`Hapus ${b.NamaProduk}`}
                                    >
                                        <Trash2Icon aria-hidden className="size-4" />
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))
                    )}
                </TableBody>
            </TabelForm>
        </div>
    );
}
