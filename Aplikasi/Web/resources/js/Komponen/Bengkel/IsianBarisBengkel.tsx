import { Trash2Icon } from 'lucide-react';

import PemilihCariBengkel from '@/Komponen/Bengkel/PemilihCariBengkel';
import { GalatBidang } from '@/Komponen/Formulir/BagianBidang';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import PilihanCari from '@/Komponen/Formulir/PilihanCari';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import { Button } from '@/Komponen/Ui/button';
import { TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import type { HasilCariProdukBengkel, IsianBarisPerintahKerja, JenisBarisBengkel, OpsiUuid } from '@/Tipe/Bengkel';

/** `TeksNomorSeri` = isi kotak nomor seri apa adanya (satu per baris), dipecah saat dikirim. */
export type BarisIsianBengkel = IsianBarisPerintahKerja & { Kunci: string; TeksNomorSeri: string };

const kelasSel = 'px-2 py-2 align-top whitespace-normal';
const kelasKepala = 'px-2 text-label text-teks-sekunder';

let urut = 0;

export function BuatKunciBarisBengkel(): string {
    urut += 1;

    return `bengkel-${String(urut)}`;
}

/** URL pencarian produk bengkel (`GET /kelola/bengkel/produk/cari?kata=&jenis=&outlet=`). */
export function BuatUrlCariProdukBengkel(kata: string, jenis: JenisBarisBengkel, uuidOutlet: string): string {
    const parameter = new URLSearchParams({ kata, jenis });

    if (uuidOutlet !== '') {
        parameter.set('outlet', uuidOutlet);
    }

    return `/kelola/bengkel/produk/cari?${parameter.toString()}`;
}

/** Nomor seri dari kotak isian: dipisah baris baru atau koma, spasi tepi dibuang, yang kosong diabaikan. */
export function PecahNomorSeri(teks: string): string[] {
    return teks
        .split(/[\n,]/)
        .map((n) => n.trim())
        .filter((n) => n !== '');
}

export function BuatBarisDariProdukBengkel(produk: HasilCariProdukBengkel): BarisIsianBengkel {
    return {
        Kunci: BuatKunciBarisBengkel(),
        Jenis: produk.Jenis,
        UuidProduk: produk.Uuid,
        UuidProdukSatuan: produk.Satuan[0]?.Uuid ?? null,
        NamaProduk: produk.Nama,
        SimbolSatuan: produk.Satuan[0]?.Simbol ?? '',
        Jumlah: '1',
        Diskon: '',
        UuidKaryawan: null,
        Catatan: null,
        NomorSeri: [],
        TeksNomorSeri: '',
        Pelacakan: produk.Pelacakan,
        StokTersedia: produk.StokTersedia,
        Satuan: produk.Satuan,
    };
}

/**
 * Tabel isian jasa & sparepart perintah kerja (§9.10). **Tanpa kolom harga**: harga ditentukan server (price engine +
 * tier pelanggan) saat disimpan, lalu tampil di halaman detail sebelum persetujuan diminta. Mekanik hanya untuk baris
 * jasa (komisi saat ditagih); stok tersedia sparepart hanya informasi (perintah kerja tidak memotong stok). Sparepart
 * ber-batch dialokasikan server (kedaluwarsa terdekat) saat ditagih; sparepart bernomor seri boleh dicatat nomornya per
 * unit di sini (diperiksa tersedia saat disimpan) atau diisi kasir saat menagih.
 */
export default function IsianBarisBengkel({
    baris,
    saatBerubah,
    uuidOutlet,
    opsiMekanik,
    periksa,
    galatServer,
    maksimal,
}: {
    baris: BarisIsianBengkel[];
    saatBerubah: (baris: BarisIsianBengkel[]) => void;
    uuidOutlet: string;
    opsiMekanik: OpsiUuid[];
    periksa: boolean;
    galatServer: Record<string, string>;
    maksimal: number;
}) {
    const Ubah = (kunci: string, perubahan: Partial<BarisIsianBengkel>) =>
        saatBerubah(baris.map((b) => (b.Kunci === kunci ? { ...b, ...perubahan } : b)));
    const Hapus = (kunci: string) => saatBerubah(baris.filter((b) => b.Kunci !== kunci));
    const penuh = baris.length >= maksimal;
    const opsiMekanikPilihan = opsiMekanik.map((m) => ({ Nilai: m.Uuid, Label: m.Nama }));
    const Pemilih = (jenis: JenisBarisBengkel, label: string) => (
        <PemilihCariBengkel<HasilCariProdukBengkel>
            sumber={jenis}
            label={label}
            placeholder={jenis === 'Jasa' ? 'Cari jasa servis' : 'Cari sparepart'}
            buatUrl={(kata) => BuatUrlCariProdukBengkel(kata, jenis, uuidOutlet)}
            ambilId={(p) => p.Uuid}
            ambilJudul={(p) => p.Nama}
            ambilKeterangan={(p) =>
                [
                    p.Sku,
                    p.StokTersedia === null ? null : `Stok ${FormatJumlahStok(p.StokTersedia, p.Satuan[0]?.Simbol)}`,
                ]
                    .filter(Boolean)
                    .join(' | ') || null
            }
            saatPilih={(p) => saatBerubah([...baris, BuatBarisDariProdukBengkel(p)])}
            pesanKosong={
                jenis === 'Jasa'
                    ? 'Belum ada produk berjenis Jasa. Tambahkan dulu di Produk.'
                    : 'Belum ada produk berstok. Tambahkan dulu di Produk.'
            }
            disabled={penuh}
            pindaiKamera={jenis !== 'Jasa'}
        />
    );

    return (
        <div className="flex flex-col gap-3">
            <div className="grid gap-3 sm:grid-cols-2">
                {Pemilih('Jasa', 'Tambah jasa')}
                {Pemilih('Sparepart', 'Tambah sparepart')}
            </div>
            {penuh ? (
                <p className="text-keterangan text-teks-sekunder">
                    Maksimal {maksimal} baris per perintah kerja. Pecah menjadi beberapa perintah kerja bila lebih.
                </p>
            ) : null}

            <TabelForm label="Jasa dan sparepart perintah kerja">
                <TableCaption className="sr-only">Jasa dan sparepart perintah kerja</TableCaption>
                <TableHeader>
                    <TableRow>
                        <TableHead className={kelasKepala}>Pekerjaan / sparepart</TableHead>
                        <TableHead className={kelasKepala}>Satuan</TableHead>
                        <TableHead className={kelasKepala}>Jumlah</TableHead>
                        <TableHead className={kelasKepala}>Diskon baris</TableHead>
                        <TableHead className={kelasKepala}>Mekanik</TableHead>
                        <TableHead className={kelasKepala}>
                            <span className="sr-only">Hapus</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {baris.length === 0 ? (
                        <TableRow>
                            <TableCell colSpan={6} className="px-2 py-4 text-isi text-teks-sekunder">
                                Belum ada jasa atau sparepart. Estimasi boleh diisi setelah diagnosis.
                            </TableCell>
                        </TableRow>
                    ) : (
                        baris.map((b, indeks) => {
                            const AmbilGalat = (bidang: string) => galatServer[`Baris.${String(indeks)}.${bidang}`];

                            return (
                                <TableRow key={b.Kunci}>
                                    <TableCell className={kelasSel}>
                                        <span className="flex flex-col gap-0.5">
                                            <span className="text-keterangan font-semibold text-teks-sekunder">
                                                {b.Jenis === 'Jasa' ? 'Jasa' : 'Sparepart'}
                                            </span>
                                            <span className="font-semibold break-words">{b.NamaProduk}</span>
                                            {b.StokTersedia !== null ? (
                                                <span className="text-keterangan text-teks-sekunder">
                                                    Stok tersedia {FormatJumlahStok(b.StokTersedia, b.SimbolSatuan)}
                                                </span>
                                            ) : null}
                                            {AmbilGalat('UuidProduk') ? (
                                                <GalatBidang>{AmbilGalat('UuidProduk')}</GalatBidang>
                                            ) : null}
                                            {b.Pelacakan === 'Batch' ? (
                                                <span className="text-keterangan text-teks-sekunder">
                                                    Batch dipilih otomatis (kedaluwarsa terdekat) saat ditagih.
                                                </span>
                                            ) : null}
                                            {b.Pelacakan === 'Seri' ? (
                                                <BidangTeksPanjang
                                                    label={`Nomor seri ${b.NamaProduk}`}
                                                    nilai={b.TeksNomorSeri}
                                                    saatBerubah={(nilai) => Ubah(b.Kunci, { TeksNomorSeri: nilai })}
                                                    keterangan="Satu nomor per baris, sebanyak jumlah unit. Boleh dikosongkan dan diisi kasir saat menagih."
                                                    galat={AmbilGalat('NomorSeri')}
                                                />
                                            ) : null}
                                        </span>
                                    </TableCell>
                                    <TableCell className={kelasSel}>
                                        <PilihanCari
                                            label="Satuan"
                                            aria-label={`Satuan ${b.NamaProduk}`}
                                            nilai={b.UuidProdukSatuan ?? ''}
                                            opsi={b.Satuan.map((s) => ({ Nilai: s.Uuid, Label: s.Simbol }))}
                                            saatBerubah={(nilai) =>
                                                Ubah(b.Kunci, {
                                                    UuidProdukSatuan: nilai,
                                                    SimbolSatuan: b.Satuan.find((s) => s.Uuid === nilai)?.Simbol ?? '',
                                                })
                                            }
                                            galat={AmbilGalat('UuidProdukSatuan')}
                                        />
                                    </TableCell>
                                    <TableCell className={kelasSel}>
                                        <BidangJumlah
                                            label={`Jumlah ${b.NamaProduk}`}
                                            labelTersembunyi
                                            nilai={b.Jumlah}
                                            saatBerubah={(nilai) => Ubah(b.Kunci, { Jumlah: nilai })}
                                            galat={
                                                AmbilGalat('Jumlah') ??
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
                                            galat={AmbilGalat('Diskon')}
                                        />
                                    </TableCell>
                                    <TableCell className={kelasSel}>
                                        {b.Jenis === 'Jasa' ? (
                                            <PilihanCari
                                                label={`Mekanik ${b.NamaProduk}`}
                                                aria-label={`Mekanik ${b.NamaProduk}`}
                                                nilai={b.UuidKaryawan ?? ''}
                                                opsi={opsiMekanikPilihan}
                                                kosong="Belum ditugaskan"
                                                saatBerubah={(nilai) =>
                                                    Ubah(b.Kunci, { UuidKaryawan: nilai === '' ? null : nilai })
                                                }
                                                galat={AmbilGalat('UuidKaryawan')}
                                            />
                                        ) : (
                                            <span className="text-keterangan text-teks-sekunder">-</span>
                                        )}
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
                            );
                        })
                    )}
                </TableBody>
            </TabelForm>
        </div>
    );
}
