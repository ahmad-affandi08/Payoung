import { router } from '@inertiajs/react';
import { useState } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import TombolPindaiKamera from '@/Komponen/Katalog/TombolPindaiKamera';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import { TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import type { BarisVarian } from '@/Tipe/Katalog';

type Isian = { Harga: string; Barcode: string };

/** Harga server ("31250.00") ke isian uang tanpa nol desimal berlebih. */
function KeIsianHarga(harga: string | null): string {
    if (harga === null) {
        return '';
    }
    const [bulat = '0', pecahan = ''] = harga.split('.');
    const dua = pecahan.slice(0, 2).replace(/0+$/, '');
    return dua === '' ? bulat : `${bulat}.${dua}`;
}

/**
 * Audit kemudahan pakai #18 (F-03): ubah harga jual & tambah barcode semua varian di satu tabel, plus "Isi sama untuk
 * semua" untuk harga. Hanya baris yang berubah yang dikirim (`PUT /kelola/produk/{uuid}/varian`).
 */
export default function TabelUbahVarian({
    uuidProduk,
    varian,
    bolehUbahHarga,
}: {
    uuidProduk: string;
    varian: BarisVarian[];
    bolehUbahHarga: boolean;
}) {
    const awal = Object.fromEntries(
        varian.map((v) => [v.Uuid, { Harga: KeIsianHarga(v.HargaDasar), Barcode: v.Barcode ?? '' }]),
    ) as Record<string, Isian>;
    const [isian, AturIsian] = useState<Record<string, Isian>>(awal);
    const [hargaSemua, AturHargaSemua] = useState('');
    const [memproses, AturMemproses] = useState(false);

    const Ubah = (uuid: string, perubahan: Partial<Isian>) =>
        AturIsian((lama) => ({ ...lama, [uuid]: { ...(lama[uuid] as Isian), ...perubahan } }));
    const berubah = varian.filter((v) => {
        const a = awal[v.Uuid] as Isian;
        const b = isian[v.Uuid] as Isian;
        return a.Harga !== b.Harga || (b.Barcode.trim() !== '' && a.Barcode !== b.Barcode);
    });

    const Simpan = () =>
        router.put(
            `/kelola/produk/${uuidProduk}/varian`,
            {
                Baris: berubah.map((v) => {
                    const a = awal[v.Uuid] as Isian;
                    const b = isian[v.Uuid] as Isian;
                    return {
                        Uuid: v.Uuid,
                        Harga: a.Harga !== b.Harga && b.Harga !== '' ? b.Harga : null,
                        Barcode: a.Barcode !== b.Barcode && b.Barcode.trim() !== '' ? b.Barcode.trim() : null,
                    };
                }),
            },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );

    return (
        <div className="flex flex-col gap-3">
            {bolehUbahHarga ? (
                <div className="flex flex-wrap items-end gap-2">
                    <div className="w-48">
                        <BidangUang label="Harga untuk semua varian" nilai={hargaSemua} saatBerubah={AturHargaSemua} />
                    </div>
                    <Tombol
                        varian="sekunder"
                        disabled={hargaSemua === ''}
                        onClick={() =>
                            AturIsian((lama) =>
                                Object.fromEntries(
                                    Object.entries(lama).map(([uuid, i]) => [uuid, { ...i, Harga: hargaSemua }]),
                                ),
                            )
                        }
                    >
                        Isi sama untuk semua
                    </Tombol>
                </div>
            ) : null}
            <TabelForm label="Ubah harga & barcode varian" lebar="sedang">
                <TableCaption className="sr-only">Harga & barcode varian</TableCaption>
                <TableHeader>
                    <TableRow>
                        <TableHead scope="col">Varian</TableHead>
                        <TableHead scope="col" className="w-44">
                            Harga jual
                        </TableHead>
                        <TableHead scope="col" className="w-64">
                            Barcode
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {varian.map((v) => (
                        <TableRow key={v.Uuid}>
                            <TableCell className="align-top whitespace-normal">{v.Nama}</TableCell>
                            <TableCell className="align-top">
                                <BidangUang
                                    label={`Harga ${v.Nama}`}
                                    labelTersembunyi
                                    nilai={(isian[v.Uuid] as Isian).Harga}
                                    saatBerubah={(harga) => Ubah(v.Uuid, { Harga: harga })}
                                    disabled={!bolehUbahHarga}
                                />
                            </TableCell>
                            <TableCell className="align-top">
                                <div className="flex items-start gap-2">
                                    <div className="min-w-0 flex-1">
                                        <BidangTeks
                                            label={`Barcode ${v.Nama}`}
                                            labelTersembunyi
                                            nilai={(isian[v.Uuid] as Isian).Barcode}
                                            saatBerubah={(barcode) => Ubah(v.Uuid, { Barcode: barcode })}
                                            disabled={(awal[v.Uuid] as Isian).Barcode !== ''}
                                            maxLength={50}
                                            kode
                                        />
                                    </div>
                                    {(awal[v.Uuid] as Isian).Barcode === '' ? (
                                        <TombolPindaiKamera
                                            ikonSaja
                                            label={`Pindai barcode ${v.Nama}`}
                                            saatTerbaca={(barcode) => Ubah(v.Uuid, { Barcode: barcode.slice(0, 50) })}
                                        />
                                    ) : null}
                                </div>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </TabelForm>
            <div>
                <Tombol disabled={berubah.length === 0} memproses={memproses} onClick={Simpan}>
                    Simpan perubahan varian
                </Tombol>
            </div>
        </div>
    );
}
