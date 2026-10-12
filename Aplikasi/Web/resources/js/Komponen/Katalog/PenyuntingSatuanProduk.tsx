import { useId } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import { Button } from '@/Komponen/Ui/button';
import type { FormSatuanProduk, OpsiSatuan } from '@/Tipe/Katalog';

import { AmbilGalatBerawalan } from './BantuanKatalog';
import BidangBarcode from './BidangBarcode';
import BidangJumlah from './BidangJumlah';

/** Pastikan satuan dasar ada (konversi 1) dan berada di urutan pertama. */
export function SiapkanSatuanDasar(satuan: FormSatuanProduk[], uuidSatuanDasar: string): FormSatuanProduk[] {
    if (uuidSatuanDasar === '') {
        return satuan;
    }

    const dasar = satuan.find((item) => item.UuidSatuan === uuidSatuanDasar);
    const lain = satuan.filter((item) => item !== dasar);

    if (dasar) {
        return [dasar, ...lain];
    }

    return [
        {
            Uuid: null,
            UuidSatuan: uuidSatuanDasar,
            KonversiKeDasar: '1',
            DefaultJual: !lain.some((item) => item.DefaultJual),
            DefaultBeli: !lain.some((item) => item.DefaultBeli),
            Barcode: [],
            HargaAwal: [],
        },
        ...lain,
    ];
}

/** Ganti satuan dasar: baris dasar lama ikut berganti satuan; satuan alternatif yang sama dibuang. */
export function GantiSatuanDasar(satuan: FormSatuanProduk[], lama: string, baru: string): FormSatuanProduk[] {
    const tanpaBentrok = satuan.filter((item) => item.UuidSatuan !== baru || item.UuidSatuan === lama);
    const diganti = tanpaBentrok.map((item) =>
        item.UuidSatuan === lama && lama !== '' ? { ...item, UuidSatuan: baru, KonversiKeDasar: '1' } : item,
    );

    return SiapkanSatuanDasar(diganti, baru);
}

type PropsPenyuntingSatuanProduk = {
    satuan: FormSatuanProduk[];
    uuidSatuanDasar: string;
    opsiSatuan: OpsiSatuan[];
    saatBerubah: (satuan: FormSatuanProduk[]) => void;
    /** Semua galat server formulir produk; kunci `Satuan.{i}.…` dibaca di sini. */
    galat: Record<string, string | undefined>;
    disabled?: boolean;
    /** Teks bila satuan dasar belum dipilih; bergantung letak pilihan satuan dasar di formulir pemanggil. */
    petunjukSatuanDasar?: string;
};

/**
 * Satuan & konversi produk (BR-03.1 barcode per satuan). Baris pertama = satuan dasar (isi 1).
 * Tepat satu satuan jual bawaan dan satu satuan beli bawaan.
 */
