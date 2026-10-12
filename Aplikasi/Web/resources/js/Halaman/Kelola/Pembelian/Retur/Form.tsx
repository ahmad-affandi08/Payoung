import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import { AlamatPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import { FormatHppSatuan, FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { BandingkanDesimal, CekDesimalValid } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisDetailPenerimaan, PropsFormRetur } from '@/Tipe/Pembelian';

type IsianRetur = { Jumlah: string; NomorSeri: string[] };

/** Galat lokal satu baris retur (jumlah dalam satuan dasar, ≤ sisa yang bisa diretur). */
export function PeriksaBarisRetur(baris: BarisDetailPenerimaan, isian: IsianRetur): string | null {
    if (baris.NomorSeriBisaDiretur.length > 0 || baris.NomorSeri.length > 0) {
        return null;
    }

    if (isian.Jumlah === '') {
        return null;
    }

    if (!CekDesimalValid(isian.Jumlah) || BandingkanDesimal(isian.Jumlah, '0') < 0) {
        return 'Isi jumlah yang valid.';
    }

    if (BandingkanDesimal(isian.Jumlah, baris.SisaBisaDiretur) > 0) {
        return `Maksimal ${FormatJumlahStok(baris.SisaBisaDiretur)}.`;
    }

    return null;
}

/** F-04 fase 1: retur barang ke pemasok dari satu penerimaan (stok keluar, hutang berkurang; J-04.5). */
export default function HalamanFormRetur({ Penerimaan: p, Baris, HariIni, OpsiAkunRefund }: PropsFormRetur) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [tanggal, AturTanggal] = useState(HariIni);
    const [alasan, AturAlasan] = useState('');
    const [uuidAkunRefund, AturUuidAkunRefund] = useState(
        OpsiAkunRefund.length === 1 ? (OpsiAkunRefund[0]?.Uuid ?? '') : '',
    );
    const [isian, AturIsian] = useState<Record<number, IsianRetur>>(() =>
        Object.fromEntries(Baris.map((b) => [b.Id, { Jumlah: '', NomorSeri: [] }])),
    );
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const bisaDiretur = Baris.filter((b) => BandingkanDesimal(b.SisaBisaDiretur, '0') > 0);
    const dipilih = bisaDiretur.filter((b) => {
        const i = isian[b.Id];

        if (i === undefined) {
            return false;
        }

        return b.NomorSeri.length > 0
            ? i.NomorSeri.length > 0
            : i.Jumlah !== '' && BandingkanDesimal(i.Jumlah, '0') > 0;
    });

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksa(true);
        const adaGalat =
            alasan.trim().length < 5 ||
            dipilih.length === 0 ||
            bisaDiretur.some((b) => PeriksaBarisRetur(b, isian[b.Id] ?? { Jumlah: '', NomorSeri: [] }) !== null);

        if (adaGalat) {
            return;
        }

        router.post(
            `${AlamatPembelian}/retur`,
            {
                UuidPenerimaan: p.Uuid,
                Tanggal: tanggal,
                Alasan: alasan.trim(),
                ...(uuidAkunRefund === '' ? {} : { UuidAkunRefund: uuidAkunRefund }),
                Baris: dipilih.map((b) => {
                    const i = isian[b.Id] ?? { Jumlah: '', NomorSeri: [] };

                    return b.NomorSeri.length > 0
                        ? { IdBarisPenerimaan: b.Id, Jumlah: String(i.NomorSeri.length), NomorSeri: i.NomorSeri }
                        : { IdBarisPenerimaan: b.Id, Jumlah: i.Jumlah, NomorSeri: [] };
                }),
            },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );
    };

    return (
        <TataLetakAplikasi judul={`Retur dari ${p.Nomor}`}>
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Barang keluar dari {p.NamaGudang} senilai harga saat diterima, dan hutang ke{' '}
                {p.Pemasok?.Nama ?? 'pemasok'} berkurang. Bila faktur sudah dibayar (termasuk belanja stok tunai),
                bagian yang melebihi sisa hutang dicatat sebagai uang yang dikembalikan pemasok ke akun yang Anda pilih.
                Jumlah dalam satuan dasar.
            </p>
            <DaftarGalatServer galat={galat} kecuali={['Tanggal', 'Alasan', 'UuidAkunRefund']} />
            <form onSubmit={Simpan} noValidate aria-label="Retur pembelian" className="flex flex-col gap-4">
                <Panel judul="Dokumen" idJudul="judul-dokumen-retur">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <PemilihTanggal
                            id="tanggal-retur"
                            label="Tanggal retur"
                            nilai={tanggal}
                            min={p.Tanggal}
                            max={HariIni}
                            required
                            saatBerubah={AturTanggal}
                            galat={galat.Tanggal}
                        />
                    </div>
                    <BidangPilihan
                        label="Akun penerima pengembalian uang (bila faktur sudah dibayar)"
                        nilai={uuidAkunRefund}
                        opsi={OpsiAkunRefund.map((a) => ({ Nilai: a.Uuid, Label: `${a.Kode} ${a.Nama}` }))}
                        saatBerubah={AturUuidAkunRefund}
                        galat={galat.UuidAkunRefund}
                    />
                    <BidangTeksPanjang
                        label="Alasan retur"
                        nilai={alasan}
                        saatBerubah={AturAlasan}
                        galat={
                            galat.Alasan ??
                            (periksa && alasan.trim().length < 5 ? 'Tulis alasan minimal 5 karakter.' : undefined)
                        }
                        baris={2}
                        maksimal={255}
                        required
                    />
                </Panel>

                <Panel judul="Barang diretur" idJudul="judul-barang-retur">
                    {bisaDiretur.length === 0 ? (
                        <p className="text-isi text-teks-sekunder">Semua barang dari penerimaan ini sudah diretur.</p>
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {bisaDiretur.map((b) => {
                                const i = isian[b.Id] ?? { Jumlah: '', NomorSeri: [] };
                                const indeks = dipilih.findIndex((d) => d.Id === b.Id);
                                const galatBaris =
                                    (indeks >= 0 ? galat[`Baris.${String(indeks)}.Jumlah`] : undefined) ??
                                    (periksa ? (PeriksaBarisRetur(b, i) ?? undefined) : undefined);

                                return (
                                    <li
                                        key={b.Id}
                                        className="flex flex-col gap-2 rounded-panel border border-garis p-3"
                                    >
                                        <span className="flex flex-wrap items-baseline justify-between gap-2">
                                            <span className="font-semibold break-words">{b.NamaProduk}</span>
                                            <span className="text-keterangan text-teks-sekunder">
                                                Bisa diretur {FormatJumlahStok(b.SisaBisaDiretur)} | nilai per satuan{' '}
                                                {FormatHppSatuan(b.HppSatuan)}
                                            </span>
                                        </span>
                                        {b.NomorSeri.length > 0 ? (
                                            <GrupCentang
                                                legenda={`Nomor seri ${b.NamaProduk} yang diretur`}
                                                opsi={b.NomorSeriBisaDiretur.map((n) => ({ nilai: n, label: n }))}
                                                terpilih={i.NomorSeri}
                                                saatBerubah={(nomor) =>
                                                    AturIsian((lama) => ({
                                                        ...lama,
                                                        [b.Id]: { ...i, NomorSeri: nomor },
                                                    }))
                                                }
                                            />
                                        ) : (
                                            <div className="max-w-xs">
                                                <BidangJumlah
                                                    label={`Jumlah retur ${b.NamaProduk}`}
                                                    nilai={i.Jumlah}
                                                    saatBerubah={(nilai) =>
                                                        AturIsian((lama) => ({
                                                            ...lama,
                                                            [b.Id]: { ...i, Jumlah: nilai },
                                                        }))
                                                    }
                                                    galat={galatBaris}
                                                />
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    {periksa && dipilih.length === 0 ? (
                        <p className="text-keterangan font-semibold text-bahaya">
                            Isi jumlah retur minimal satu barang.
                        </p>
                    ) : null}
                    {galat.Baris ? <p className="text-keterangan font-semibold text-bahaya">{galat.Baris}</p> : null}
                </Panel>

                <BilahAksiForm>
                    <Tombol type="submit" memproses={memproses} varian="bahaya">
                        Simpan retur
                    </Tombol>
                    <Button asChild variant="outline">
                        <Link href={`${AlamatPembelian}/penerimaan/${p.Uuid}`}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
