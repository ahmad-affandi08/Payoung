import { PlusIcon, Trash2Icon } from 'lucide-react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import PilihanCari from '@/Komponen/Formulir/PilihanCari';
import PemilihJasaInvoice from '@/Komponen/Invoice/PemilihJasaInvoice';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import { useEntriCepat } from '@/Komponen/Persediaan/EntriCepat';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import { Button } from '@/Komponen/Ui/button';
import { TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { FormatRupiah } from '@/Pustaka/Format';
import { HitungSubtotalBaris } from '@/Pustaka/HitungInvoice';
import type { BarisIsianInvoice, HasilCariJasaInvoice, OpsiKelompokPajakInvoice } from '@/Tipe/Invoice';

/**
 * Tabel isian baris invoice (F-21): jasa dari katalog atau baris bebas yang diketik, jumlah, satuan, harga, diskon, dan
 * kelompok pajak. Subtotal baris hanya pratinjau (jumlah × harga − diskon); pajak, PPh, dan total resmi dihitung
 * server setelah draf disimpan, karena tarifnya bertanggal berlaku (aturan emas #12).
 */

const kelasSel = 'px-2 py-2 align-top whitespace-normal';
const kelasKepala = 'px-2 text-label text-teks-sekunder';
const opsiJenis = [
    { Nilai: 'Jasa', Label: 'Jasa' },
    { Nilai: 'Barang', Label: 'Barang' },
];

let urut = 0;

export function BuatKunciBarisInvoice(): string {
    urut += 1;

    return `baris-invoice-${String(urut)}`;
}

export function BuatBarisKosongInvoice(): BarisIsianInvoice {
    return {
        Kunci: BuatKunciBarisInvoice(),
        UuidProduk: null,
        Nama: '',
        Keterangan: '',
        JenisBaris: 'Jasa',
        SimbolSatuan: '',
        Jumlah: '1',
        Harga: '',
        Diskon: '',
        UuidKelompokPajak: null,
        HargaTermasukPajak: null,
    };
}

export function BuatBarisDariJasa(produk: HasilCariJasaInvoice): BarisIsianInvoice {
    return {
        ...BuatBarisKosongInvoice(),
        UuidProduk: produk.Uuid,
        Nama: produk.Nama,
        // Produk tanpa stok yang bukan jasa (mis. biaya admin, voucher) tetap bisa ditagih sebagai barang.
        JenisBaris: produk.Jenis === 'Jasa' ? 'Jasa' : 'Barang',
        SimbolSatuan: produk.SimbolSatuan ?? '',
        Harga: produk.Harga === null ? '' : produk.Harga.replace(/\.00$/, ''),
        UuidKelompokPajak: produk.UuidKelompokPajak,
        HargaTermasukPajak: produk.HargaTermasukPajak,
    };
}

export default function IsianBarisInvoice({
    baris,
    saatBerubah,
    opsiKelompokPajak,
    periksa,
    galatServer,
    maksimal,
    sembunyikanDiskon = false,
}: {
    baris: BarisIsianInvoice[];
    saatBerubah: (baris: BarisIsianInvoice[]) => void;
    opsiKelompokPajak: OpsiKelompokPajakInvoice[];
    periksa: boolean;
    galatServer: Record<string, string>;
    maksimal: number;
    /** Invoice uang muka tidak memakai diskon. */
    sembunyikanDiskon?: boolean;
}) {
    const Ubah = (kunci: string, perubahan: Partial<BarisIsianInvoice>) =>
        saatBerubah(baris.map((b) => (b.Kunci === kunci ? { ...b, ...perubahan } : b)));
    const Hapus = (kunci: string) => saatBerubah(baris.filter((b) => b.Kunci !== kunci));
    const penuh = baris.length >= maksimal;
    const opsiPajak = opsiKelompokPajak.map((k) => ({ Nilai: k.Uuid, Label: k.Nama }));
    const jumlahKolom = sembunyikanDiskon ? 8 : 9;
    const entri = useEntriCepat('invoice');
    // Entri cepat: baris baru di paling atas (dekat kotak cari). Baris dari katalog langsung memfokuskan jumlahnya,
    // baris bebas memfokuskan uraiannya.
    const TambahBarisBebas = () => {
        const baru = BuatBarisKosongInvoice();

        saatBerubah([baru, ...baris]);
        requestAnimationFrame(() =>
            document.querySelector<HTMLInputElement>(`[data-baris="${baru.Kunci}"] input`)?.focus(),
        );
    };

    return (
        <div className="flex flex-col gap-3">
            <div className="grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                <PemilihJasaInvoice
                    id={entri.idCari}
                    alihkanFokusSetelahPilih={entri.AlihkanFokusSetelahPilih}
                    saatPilih={(produk) => {
                        const baru = BuatBarisDariJasa(produk);

                        entri.CatatBarisBaru(baru.Kunci);
                        saatBerubah([baru, ...baris]);
                    }}
                    disabled={penuh}
                />
                <Button
                    type="button"
                    variant="outline"
                    className="h-8 pointer-coarse:h-11"
                    disabled={penuh}
                    onClick={TambahBarisBebas}
                >
                    <PlusIcon aria-hidden className="size-4" />
                    Tambah baris bebas
                </Button>
            </div>
            {penuh ? (
                <p className="text-keterangan text-teks-sekunder">
                    Maksimal {maksimal} baris per invoice. Pecah menjadi beberapa invoice bila lebih.
                </p>
            ) : null}

            <TabelForm label="Baris invoice" lebar="dokumen">
                <TableCaption className="sr-only">Baris invoice</TableCaption>
                <TableHeader>
                    <TableRow>
                        <TableHead className={kelasKepala}>Uraian</TableHead>
                        <TableHead className={kelasKepala}>Jenis</TableHead>
                        <TableHead className={kelasKepala}>Jumlah</TableHead>
                        <TableHead className={kelasKepala}>Satuan</TableHead>
                        <TableHead className={kelasKepala}>Harga</TableHead>
                        {sembunyikanDiskon ? null : <TableHead className={kelasKepala}>Diskon</TableHead>}
                        <TableHead className={kelasKepala}>Pajak</TableHead>
                        <TableHead className={`${kelasKepala} text-right`}>Subtotal</TableHead>
                        <TableHead className={kelasKepala}>
                            <span className="sr-only">Hapus</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {baris.length === 0 ? (
                        <TableRow>
                            <TableCell colSpan={jumlahKolom} className="px-2 py-4 text-isi text-teks-sekunder">
                                Belum ada baris. Pilih jasa dari daftar, atau tambah baris bebas lalu ketik uraiannya.
                            </TableCell>
                        </TableRow>
                    ) : (
                        baris.map((b, indeks) => {
                            const awalan = `Baris.${String(indeks)}`;
                            const subtotal = HitungSubtotalBaris(b);

                            return (
                                <TableRow key={b.Kunci} data-baris={b.Kunci}>
                                    <TableCell className={`${kelasSel} min-w-56`}>
                                        <div className="flex flex-col gap-2">
                                            <BidangTeks
                                                label={`Uraian baris ${String(indeks + 1)}`}
                                                labelTersembunyi
                                                nilai={b.Nama}
                                                saatBerubah={(nilai) => Ubah(b.Kunci, { Nama: nilai })}
                                                maxLength={200}
                                                galat={
                                                    galatServer[`${awalan}.Nama`] ??
                                                    (periksa && b.Nama.trim() === ''
                                                        ? 'Uraian wajib diisi.'
                                                        : undefined)
                                                }
                                            />
                                            <BidangTeks
                                                label={`Keterangan baris ${String(indeks + 1)}`}
                                                labelTersembunyi
                                                nilai={b.Keterangan}
                                                saatBerubah={(nilai) => Ubah(b.Kunci, { Keterangan: nilai })}
                                                maxLength={255}
                                            />
                                            {galatServer[`${awalan}.UuidProduk`] ? (
                                                <p className="text-keterangan text-bahaya">
                                                    {galatServer[`${awalan}.UuidProduk`]}
                                                </p>
                                            ) : null}
                                        </div>
                                    </TableCell>
                                    <TableCell className={`${kelasSel} min-w-28`}>
                                        <PilihanCari
                                            label="Jenis baris"
                                            aria-label={`Jenis baris ${String(indeks + 1)}`}
                                            nilai={b.JenisBaris}
                                            opsi={opsiJenis}
                                            saatBerubah={(nilai) =>
                                                Ubah(b.Kunci, { JenisBaris: nilai === 'Barang' ? 'Barang' : 'Jasa' })
                                            }
                                        />
                                    </TableCell>
                                    <TableCell className={`${kelasSel} min-w-24`}>
                                        <BidangJumlah
                                            label={`Jumlah baris ${String(indeks + 1)}`}
                                            labelTersembunyi
                                            id={entri.IdJumlah(b.Kunci)}
                                            saatEnter={entri.KembaliKeCari}
                                            nilai={b.Jumlah}
                                            saatBerubah={(nilai) => Ubah(b.Kunci, { Jumlah: nilai })}
                                            galat={
                                                galatServer[`${awalan}.Jumlah`] ??
                                                (periksa && Number(b.Jumlah) <= 0
                                                    ? 'Jumlah harus lebih dari nol.'
                                                    : undefined)
                                            }
                                        />
                                    </TableCell>
                                    <TableCell className={`${kelasSel} min-w-20`}>
                                        <BidangTeks
                                            label={`Satuan baris ${String(indeks + 1)}`}
                                            labelTersembunyi
                                            nilai={b.SimbolSatuan}
                                            saatBerubah={(nilai) => Ubah(b.Kunci, { SimbolSatuan: nilai })}
                                            maxLength={20}
                                        />
                                    </TableCell>
                                    <TableCell className={`${kelasSel} min-w-36`}>
                                        <BidangUang
                                            label={`Harga baris ${String(indeks + 1)}`}
                                            labelTersembunyi
                                            nilai={b.Harga}
                                            saatBerubah={(nilai) => Ubah(b.Kunci, { Harga: nilai })}
                                            galat={
                                                galatServer[`${awalan}.Harga`] ??
                                                (periksa && b.Harga === '' ? 'Harga wajib diisi.' : undefined)
                                            }
                                        />
                                    </TableCell>
                                    {sembunyikanDiskon ? null : (
                                        <TableCell className={`${kelasSel} min-w-32`}>
                                            <BidangUang
                                                label={`Diskon baris ${String(indeks + 1)}`}
                                                labelTersembunyi
                                                nilai={b.Diskon}
                                                saatBerubah={(nilai) => Ubah(b.Kunci, { Diskon: nilai })}
                                                galat={galatServer[`${awalan}.Diskon`]}
                                            />
                                        </TableCell>
                                    )}
                                    <TableCell className={`${kelasSel} min-w-36`}>
                                        <PilihanCari
                                            label="Pajak"
                                            aria-label={`Pajak baris ${String(indeks + 1)}`}
                                            nilai={b.UuidKelompokPajak ?? ''}
                                            opsi={opsiPajak}
                                            kosong="Tanpa pajak"
                                            saatBerubah={(nilai) =>
                                                Ubah(b.Kunci, { UuidKelompokPajak: nilai === '' ? null : nilai })
                                            }
                                        />
                                    </TableCell>
                                    <TableCell className={`${kelasSel} text-right tabular-nums`}>
                                        {subtotal === null ? '—' : FormatRupiah(subtotal)}
                                    </TableCell>
                                    <TableCell className={kelasSel}>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => Hapus(b.Kunci)}
                                            aria-label={`Hapus baris ${String(indeks + 1)}`}
                                        >
                                            <Trash2Icon aria-hidden className="size-4" />
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            );
                        })
                    )}
                </TableBody>
            </TabelForm>
        </div>
    );
}