export default function PenyuntingSatuanProduk({
    satuan,
    uuidSatuanDasar,
    opsiSatuan,
    saatBerubah,
    galat,
    disabled = false,
    petunjukSatuanDasar = 'Pilih satuan dasar di tab Umum lebih dulu.',
}: PropsPenyuntingSatuanProduk) {
    const id = useId();
    const dasar = opsiSatuan.find((item) => item.Uuid === uuidSatuanDasar);
    const Ubah = (indeks: number, perubahan: Partial<FormSatuanProduk>) =>
        saatBerubah(satuan.map((item, i) => (i === indeks ? { ...item, ...perubahan } : item)));
    const PilihBawaan = (indeks: number, kunci: 'DefaultJual' | 'DefaultBeli') =>
        saatBerubah(satuan.map((item, i) => ({ ...item, [kunci]: i === indeks })));
    const terpakai = satuan.map((item) => item.UuidSatuan);
    const sisa = opsiSatuan.filter((item) => !terpakai.includes(item.Uuid));

    if (!dasar) {
        return <p className="text-isi text-teks-sekunder">{petunjukSatuanDasar}</p>;
    }

    return (
        <div className="flex flex-col gap-3">
            {galat.Satuan ? (
                <p role="alert" className="text-keterangan font-semibold text-bahaya">
                    {galat.Satuan}
                </p>
            ) : null}
            {satuan.map((baris, indeks) => {
                const opsi = opsiSatuan.find((item) => item.Uuid === baris.UuidSatuan);
                const galatBaris = AmbilGalatBerawalan(galat, `Satuan.${String(indeks)}`);
                const galatBarcode: Record<number, string | undefined> = {};
                const barcodeLain = satuan.filter((_, i) => i !== indeks).flatMap((item) => item.Barcode);
                const namaSatuan = opsi ? `${opsi.Nama} (${opsi.Simbol})` : 'satuan baru';

                Object.entries(AmbilGalatBerawalan(galatBaris, 'Barcode')).forEach(([kunci, pesan]) => {
                    galatBarcode[Number.parseInt(kunci, 10)] = pesan;
                });

                return (
                    <fieldset
                        key={baris.Uuid ?? `baru-${String(indeks)}`}
                        className="flex flex-col gap-3 rounded-panel border border-garis bg-card p-3"
                    >
                        <legend className="px-1 text-label font-semibold text-teks-utama">
                            {indeks === 0 ? `Satuan dasar: ${namaSatuan}` : `Satuan alternatif ${String(indeks)}`}
                        </legend>
                        {indeks > 0 ? (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <BidangPilihan
                                    label="Satuan"
                                    nilai={baris.UuidSatuan}
                                    kosong="Pilih satuan"
                                    opsi={[...(opsi ? [opsi] : []), ...sisa].map((item) => ({
                                        Nilai: item.Uuid,
                                        Label: `${item.Nama} (${item.Simbol})`,
                                    }))}
                                    saatBerubah={(nilai) => Ubah(indeks, { UuidSatuan: nilai })}
                                    galat={galatBaris.UuidSatuan}
                                    required
                                />
                                <BidangJumlah
                                    label={`Isi dalam ${dasar.Simbol}`}
                                    nilai={baris.KonversiKeDasar}
                                    saatBerubah={(nilai) => Ubah(indeks, { KonversiKeDasar: nilai })}
                                    akhiran={dasar.Simbol}
                                    keterangan={
                                        opsi
                                            ? `1 ${opsi.Simbol} = ${baris.KonversiKeDasar || '…'} ${dasar.Simbol}. Maksimal 4 angka desimal.`
                                            : 'Maksimal 4 angka desimal.'
                                    }
                                    galat={galatBaris.KonversiKeDasar}
                                    disabled={disabled}
                                    required
                                />
                            </div>
                        ) : (
                            <p className="text-keterangan text-teks-sekunder">
                                Stok, resep, dan harga satuan lain dihitung dari satuan ini. Isi 1 {dasar.Simbol}.
                            </p>
                        )}
                        <BidangBarcode
                            label={`Barcode ${opsi?.Simbol ?? 'satuan'}`}
                            nilai={baris.Barcode}
                            saatBerubah={(nilai) => Ubah(indeks, { Barcode: nilai })}
                            barcodeLain={barcodeLain}
                            galatPerIndeks={galatBarcode}
                            galat={galatBaris.Barcode}
                            disabled={disabled}
                        />
                        <div className="flex flex-wrap gap-4 text-isi text-teks-utama">
                            <label className="flex min-h-10 items-center gap-2">
                                <input
                                    type="radio"
                                    name={`${id}-jual`}
                                    checked={baris.DefaultJual}
                                    onChange={() => PilihBawaan(indeks, 'DefaultJual')}
                                    disabled={disabled}
                                    className="size-4 accent-primary"
                                />
                                Satuan jual bawaan
                            </label>
                            <label className="flex min-h-10 items-center gap-2">
                                <input
                                    type="radio"
                                    name={`${id}-beli`}
                                    checked={baris.DefaultBeli}
                                    onChange={() => PilihBawaan(indeks, 'DefaultBeli')}
                                    disabled={disabled}
                                    className="size-4 accent-primary"
                                />
                                Satuan beli bawaan
                            </label>
                        </div>
                        {indeks > 0 && !disabled ? (
                            <p>
                                <Button
                                    type="button"
                                    variant="link"
                                    onClick={() => {
                                        const tersisa = satuan.filter((_, i) => i !== indeks);
                                        saatBerubah(
                                            tersisa.map((item, i) => ({
                                                ...item,
                                                DefaultJual: item.DefaultJual || (baris.DefaultJual && i === 0),
                                                DefaultBeli: item.DefaultBeli || (baris.DefaultBeli && i === 0),
                                            })),
                                        );
                                    }}
                                    className="h-auto px-0 text-destructive"
                                >
                                    Hapus satuan {opsi?.Simbol ?? String(indeks)}
                                </Button>
                                {baris.Uuid !== null ? (
                                    <span className="block text-keterangan text-teks-sekunder">
                                        Harga dan barcode satuan ini ikut terhapus saat produk disimpan.
                                    </span>
                                ) : null}
                            </p>
                        ) : null}
                    </fieldset>
                );
            })}
            {!disabled && sisa.length > 0 ? (
                <p>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            saatBerubah([
                                ...satuan,
                                {
                                    Uuid: null,
                                    UuidSatuan: '',
                                    KonversiKeDasar: '',
                                    DefaultJual: false,
                                    DefaultBeli: false,
                                    Barcode: [],
                                    HargaAwal: [],
                                },
                            ])
                        }
                        className="h-8 pointer-coarse:h-11"
                    >
                        Tambah satuan alternatif
                    </Button>
                    <span className="mt-1 block text-keterangan text-teks-sekunder">
                        Misal pak isi 10 atau dus isi 24. Harga satuan baru diisi di tab Harga.
                    </span>
                </p>
            ) : null}
        </div>
    );
}
