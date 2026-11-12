import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import BidangBerkas from '@/Komponen/Formulir/BidangBerkas';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import { HitungSubtotal } from '@/Komponen/Pembelian/AturanPembelian';
import { AlamatPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { BandingkanDesimal, JumlahkanDesimal, KurangiDesimal } from '@/Pustaka/HitungDesimal';
import { TulisTanggal, UraiTanggal } from '@/Pustaka/Tanggal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsFormFaktur } from '@/Tipe/Pembelian';

const alamat = `${AlamatPembelian}/faktur`;

/** Tanggal `YYYY-MM-DD` + `hari` (kalender lokal); '' bila tanggal tidak valid. */
export function TambahHari(tanggal: string, hari: number): string {
    const awal = UraiTanggal(tanggal);

    if (awal === undefined) {
        return '';
    }

    const hasil = new Date(awal.getFullYear(), awal.getMonth(), awal.getDate() + hari);

    return TulisTanggal(hasil);
}

type HargaBaris = { Harga: string; Diskon: string };

/**
 * F-04 fase 1: catat faktur pemasok dari penerimaan yang belum difakturkan (3-way matching PO–penerimaan–faktur).
 * Harga faktur boleh berbeda dari penerimaan; selisihnya disesuaikan ke HPP oleh server (BR-04.4).
 */
export default function HalamanFormFaktur({
    OpsiPemasok,
    UuidPemasok,
    TerminHari,
    UuidPenerimaanAwal,
    Penerimaan,
    HariIni,
    Lampiran,
}: PropsFormFaktur) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [terpilih, AturTerpilih] = useState<string[]>(() =>
        UuidPenerimaanAwal && Penerimaan.some((p) => p.Uuid === UuidPenerimaanAwal)
            ? [UuidPenerimaanAwal]
            : Penerimaan.map((p) => p.Uuid),
    );
    const [nomor, AturNomor] = useState('');
    const [tanggal, AturTanggal] = useState(HariIni);
    const [jatuhTempo, AturJatuhTempo] = useState(TambahHari(HariIni, TerminHari ?? 0));
    const [ongkir, AturOngkir] = useState('');
    const [catatan, AturCatatan] = useState('');
    const [berkas, AturBerkas] = useState<File[]>([]);
    const [harga, AturHarga] = useState<Record<number, HargaBaris>>(() =>
        Object.fromEntries(Penerimaan.flatMap((p) => p.Baris).map((b) => [b.Id, { Harga: b.Harga, Diskon: b.Diskon }])),
    );
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const penerimaanTerpilih = Penerimaan.filter((p) => terpilih.includes(p.Uuid));
    const barisTerpilih = penerimaanTerpilih.flatMap((p) => p.Baris);
    const nilaiPenerimaan = JumlahkanDesimal(barisTerpilih.map((b) => b.Subtotal));
    const nilaiFaktur = JumlahkanDesimal(
        barisTerpilih.map((b) => {
            const h = harga[b.Id] ?? { Harga: b.Harga, Diskon: b.Diskon };

            return HitungSubtotal(b.Jumlah, h.Harga, h.Diskon) ?? '0';
        }),
    );
    const selisih = KurangiDesimal(nilaiFaktur, nilaiPenerimaan);

    const GantiPemasok = (uuid: string) =>
        router.get(alamat + '/buat', uuid === '' ? {} : { pemasok: uuid }, { preserveState: false });

    const GantiTanggal = (nilai: string) => {
        AturTanggal(nilai);
        AturJatuhTempo(TambahHari(nilai, TerminHari ?? 0));
    };

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksa(true);

        if (UuidPemasok === null || terpilih.length === 0 || nomor.trim() === '') {
            return;
        }

        router.post(
            alamat,
            {
                UuidPemasok,
                NomorFakturPemasok: nomor.trim(),
                Tanggal: tanggal,
                JatuhTempo: jatuhTempo === '' ? null : jatuhTempo,
                UuidPenerimaan: terpilih,
                Ongkir: ongkir === '' ? '0' : ongkir,
                Catatan: catatan === '' ? null : catatan,
                Lampiran: berkas[0] ?? null,
                Baris: barisTerpilih.map((b) => {
                    const h = harga[b.Id] ?? { Harga: b.Harga, Diskon: b.Diskon };

                    return {
                        IdBarisPenerimaan: b.Id,
                        Harga: h.Harga === '' ? '0' : h.Harga,
                        Diskon: h.Diskon === '' ? '0' : h.Diskon,
                    };
                }),
            },
            {
                preserveScroll: true,
                forceFormData: berkas.length > 0,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
            },
        );
    };

    return (
        <TataLetakAplikasi judul="Catat faktur pembelian">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Pilih penerimaan barang yang ditagih pemasok, lalu cocokkan harga dengan faktur. Selisih harga
                menyesuaikan HPP.
            </p>
            <DaftarGalatServer
                galat={galat}
                kecuali={[
                    'UuidPemasok',
                    'NomorFakturPemasok',
                    'Tanggal',
                    'JatuhTempo',
                    'Ongkir',
                    'Catatan',
                    'Lampiran',
                ]}
            />
            <form onSubmit={Simpan} noValidate aria-label="Catat faktur pembelian" className="flex flex-col gap-4">
                <Panel judul="Faktur" idJudul="judul-faktur">
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <BidangPilihan
                            label="Pemasok"
                            nilai={UuidPemasok ?? ''}
                            kosong="Pilih pemasok"
                            opsi={OpsiPemasok.map((p) => ({ Nilai: p.Uuid, Label: p.Nama, Keterangan: p.Kode }))}
                            saatBerubah={GantiPemasok}
                            required
                            galat={
                                galat.UuidPemasok ?? (periksa && UuidPemasok === null ? 'Pilih pemasok.' : undefined)
                            }
                        />
                        <BidangTeks
                            label="Nomor faktur pemasok"
                            nilai={nomor}
                            saatBerubah={AturNomor}
                            galat={
                                galat.NomorFakturPemasok ??
                                (periksa && nomor.trim() === '' ? 'Isi nomor faktur dari pemasok.' : undefined)
                            }
                            maxLength={60}
                            kode
                            required
                        />
                        <PemilihTanggal
                            id="tanggal-faktur"
                            label="Tanggal faktur"
                            nilai={tanggal}
                            max={HariIni}
                            required
                            saatBerubah={GantiTanggal}
                            galat={galat.Tanggal}
                        />
                        <PemilihTanggal
                            id="jatuh-tempo-faktur"
                            label="Jatuh tempo"
                            nilai={jatuhTempo}
                            min={tanggal}
                            saatBerubah={AturJatuhTempo}
                            galat={galat.JatuhTempo}
                        />
                        <BidangUang
                            label="Ongkos kirim di faktur (opsional)"
                            nilai={ongkir}
                            saatBerubah={AturOngkir}
                            galat={galat.Ongkir}
                        />
                    </div>
                    <BidangTeksPanjang
                        label="Catatan (opsional)"
                        nilai={catatan}
                        saatBerubah={AturCatatan}
                        galat={galat.Catatan}
                        baris={2}
                        maksimal={500}
                    />
                    <BidangBerkas
                        label="Lampiran faktur (opsional)"
                        berkas={berkas}
                        saatBerubah={AturBerkas}
                        ekstensi={Lampiran.Ekstensi}
                        maksimal={1}
                        ukuranMaksimalKb={Lampiran.UkuranMaksimalKb}
                        galat={galat.Lampiran}
                    />
                </Panel>

                <Panel judul="Penerimaan yang ditagih" idJudul="judul-penerimaan-faktur">
                    {UuidPemasok === null ? (
                        <p className="text-isi text-teks-sekunder">Pilih pemasok untuk melihat penerimaan barangnya.</p>
                    ) : Penerimaan.length === 0 ? (
                        <Pemberitahuan jenis="info" judul="Tidak ada penerimaan yang belum difakturkan">
                            Semua penerimaan barang pemasok ini sudah difakturkan.
                        </Pemberitahuan>
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {Penerimaan.map((p) => (
                                <li key={p.Uuid} className="flex flex-col gap-3 rounded-panel border border-garis p-3">
                                    <KotakCentang
                                        label={`${p.Nomor} | ${FormatTanggal(p.Tanggal)}${p.NamaOutlet ? ` | ${p.NamaOutlet}` : ''}`}
                                        nilai={terpilih.includes(p.Uuid)}
                                        saatBerubah={(ya) =>
                                            AturTerpilih((lama) =>
                                                ya ? [...lama, p.Uuid] : lama.filter((u) => u !== p.Uuid),
                                            )
                                        }
                                    />
                                    {terpilih.includes(p.Uuid) ? (
                                        <ul className="flex flex-col gap-2">
                                            {p.Baris.map((b) => {
                                                const h = harga[b.Id] ?? { Harga: b.Harga, Diskon: b.Diskon };
                                                const sub = HitungSubtotal(b.Jumlah, h.Harga, h.Diskon);
                                                const beda =
                                                    BandingkanDesimal(h.Harga === '' ? '0' : h.Harga, b.Harga) !== 0 ||
                                                    BandingkanDesimal(h.Diskon === '' ? '0' : h.Diskon, b.Diskon) !== 0;

                                                return (
                                                    <li
                                                        key={b.Id}
                                                        className="grid gap-2 border-t border-garis pt-2 sm:grid-cols-[1fr_12rem_10rem_9rem]"
                                                    >
                                                        <span className="flex flex-col">
                                                            <span className="font-semibold break-words">
                                                                {b.NamaProduk}
                                                            </span>
                                                            <span className="text-keterangan text-teks-sekunder">
                                                                {FormatJumlahStok(b.Jumlah, b.SimbolSatuan)} × harga
                                                                terima {FormatRupiah(b.Harga)}
                                                            </span>
                                                        </span>
                                                        <BidangUang
                                                            label={`Harga faktur ${b.NamaProduk}`}
                                                            nilai={h.Harga}
                                                            required
                                                            saatBerubah={(nilai) =>
                                                                AturHarga((lama) => ({
                                                                    ...lama,
                                                                    [b.Id]: { ...h, Harga: nilai },
                                                                }))
                                                            }
                                                        />
                                                        <BidangUang
                                                            label={`Diskon ${b.NamaProduk}`}
                                                            nilai={h.Diskon}
                                                            saatBerubah={(nilai) =>
                                                                AturHarga((lama) => ({
                                                                    ...lama,
                                                                    [b.Id]: { ...h, Diskon: nilai },
                                                                }))
                                                            }
                                                        />
                                                        <span className="flex flex-col items-end justify-end tabular-nums">
                                                            <span>{sub === null ? '—' : FormatRupiah(sub)}</span>
                                                            {beda ? (
                                                                <span className="text-keterangan text-peringatan">
                                                                    Beda dari penerimaan
                                                                </span>
                                                            ) : null}
                                                        </span>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                    {periksa && UuidPemasok !== null && terpilih.length === 0 ? (
                        <p className="text-keterangan font-semibold text-bahaya">Pilih minimal satu penerimaan.</p>
                    ) : null}
                    {galat.UuidPenerimaan ? (
                        <p className="text-keterangan font-semibold text-bahaya">{galat.UuidPenerimaan}</p>
                    ) : null}
                    <dl className="ml-auto flex w-full max-w-md flex-col gap-1 border-t border-garis pt-3">
                        <div className="flex justify-between gap-3">
                            <dt className="text-teks-sekunder">Nilai penerimaan</dt>
                            <dd className="tabular-nums">{FormatRupiah(nilaiPenerimaan)}</dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-teks-sekunder">Nilai faktur (sebelum PPN & ongkir)</dt>
                            <dd className="tabular-nums">{FormatRupiah(nilaiFaktur)}</dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="font-semibold">Selisih harga</dt>
                            <dd className="font-semibold tabular-nums">{FormatRupiah(selisih)}</dd>
                        </div>
                    </dl>
                </Panel>

                <BilahAksiForm>
                    <Tombol type="submit" memproses={memproses}>
                        Simpan faktur
                    </Tombol>
                    <Button asChild variant="outline">
                        <Link href={alamat}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
