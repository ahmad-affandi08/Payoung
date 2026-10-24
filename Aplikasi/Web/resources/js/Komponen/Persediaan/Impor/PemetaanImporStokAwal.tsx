import { router } from '@inertiajs/react';
import { useId, useState, type FormEvent } from 'react';

import TabelForm from '@/Komponen/TabelData/TabelForm';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import Tombol from '@/Komponen/Formulir/Tombol';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { Card } from '@/Komponen/Ui/card';
import { TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import type { BidangImporStokAwal, OpsiGudang, PropsDetailImporStokAwal } from '@/Tipe/Persediaan';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { TulisTanggal } from '@/Pustaka/Tanggal';

export type DataPemetaanStokAwal = NonNullable<PropsDetailImporStokAwal['Pemetaan']>;

/** Label opsi lokasi stok: "Nama | Outlet" (lokasi tanpa outlet cukup namanya). */
export function LabelOpsiGudang(gudang: OpsiGudang): string {
    return gudang.NamaOutlet ? `${gudang.Nama} | ${gudang.NamaOutlet}` : gudang.Nama;
}

/**
 * Galat lokal pemetaan (server tetap memeriksa ulang): pencocok produk (SKU/Barcode/Nama Produk) minimal satu,
 * Stok & Harga Modal wajib, Lokasi Stok atau lokasi bawaan, satu kolom hanya untuk satu bidang, tanggal terisi.
 */
export function PeriksaPemetaanStokAwal(
    bidang: DataPemetaanStokAwal['Bidang'],
    pemetaan: Record<BidangImporStokAwal, number | null>,
    uuidGudangBawaan: string | null,
    tanggal: string,
): Record<string, string> {
    const galat: Record<string, string> = {};
    const pemakai = new Map<number, string>();

    if (pemetaan.Sku === null && pemetaan.Barcode === null && pemetaan.NamaProduk === null) {
        galat.Sku = 'Petakan minimal satu kolom pencocok produk: SKU, Barcode, atau Nama Produk.';
    }

    if (pemetaan.Lokasi === null && uuidGudangBawaan === null) {
        galat.Lokasi = 'Petakan kolom Lokasi Stok atau pilih lokasi stok bawaan.';
    }

    bidang.forEach((item) => {
        const kolom = pemetaan[item.Kunci];

        if (item.Wajib && kolom === null) {
            galat[item.Kunci] = `${item.Label} wajib dipetakan ke satu kolom.`;
        }

        if (kolom !== null) {
            const lain = pemakai.get(kolom);

            if (lain !== undefined) {
                galat[item.Kunci] = `Kolom ini sudah dipakai untuk ${lain}.`;
            } else {
                pemakai.set(kolom, item.Label);
            }
        }
    });

    if (!/^\d{4}-\d{2}-\d{2}$/.test(tanggal)) {
        galat.Tanggal = 'Isi tanggal stok awal.';
    }

    return galat;
}

type PropsPemetaanImporStokAwal = {
    uuidImpor: string;
    pemetaan: DataPemetaanStokAwal;
    opsiGudang: OpsiGudang[];
    galatServer: Record<string, string | undefined>;
    saatBatal?: () => void;
};

/**
 * Langkah 2 impor stok awal: cocokkan kolom berkas ke bidang stok awal, pilih lokasi stok bawaan & tanggal stok
 * awal, lalu periksa data (DesainF05a C.7).
 */
export default function PemetaanImporStokAwal({
    uuidImpor,
    pemetaan: data,
    opsiGudang,
    galatServer,
    saatBatal,
}: PropsPemetaanImporStokAwal) {
    const id = useId();
    const [pemetaan, AturPemetaan] = useState<Record<BidangImporStokAwal, number | null>>(data.Pemetaan);
    const [uuidGudang, AturUuidGudang] = useState<string | null>(data.UuidGudangBawaan);
    const [tanggal, AturTanggal] = useState(data.Tanggal);
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const galatLokal = periksa ? PeriksaPemetaanStokAwal(data.Bidang, pemetaan, uuidGudang, tanggal) : {};
    const jumlahGalat = Object.keys(galatLokal).length;
    const galatTanggal = galatLokal.Tanggal ?? galatServer.Tanggal;
    const opsiKolom = data.KolomSumber.map((kolom) => ({
        Nilai: String(kolom.Indeks),
        Label: kolom.Judul || `Kolom ${String(kolom.Indeks + 1)}`,
    }));

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksa(true);

        if (Object.keys(PeriksaPemetaanStokAwal(data.Bidang, pemetaan, uuidGudang, tanggal)).length > 0) {
            return;
        }

        router.put(
            `/kelola/persediaan/stok-awal/impor/${uuidImpor}/pemetaan`,
            { Pemetaan: pemetaan, UuidGudangBawaan: uuidGudang, Tanggal: tanggal },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );
    };

    return (
        <Card className="gap-4 rounded-panel p-4 shadow-none">
            <form onSubmit={Kirim} noValidate aria-labelledby={`${id}-judul`} className="flex flex-col gap-4">
                <h2 id={`${id}-judul`} className="text-subjudul font-semibold text-teks-utama">
                    Pemetaan kolom
                </h2>
                <div aria-live="polite">
                    {jumlahGalat > 0 ? (
                        <Pemberitahuan jenis="bahaya">Ada {jumlahGalat} isian yang perlu diperbaiki.</Pemberitahuan>
                    ) : null}
                </div>

                <fieldset className="grid gap-4 sm:grid-cols-2">
                    <legend className="mb-2 text-label font-semibold text-teks-utama">Dokumen stok awal</legend>
                    <BidangPilihan
                        label="Lokasi stok bawaan"
                        nilai={uuidGudang ?? ''}
                        kosong="Tidak ada (isi kolom Lokasi Stok di berkas)"
                        opsi={opsiGudang.map((gudang) => ({ Nilai: gudang.Uuid, Label: LabelOpsiGudang(gudang) }))}
                        saatBerubah={(nilai) => AturUuidGudang(nilai === '' ? null : nilai)}
                        galat={galatServer.UuidGudangBawaan}
                    />
                    <PemilihTanggal
                        id={`${id}-tanggal`}
                        label="Tanggal stok awal"
                        nilai={tanggal}
                        max={TulisTanggal(new Date())}
                        required
                        saatBerubah={AturTanggal}
                        keterangan="Tanggal saldo awal. Tidak boleh melewati hari ini."
                        galat={galatTanggal}
                    />
                </fieldset>

                <TabelForm label="Pemetaan kolom impor stok awal" lebar="sedang">
                    <TableCaption className="sr-only">Pemetaan bidang stok awal ke kolom berkas</TableCaption>
                    <TableHeader>
                        <TableRow className="border-garis hover:bg-transparent">
                            <TableHead scope="col" className="pl-0 text-label text-teks-sekunder">
                                Bidang stok awal
                            </TableHead>
                            <TableHead scope="col" className="text-label text-teks-sekunder">
                                Kolom di berkas
                            </TableHead>
                            <TableHead scope="col" className="pr-0 text-label text-teks-sekunder">
                                Contoh isi
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {data.Bidang.map((bidang) => {
                            const kolom = pemetaan[bidang.Kunci];
                            const contoh = data.KolomSumber.find((item) => item.Indeks === kolom)?.Contoh ?? [];

                            return (
                                <TableRow key={bidang.Kunci} className="border-garis align-top hover:bg-transparent">
                                    <th scope="row" className="p-2 pl-0 text-left align-top font-normal">
                                        <span className="block font-semibold text-teks-utama">
                                            {bidang.Label}
                                            {bidang.Wajib ? ' (wajib)' : ''}
                                        </span>
                                        <span className="block text-keterangan text-teks-sekunder">
                                            {bidang.Keterangan}
                                        </span>
                                    </th>
                                    <TableCell className="whitespace-normal">
                                        <BidangPilihan
                                            label={`Kolom untuk ${bidang.Label}`}
                                            labelTersembunyi
                                            nilai={kolom === null ? '' : String(kolom)}
                                            kosong="Tidak diimpor"
                                            opsi={opsiKolom}
                                            saatBerubah={(nilai) =>
                                                AturPemetaan({
                                                    ...pemetaan,
                                                    [bidang.Kunci]: nilai === '' ? null : Number.parseInt(nilai, 10),
                                                })
                                            }
                                            galat={galatLokal[bidang.Kunci] ?? galatServer[`Pemetaan.${bidang.Kunci}`]}
                                            required={bidang.Wajib}
                                        />
                                    </TableCell>
                                    <TableCell className="pr-0 text-keterangan break-all whitespace-normal text-teks-sekunder">
                                        {contoh.length === 0 ? '—' : contoh.filter(Boolean).slice(0, 3).join(' | ')}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </TabelForm>

                <div className="flex flex-wrap gap-2">
                    <Tombol type="submit" memproses={memproses}>
                        Periksa data
                    </Tombol>
                    {saatBatal ? (
                        <Tombol varian="sekunder" onClick={saatBatal}>
                            Batal ubah pemetaan
                        </Tombol>
                    ) : null}
                </div>
            </form>
        </Card>
    );
}
