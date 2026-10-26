import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import BidangBerkas from '@/Komponen/Formulir/BidangBerkas';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import {
    HitungTotalBaris,
    PeriksaBaris,
    SusunMasukanBaris,
    type MasukanBarisPembelian,
} from '@/Komponen/Pembelian/AturanPembelian';
import { AlamatPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import IsianBarisPembelian from '@/Komponen/Pembelian/IsianBarisPembelian';
import BidangNomorSeri from '@/Komponen/Persediaan/BidangNomorSeri';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok, FormatLabelGudang } from '@/Pustaka/FormatPersediaan';
import {
    BandingkanDesimal,
    BulatkanDesimal,
    CekDesimalBulat,
    CekDesimalValid,
    JumlahkanDesimal,
    KalikanDesimal,
    KurangiDesimal,
    SkalaJumlah,
} from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisIsianBebas, BarisPesananUntukPenerimaan, PropsFormPenerimaan } from '@/Tipe/Pembelian';

type MasukanBarisDariPesanan = {
    IdBarisPesanan: number;
    Jumlah: string;
    NomorBatch: string | null;
    TanggalKedaluwarsa: string | null;
    NomorSeri: string[];
};

type IsianBarisPesanan = { Jumlah: string; NomorBatch: string; TanggalKedaluwarsa: string; NomorSeri: string[] };

/** Galat lokal satu baris penerimaan dari PO (jumlah boleh 0 = tidak diterima sekarang). */
export function PeriksaBarisDariPesanan(baris: BarisPesananUntukPenerimaan, isian: IsianBarisPesanan): string | null {
    if (isian.Jumlah === '' || isian.Jumlah === '0') {
        return null;
    }

    if (!CekDesimalValid(isian.Jumlah) || BandingkanDesimal(isian.Jumlah, '0') < 0) {
        return 'Isi jumlah 0 atau lebih.';
    }

    const dasar = KalikanDesimal(isian.Jumlah, baris.Konversi, SkalaJumlah);

    if (!baris.BolehDesimal && !CekDesimalBulat(dasar)) {
        return 'Jumlah harus bilangan bulat.';
    }

    if (baris.Pelacakan === 'Batch' && isian.NomorBatch.trim() === '') {
        return 'Isi nomor batch.';
    }

    if (baris.Pelacakan === 'Seri' && String(isian.NomorSeri.length) !== BulatkanDesimal(dasar, 0)) {
        return `Isi tepat ${BulatkanDesimal(dasar, 0)} nomor seri.`;
    }

    return null;
}

/**
 * F-04 fase 1: penerimaan barang (dari PO / tanpa PO) dan belanja stok (sekali simpan: penerimaan + faktur +
 * pembayaran lunas). Stok bertambah saat disimpan; nilai & HPP dihitung server.
 */
/** Tempo pembayaran belanja stok 0–365 hari (sama dengan batas server). */
function CekTerminValid(termin: string): boolean {
    return /^\d{1,3}$/.test(termin) && Number(termin) <= 365;
}

