import TabelForm from '@/Komponen/TabelData/TabelForm';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import { Button } from '@/Komponen/Ui/button';
import { TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { FormatRupiah } from '@/Pustaka/Format';
import { BandingkanDesimal, CekDesimalBulat, CekDesimalPositif, FormatMasukanJumlah } from '@/Pustaka/MasukanJumlah';
import type { BarisHarga } from '@/Tipe/Katalog';

import BidangJumlah from './BidangJumlah';

type GalatBaris = { JumlahMinimum?: string; Harga?: string };

type OpsiPeriksa = {
    /** Harga dasar wajib punya baris JumlahMinimum = 1 (tidak berlaku untuk daftar harga). */
    wajibDasar: boolean;
    /** Satuan boleh desimal: JumlahMinimum pecahan diizinkan. */
    bolehDesimal: boolean;
};

/**
 * Validasi baris harga di peramban (cermin DesainF03 C.3; server tetap penentu). Murni string, tanpa number.
 * Hasil: galat per indeks baris dan galat umum tabel.
 */
export function PeriksaBarisHarga(
    baris: BarisHarga[],
    { wajibDasar, bolehDesimal }: OpsiPeriksa,
): { perBaris: Record<number, GalatBaris>; umum: string | null } {
    const perBaris: Record<number, GalatBaris> = {};

    baris.forEach((item, indeks) => {
        const galat: GalatBaris = {};

        if (!CekDesimalPositif(item.JumlahMinimum)) {
            galat.JumlahMinimum = 'Isi jumlah minimum lebih dari 0.';
        } else if (!bolehDesimal && !CekDesimalBulat(item.JumlahMinimum)) {
            galat.JumlahMinimum = 'Satuan ini tidak boleh pecahan. Isi bilangan bulat.';
        } else if (
            baris.some(
                (lain, indeksLain) =>
                    indeksLain < indeks &&
                    CekDesimalPositif(lain.JumlahMinimum) &&
                    BandingkanDesimal(lain.JumlahMinimum, item.JumlahMinimum) === 0,
            )
        ) {
            galat.JumlahMinimum = 'Jumlah minimum ini sudah ada di baris lain.';
        }

        if (item.Harga === '') {
            galat.Harga = 'Isi harga. Tulis 0 bila gratis.';
        }

        if (galat.JumlahMinimum !== undefined || galat.Harga !== undefined) {
            perBaris[indeks] = galat;
        }
    });

    const adaDasar = baris.some(
        (item) => CekDesimalPositif(item.JumlahMinimum) && BandingkanDesimal(item.JumlahMinimum, '1') === 0,
    );
    const umum = wajibDasar && baris.length > 0 && !adaDasar ? 'Harga jual untuk jumlah minimum 1 wajib diisi.' : null;

    return { perBaris, umum };
}

/** Urutkan baris naik menurut JumlahMinimum (perbandingan desimal string); baris tidak valid di akhir. */
export function UrutkanBarisHarga<T extends BarisHarga>(baris: T[]): T[] {
    return [...baris].sort((a, b) => {
        const validA = CekDesimalPositif(a.JumlahMinimum);
        const validB = CekDesimalPositif(b.JumlahMinimum);

        if (validA && validB) {
            return BandingkanDesimal(a.JumlahMinimum, b.JumlahMinimum);
        }

        return validA ? -1 : validB ? 1 : 0;
    });
}

/** Ringkasan satu baris: "1+ pcs: Rp 5.000" / "12+ pcs: Rp 4.500". */
export function RingkasBarisHarga(baris: BarisHarga, simbol: string): string {
    return `${FormatMasukanJumlah(baris.JumlahMinimum)}+ ${simbol}: ${FormatRupiah(baris.Harga)}`;
}

type PropsTabelHargaBertingkat = {
    /** Judul tabel untuk pembaca layar dan caption, misal "Harga per pcs". */
    judul: string;
    baris: BarisHarga[];
    saatBerubah: (baris: BarisHarga[]) => void;
    simbolSatuan: string;
    bolehDesimal: boolean;
    wajibDasar: boolean;
    /** Galat server dengan kunci relatif, misal {"0.Harga": "…", "1.JumlahMinimum": "…"}. */
    galatServer?: Record<string, string | undefined>;
    /** Tampilkan galat validasi lokal (setelah pengguna mencoba menyimpan). */
    tampilkanGalat?: boolean;
    disabled?: boolean;
};

/**
 * Harga dasar + harga bertingkat per satuan (price engine lapis 5): baris pertama JumlahMinimum 1,
 * tingkat berikutnya misal 12+ = Rp 4.500. Uang lewat BidangUang, jumlah lewat BidangJumlah: semua string.
 */
export default function TabelHargaBertingkat({
    judul,
    baris,
    saatBerubah,
    simbolSatuan,
    bolehDesimal,
    wajibDasar,
    galatServer = {},
    tampilkanGalat = false,
    disabled = false,
}: PropsTabelHargaBertingkat) {
    const hasilPeriksa = PeriksaBarisHarga(baris, { wajibDasar, bolehDesimal });
    const perBaris = tampilkanGalat ? hasilPeriksa.perBaris : {};
    const umum = tampilkanGalat ? hasilPeriksa.umum : null;
    // Harga dasar langsung tampil sebagai isian kosong (tanpa tombol "Isi harga jual"): kosong = satuan tidak dijual di
    // kasir, jadi baris baru benar-benar dibuat begitu harga diketik dan dibuang lagi bila harga dikosongkan.
    const barisKosong = wajibDasar && !disabled && baris.length === 0;
    const tampil: BarisHarga[] = barisKosong ? [{ JumlahMinimum: '1', Harga: '' }] : baris;
    const Ubah = (indeks: number, perubahan: Partial<BarisHarga>) => {
        const berubah = tampil.map((item, i) => (i === indeks ? { ...item, ...perubahan } : item));
        const hanyaDasarKosong =
            wajibDasar && berubah.length === 1 && berubah[0]?.Harga === '' && berubah[0].JumlahMinimum === '1';

        saatBerubah(hanyaDasarKosong ? [] : berubah);
    };

    return (
        <div className="flex flex-col gap-2">
            {tampil.length === 0 ? (
                <p className="rounded-kontrol border border-dashed border-garis-input px-3 py-2 text-isi text-teks-sekunder">
                    {wajibDasar
                        ? `Belum ada harga jual per ${simbolSatuan}. Selama kosong, ${simbolSatuan} belum bisa dipilih di kasir (masih bisa dipakai saat belanja stok).`
                        : 'Belum ada harga.'}
                </p>
            ) : (
                <TabelForm label="Harga bertingkat" lebar="sempit">
                    <TableCaption className="sr-only">{judul}</TableCaption>
                    <TableHeader>
                        <TableRow className="border-garis hover:bg-transparent">
                            <TableHead scope="col" className="h-8 pl-0 text-right text-label text-teks-sekunder">
                                Mulai jumlah ({simbolSatuan})
                            </TableHead>
                            <TableHead scope="col" className="h-8 text-right text-label text-teks-sekunder">
                                Harga per {simbolSatuan}
                            </TableHead>
                            <TableHead scope="col" className="h-8 pr-0 text-label text-teks-sekunder">
                                <span className="sr-only">Aksi</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {tampil.map((item, indeks) => {
                            const dasar =
                                wajibDasar &&
                                indeks === 0 &&
                                CekDesimalPositif(item.JumlahMinimum) &&
                                BandingkanDesimal(item.JumlahMinimum, '1') === 0;

                            return (
                                <TableRow key={indeks} className="border-0 align-top hover:bg-transparent">
                                    <TableCell className="py-1 pl-0 whitespace-normal">
                                        <BidangJumlah
                                            label={`Mulai jumlah baris ${String(indeks + 1)}`}
                                            labelTersembunyi
                                            nilai={item.JumlahMinimum}
                                            saatBerubah={(nilai) => Ubah(indeks, { JumlahMinimum: nilai })}
                                            desimal={bolehDesimal ? 4 : 0}
                                            akhiran={simbolSatuan}
                                            disabled={disabled || dasar}
                                            galat={
                                                galatServer[`${String(indeks)}.JumlahMinimum`] ??
                                                perBaris[indeks]?.JumlahMinimum
                                            }
                                            required
                                        />
                                    </TableCell>
                                    <TableCell className="py-1 whitespace-normal">
                                        <BidangUang
                                            label={`Harga baris ${String(indeks + 1)}`}
                                            labelTersembunyi
                                            nilai={item.Harga}
                                            saatBerubah={(nilai) => Ubah(indeks, { Harga: nilai })}
                                            disabled={disabled}
                                            galat={galatServer[`${String(indeks)}.Harga`] ?? perBaris[indeks]?.Harga}
                                            required={!barisKosong}
                                        />
                                    </TableCell>
                                    <TableCell className="py-1 pr-0">
                                        {dasar ? (
                                            <span className="inline-flex h-8 pointer-coarse:h-11 items-center text-keterangan text-teks-sekunder">
                                                Harga jual
                                            </span>
                                        ) : (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                disabled={disabled}
                                                onClick={() => saatBerubah(baris.filter((_, i) => i !== indeks))}
                                                className="h-8 pointer-coarse:h-11 text-destructive"
                                                aria-label={`Hapus tingkat harga baris ${String(indeks + 1)}`}
                                            >
                                                Hapus
                                            </Button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </TabelForm>
            )}
            {barisKosong ? (
                <p className="text-keterangan text-teks-sekunder">
                    Kosongkan bila {simbolSatuan} tidak dijual di kasir (masih bisa dipakai saat belanja stok).
                </p>
            ) : null}
            <div aria-live="polite">
                {umum ? <p className="text-keterangan font-semibold text-bahaya">{umum}</p> : null}
            </div>
            <div className="flex flex-wrap gap-2">
                {!disabled && !barisKosong ? (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            saatBerubah([...baris, { JumlahMinimum: baris.length === 0 ? '1' : '', Harga: '' }])
                        }
                        className="h-8 pointer-coarse:h-11"
                    >
                        {baris.length === 0 ? (wajibDasar ? 'Isi harga jual' : 'Isi harga') : 'Tambah harga bertingkat'}
                    </Button>
                ) : null}
                {!disabled && baris.length > 1 ? (
                    <Button
                        type="button"
                        variant="link"
                        onClick={() => saatBerubah(UrutkanBarisHarga(baris))}
                        className="h-8 pointer-coarse:h-11 px-2"
                    >
                        Urutkan menurut jumlah
                    </Button>
                ) : null}
            </div>
        </div>
    );
}