export default function HalamanFormPenerimaan({
    Mode,
    Pesanan,
    OpsiPemasok,
    OpsiGudang,
    OpsiAkun,
    HariIni,
    Lampiran,
    MaksimalBaris,
}: PropsFormPenerimaan) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const belanja = Mode === 'BelanjaStok';
    const [pemasok, AturPemasok] = useState('');
    const [gudang, AturGudang] = useState(Pesanan?.UuidGudang ?? '');
    const [akun, AturAkun] = useState(OpsiAkun[0]?.Uuid ?? '');
    // Audit kemudahan pakai #20: belanja dibayar nanti (tempo) = penerimaan + faktur belum dibayar, tanpa akun kas.
    const [bayarNanti, AturBayarNanti] = useState(false);
    const [termin, AturTermin] = useState('30');
    const [tanggal, AturTanggal] = useState(Pesanan?.TanggalPenerimaan ?? HariIni);
    const [nomor, AturNomor] = useState('');
    // Ongkir pesanan yang belum terpakai terisi otomatis (bisa diubah).
    const [ongkir, AturOngkir] = useState(() => {
        if (!Pesanan) {
            return '';
        }

        const sisa = KurangiDesimal(Pesanan.Ongkir, Pesanan.OngkirTerpakai);

        return BandingkanDesimal(sisa, '0') > 0 ? sisa.replace(/\.0+$/, '') : '';
    });
    const [catatan, AturCatatan] = useState('');
    const [berkas, AturBerkas] = useState<File[]>([]);
    const [baris, AturBaris] = useState<BarisIsianBebas[]>([]);
    const [isianPesanan, AturIsianPesanan] = useState<Record<number, IsianBarisPesanan>>(() =>
        Object.fromEntries(
            (Pesanan?.Baris ?? []).map((b) => [
                b.IdBarisPesanan,
                { Jumlah: b.Sisa.replace(/\.?0+$/, ''), NomorBatch: '', TanggalKedaluwarsa: '', NomorSeri: [] },
            ]),
        ),
    );
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const judul = belanja ? 'Belanja stok' : Pesanan ? `Terima barang ${Pesanan.Nomor}` : 'Terima barang tanpa pesanan';
    const alamatKembali = Pesanan ? `${AlamatPembelian}/pesanan/${Pesanan.Uuid}` : `${AlamatPembelian}/penerimaan`;

    const UbahPesanan = (id: number, ubah: Partial<IsianBarisPesanan>) =>
        AturIsianPesanan((lama) => ({ ...lama, [id]: { ...(lama[id] as IsianBarisPesanan), ...ubah } }));

    const TanpaJumlah = () =>
        (Pesanan?.Baris ?? []).every((b) => {
            const isian = isianPesanan[b.IdBarisPesanan];

            return isian === undefined || isian.Jumlah === '' || BandingkanDesimal(isian.Jumlah, '0') <= 0;
        });

    const totalBebas = HitungTotalBaris(baris);
    const totalBelanja = JumlahkanDesimal([totalBebas, ongkir === '' ? '0' : ongkir]);

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksa(true);
        let adaGalat: boolean;
        let dataBaris: (MasukanBarisPembelian | MasukanBarisDariPesanan)[];

        if (Pesanan) {
            const dikirim = Pesanan.Baris.filter((b) => {
                const isian = isianPesanan[b.IdBarisPesanan];

                return isian !== undefined && isian.Jumlah !== '' && BandingkanDesimal(isian.Jumlah, '0') > 0;
            });
            adaGalat =
                dikirim.length === 0 ||
                Pesanan.Baris.some((b) => {
                    const isian = isianPesanan[b.IdBarisPesanan];

                    return isian !== undefined && PeriksaBarisDariPesanan(b, isian) !== null;
                });
            dataBaris = dikirim.map((b) => {
                const isian = isianPesanan[b.IdBarisPesanan] as IsianBarisPesanan;

                return {
                    IdBarisPesanan: b.IdBarisPesanan,
                    Jumlah: isian.Jumlah,
                    NomorBatch: b.Pelacakan === 'Batch' ? isian.NomorBatch.trim() : null,
                    TanggalKedaluwarsa:
                        b.Pelacakan === 'Batch' && isian.TanggalKedaluwarsa !== '' ? isian.TanggalKedaluwarsa : null,
                    NomorSeri: b.Pelacakan === 'Seri' ? isian.NomorSeri : [],
                };
            });
        } else {
            adaGalat =
                gudang === '' ||
                (belanja && !bayarNanti && akun === '') ||
                (belanja && bayarNanti && (pemasok === '' || !CekTerminValid(termin))) ||
                baris.length === 0 ||
                baris.some((b) => Object.keys(PeriksaBaris(b, { wajibHarga: true, pelacakan: true })).length > 0);
            dataBaris = baris.map((b) => SusunMasukanBaris(b, true));
        }

        if (adaGalat) {
            return;
        }

        const data = {
            UuidPesananPembelian: Pesanan?.Uuid ?? null,
            UuidPemasok: Pesanan ? null : pemasok === '' ? null : pemasok,
            UuidGudang: Pesanan ? null : gudang,
            UuidAkun: belanja && !bayarNanti ? akun : null,
            ...(belanja ? { BayarNanti: bayarNanti, TerminHari: bayarNanti ? Number(termin) : null } : {}),
            Tanggal: tanggal,
            [belanja ? 'NomorNota' : 'NomorSuratJalan']: nomor === '' ? null : nomor,
            Ongkir: ongkir === '' ? '0' : ongkir,
            Catatan: catatan === '' ? null : catatan,
            Lampiran: berkas[0] ?? null,
            Baris: dataBaris,
        };

        router.post(belanja ? `${AlamatPembelian}/belanja-stok` : `${AlamatPembelian}/penerimaan`, data, {
            preserveScroll: true,
            forceFormData: berkas.length > 0,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
        });
    };

    return (
        <TataLetakAplikasi judul={judul}>
            <p className="max-w-3xl text-isi text-teks-sekunder">
                {belanja
                    ? 'Untuk belanja sehari-hari: sekali simpan mencatat barang masuk dan fakturnya. Bayar sekarang = lunas dari akun kas/bank. Bayar nanti = hutang ke pemasok.'
                    : 'Stok bertambah saat penerimaan disimpan. Harga, diskon, dan ongkos kirim menjadi dasar HPP; PPN masukan yang dapat dikreditkan tidak masuk HPP.'}
            </p>
            <DaftarGalatServer
                galat={galat}
                kecuali={[
                    'UuidPemasok',
                    'UuidGudang',
                    'UuidAkun',
                    'TerminHari',
                    'Tanggal',
                    'NomorSuratJalan',
                    'NomorNota',
                    'Ongkir',
                    'Catatan',
                    'Lampiran',
                ]}
            />
            <form onSubmit={Simpan} noValidate aria-label={judul} className="flex flex-col gap-4">
                <Panel judul="Dokumen" idJudul="judul-dokumen-penerimaan">
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {Pesanan ? (
                            <>
                                <div className="flex flex-col gap-1">
                                    <span className="text-label font-semibold text-teks-sekunder">Lokasi tujuan</span>
                                    <span>{Pesanan.NamaGudang}</span>
                                </div>
                                <div className="flex flex-col gap-1">
                                    <span className="text-label font-semibold text-teks-sekunder">Pemasok</span>
                                    <span>{Pesanan.NamaPemasok}</span>
                                </div>
                            </>
                        ) : (
                            <>
                                <BidangOutlet
                                    label="Lokasi stok"
                                    nilai={gudang}
                                    kosong="Pilih lokasi stok"
                                    opsi={OpsiGudang.filter((g) => g.Aktif).map((g) => ({
                                        Nilai: g.Uuid,
                                        Label: FormatLabelGudang(g),
                                    }))}
                                    saatBerubah={AturGudang}
                                    galat={
                                        galat.UuidGudang ??
                                        (periksa && gudang === '' ? 'Pilih lokasi stok.' : undefined)
                                    }
                                />
                                <BidangPilihan
                                    label={belanja && !bayarNanti ? 'Pemasok (opsional)' : 'Pemasok'}
                                    nilai={pemasok}
                                    kosong={belanja && !bayarNanti ? 'Tanpa pemasok' : 'Pilih pemasok'}
                                    opsi={OpsiPemasok.filter((p) => p.Aktif).map((p) => ({
                                        Nilai: p.Uuid,
                                        Label: p.Nama,
                                        Keterangan: p.Kode,
                                    }))}
                                    saatBerubah={(nilai) => {
                                        AturPemasok(nilai);
                                        const dipilih = OpsiPemasok.find((p) => p.Uuid === nilai);
                                        if (dipilih && dipilih.TerminHari > 0) {
                                            AturTermin(String(dipilih.TerminHari));
                                        }
                                    }}
                                    required={belanja && bayarNanti}
                                    galat={
                                        galat.UuidPemasok ??
                                        (periksa && belanja && bayarNanti && pemasok === ''
                                            ? 'Pilih pemasok untuk belanja yang dibayar nanti.'
                                            : undefined)
                                    }
                                />
                            </>
                        )}
                        {belanja ? (
                            <BidangPilihan
                                label="Pembayaran"
                                nilai={bayarNanti ? 'Nanti' : 'Sekarang'}
                                opsi={[
                                    { Nilai: 'Sekarang', Label: 'Bayar sekarang (lunas)' },
                                    { Nilai: 'Nanti', Label: 'Bayar nanti (tempo)' },
                                ]}
                                saatBerubah={(nilai) => AturBayarNanti(nilai === 'Nanti')}
                            />
                        ) : null}
                        {belanja && !bayarNanti ? (
                            <BidangPilihan
                                label="Dibayar dari akun"
                                nilai={akun}
                                kosong="Pilih akun kas/bank"
                                opsi={OpsiAkun.map((a) => ({ Nilai: a.Uuid, Label: a.Nama, Keterangan: a.Kode }))}
                                saatBerubah={AturAkun}
                                required
                                galat={galat.UuidAkun ?? (periksa && akun === '' ? 'Pilih akun kas/bank.' : undefined)}
                            />
                        ) : null}
                        {belanja && bayarNanti ? (
                            <BidangTeks
                                label="Tempo (hari)"
                                nilai={termin}
                                saatBerubah={(nilai) => AturTermin(nilai.replace(/\D/g, '').slice(0, 3))}
                                inputMode="numeric"
                                required
                                keterangan="Hutang tercatat di halaman Hutang dengan jatuh tempo sejak tanggal terima."
                                galat={
                                    galat.TerminHari ??
                                    (periksa && !CekTerminValid(termin) ? 'Isi tempo 0–365 hari.' : undefined)
                                }
                            />
                        ) : null}
                        <PemilihTanggal
                            id="tanggal-penerimaan"
                            label="Tanggal terima"
                            nilai={tanggal}
                            max={HariIni}
                            required
                            saatBerubah={AturTanggal}
                            galat={galat.Tanggal}
                        />
                        <BidangTeks
                            label={belanja ? 'Nomor nota (opsional)' : 'Nomor surat jalan (opsional)'}
                            nilai={nomor}
                            saatBerubah={AturNomor}
                            galat={galat.NomorSuratJalan ?? galat.NomorNota}
                            maxLength={60}
                            kode
                        />
                        <BidangUang
                            label="Ongkos kirim (opsional)"
                            nilai={ongkir}
                            saatBerubah={AturOngkir}
                            keterangan={
                                Pesanan
                                    ? `Ongkir pesanan ${FormatRupiah(Pesanan.Ongkir)}, sudah terpakai ${FormatRupiah(Pesanan.OngkirTerpakai)}.`
                                    : 'Dibagi ke barang sebanding nilainya.'
                            }
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
                        label={belanja ? 'Lampiran nota (opsional)' : 'Lampiran surat jalan (opsional)'}
                        berkas={berkas}
                        saatBerubah={AturBerkas}
                        ekstensi={Lampiran.Ekstensi}
                        maksimal={1}
                        ukuranMaksimalKb={Lampiran.UkuranMaksimalKb}
                        galat={galat.Lampiran}
                    />
                </Panel>

                <Panel
                    judul="Barang diterima"
                    idJudul="judul-barang-penerimaan"
                    keterangan={
                        Pesanan
                            ? 'Isi jumlah yang benar-benar diterima (boleh sebagian). Kosongkan atau 0 bila baris belum datang.'
                            : undefined
                    }
                >
                    {Pesanan ? (
                        <ul className="flex flex-col gap-3">
                            {Pesanan.Baris.map((b, i) => {
                                const isian = isianPesanan[b.IdBarisPesanan] as IsianBarisPesanan;
                                const galatLokal = periksa ? PeriksaBarisDariPesanan(b, isian) : null;
                                const galatBaris =
                                    galat[`Baris.${String(i)}.Jumlah`] ??
                                    galat[`Baris.${String(i)}.NomorBatch`] ??
                                    galat[`Baris.${String(i)}.NomorSeri`] ??
                                    galatLokal ??
                                    undefined;

                                return (
                                    <li
                                        key={b.IdBarisPesanan}
                                        className="flex flex-col gap-3 rounded-panel border border-garis p-3"
                                    >
                                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                                            <span className="flex flex-col">
                                                <span className="font-semibold break-words">{b.NamaProduk}</span>
                                                <span className="font-mono text-keterangan text-teks-sekunder">
                                                    {b.Sku ?? 'Tanpa SKU'}
                                                </span>
                                            </span>
                                            <span className="text-keterangan text-teks-sekunder">
                                                Dipesan {FormatJumlahStok(b.Jumlah, b.SimbolSatuan)} | sudah diterima{' '}
                                                {FormatJumlahStok(b.JumlahDiterima, b.SimbolSatuan)} | sisa{' '}
                                                {FormatJumlahStok(b.Sisa, b.SimbolSatuan)}
                                            </span>
                                        </div>
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <BidangJumlah
                                                label={`Diterima ${b.NamaProduk}`}
                                                nilai={isian.Jumlah}
                                                saatBerubah={(nilai) =>
                                                    UbahPesanan(b.IdBarisPesanan, { Jumlah: nilai })
                                                }
                                                akhiran={b.SimbolSatuan}
                                                galat={galatBaris}
                                            />
                                            {b.Pelacakan === 'Batch' ? (
                                                <>
                                                    <BidangTeks
                                                        label={`Nomor batch ${b.NamaProduk}`}
                                                        nilai={isian.NomorBatch}
                                                        saatBerubah={(nilai) =>
                                                            UbahPesanan(b.IdBarisPesanan, { NomorBatch: nilai })
                                                        }
                                                        maxLength={60}
                                                        kode
                                                        required={
                                                            isian.Jumlah !== '' &&
                                                            BandingkanDesimal(isian.Jumlah, '0') > 0
                                                        }
                                                    />
                                                    <PemilihTanggal
                                                        label={`Kedaluwarsa ${b.NamaProduk} (opsional)`}
                                                        nilai={isian.TanggalKedaluwarsa}
                                                        saatBerubah={(nilai) =>
                                                            UbahPesanan(b.IdBarisPesanan, { TanggalKedaluwarsa: nilai })
                                                        }
                                                    />
                                                </>
                                            ) : null}
                                        </div>
                                        {b.Pelacakan === 'Seri' ? (
                                            <BidangNomorSeri
                                                label={`Nomor seri ${b.NamaProduk}`}
                                                nilai={isian.NomorSeri}
                                                saatBerubah={(nomorSeri) =>
                                                    UbahPesanan(b.IdBarisPesanan, { NomorSeri: nomorSeri })
                                                }
                                                maksimal={1000}
                                            />
                                        ) : null}
                                    </li>
                                );
                            })}
                        </ul>
                    ) : (
                        <>
                            <IsianBarisPembelian
                                judul="Barang diterima"
                                baris={baris}
                                saatBerubah={AturBaris}
                                uuidGudang={gudang === '' ? null : gudang}
                                uuidPemasok={pemasok === '' ? null : pemasok}
                                pelacakan
                                periksa={periksa}
                                galatServer={galat}
                                maksimal={MaksimalBaris}
                            />
                            {periksa && baris.length === 0 ? (
                                <p className="text-keterangan font-semibold text-bahaya">
                                    Tambahkan minimal satu produk.
                                </p>
                            ) : null}
                            <dl className="ml-auto flex w-full max-w-md flex-col gap-1 border-t border-garis pt-3">
                                <div className="flex justify-between gap-3">
                                    <dt className="font-semibold">
                                        {belanja ? 'Total dibayar (sebelum PPN)' : 'Perkiraan nilai barang + ongkir'}
                                    </dt>
                                    <dd className="font-semibold tabular-nums">{FormatRupiah(totalBelanja)}</dd>
                                </div>
                            </dl>
                        </>
                    )}
                    {Pesanan && (galat.Baris || (periksa && TanpaJumlah())) ? (
                        <p className="text-keterangan font-semibold text-bahaya">
                            {galat.Baris ?? 'Isi jumlah diterima minimal satu baris.'}
                        </p>
                    ) : null}
                </Panel>

                <BilahAksiForm>
                    <Tombol type="submit" memproses={memproses}>
                        {belanja ? 'Simpan belanja stok' : 'Simpan penerimaan'}
                    </Tombol>
                    <Button asChild variant="outline">
                        <Link href={alamatKembali}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
