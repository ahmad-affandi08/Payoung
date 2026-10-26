import { Link, router, usePage } from '@inertiajs/react';
import { Trash2Icon } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import { GalatBidang } from '@/Komponen/Formulir/BagianBidang';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import PemilihProdukStok, { type ProdukStokTerpilih } from '@/Komponen/Persediaan/PemilihProdukStok';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok, FormatLabelGudang } from '@/Pustaka/FormatPersediaan';
import { AmbilTandaDesimal, BandingkanDesimal, CekDesimalValid, HitungTotalNilai } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsFormKonsinyasi } from '@/Tipe/Pembelian';

import { AlamatKonsinyasi } from './Daftar';

type BarisForm = {
    Kunci: string;
    UuidProduk: string;
    NamaProduk: string;
    Sku: string | null;
    SimbolSatuan: string;
    BolehDesimal: boolean;
    SaldoDiGudang: string | null;
    Jumlah: string;
    HargaTitip: string;
};

let nomorKunci = 0;

/** Galat lokal satu baris titipan (server memeriksa ulang). */
export function PeriksaBarisKonsinyasi(b: BarisForm, masuk: boolean): string | null {
    if (!CekDesimalValid(b.Jumlah) || AmbilTandaDesimal(b.Jumlah) <= 0 || (!b.BolehDesimal && b.Jumlah.includes('.'))) {
        return 'Isi jumlah lebih dari 0.';
    }

    if (!masuk && b.SaldoDiGudang !== null && BandingkanDesimal(b.Jumlah, b.SaldoDiGudang) > 0) {
        return `Stok di lokasi ini tinggal ${FormatJumlahStok(b.SaldoDiGudang, b.SimbolSatuan)}.`;
    }

    if (masuk && (!CekDesimalValid(b.HargaTitip) || AmbilTandaDesimal(b.HargaTitip) <= 0)) {
        return 'Isi harga titip lebih dari 0.';
    }

    return null;
}

/**
 * F-05i: catat titipan masuk dari penitip (dinilai harga titip) atau retur sisa titipan ke penitip. Langsung dicatat
 * ke stok, tanpa jurnal. Produk wajib berjenis Konsinyasi; satu produk hanya untuk satu penitip.
 */
export default function HalamanFormKonsinyasi({
    Jenis,
    LabelJenis,
    UuidPemasokAwal,
    OpsiPemasok,
    OpsiGudang,
    HariIni,
    MaksBaris,
}: PropsFormKonsinyasi) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galatServer = props.errors;
    const masuk = Jenis === 'Masuk';
    const gudangAktif = OpsiGudang.filter((g) => g.Aktif);
    const [gudang, AturGudang] = useState(gudangAktif.length === 1 ? (gudangAktif[0]?.Uuid ?? '') : '');
    const [pemasok, AturPemasok] = useState(UuidPemasokAwal ?? '');
    const [tanggal, AturTanggal] = useState(HariIni);
    const [catatan, AturCatatan] = useState('');
    const [daftar, AturDaftar] = useState<BarisForm[]>([]);
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const judul = masuk ? 'Catat titipan masuk' : 'Retur ke penitip';
    const total = HitungTotalNilai(daftar.map((b) => ({ Jumlah: b.Jumlah, HppSatuan: b.HargaTitip })));

    const Ubah = (kunci: string, perubahan: Partial<BarisForm>) =>
        AturDaftar((lama) => lama.map((b) => (b.Kunci === kunci ? { ...b, ...perubahan } : b)));

    const Tambah = (p: ProdukStokTerpilih) => {
        nomorKunci += 1;
        AturDaftar((lama) => [
            ...lama,
            {
                Kunci: `ks-${String(nomorKunci)}`,
                UuidProduk: p.Uuid,
                NamaProduk: p.Nama,
                Sku: p.Sku,
                SimbolSatuan: p.SimbolSatuan,
                BolehDesimal: p.BolehDesimal,
                SaldoDiGudang: p.SaldoDiGudang,
                Jumlah: '',
                HargaTitip: p.HppRataRata !== null && masuk ? p.HppRataRata.replace(/(\.\d{2})\d*$/, '$1') : '',
            },
        ]);
    };

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksa(true);

        if (
            gudang === '' ||
            pemasok === '' ||
            daftar.length === 0 ||
            daftar.some((b) => PeriksaBarisKonsinyasi(b, masuk) !== null)
        ) {
            return;
        }

        router.post(
            AlamatKonsinyasi,
            {
                Jenis,
                UuidPemasok: pemasok,
                UuidGudang: gudang,
                Tanggal: tanggal,
                Catatan: catatan.trim() === '' ? null : catatan.trim(),
                Baris: daftar.map((b) => ({
                    UuidProduk: b.UuidProduk,
                    Jumlah: b.Jumlah,
                    HargaTitip: masuk ? b.HargaTitip : null,
                })),
            },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );
    };

    return (
        <TataLetakAplikasi judul={judul} jejak={[{ label: 'Konsinyasi', href: AlamatKonsinyasi }]}>
            <DaftarGalatServer
                galat={galatServer}
                kecuali={['UuidGudang', 'UuidPemasok', 'Tanggal', 'Catatan', 'Baris']}
            />
            <form onSubmit={Simpan} noValidate aria-label={judul} className="flex flex-col gap-4">
                <Panel
                    judul="Dokumen"
                    idJudul="judul-dokumen-konsinyasi"
                    keterangan={
                        masuk
                            ? 'Stok titipan bertambah saat disimpan. Tidak dijurnal: hutang ke penitip baru muncul saat barangnya terjual.'
                            : 'Stok titipan berkurang saat disimpan, dinilai harga rata-rata titipan. Tidak dijurnal.'
                    }
                >
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <BidangOutlet
                            label="Lokasi stok"
                            nilai={gudang}
                            kosong="Pilih lokasi stok"
                            opsi={gudangAktif.map((g) => ({ Nilai: g.Uuid, Label: FormatLabelGudang(g) }))}
                            saatBerubah={(nilai) => {
                                AturGudang(nilai);
                                AturDaftar((lama) => lama.map((b) => ({ ...b, SaldoDiGudang: null })));
                            }}
                            galat={
                                galatServer.UuidGudang ?? (periksa && gudang === '' ? 'Pilih lokasi stok.' : undefined)
                            }
                        />
                        <BidangPilihan
                            label="Penitip"
                            nilai={pemasok}
                            kosong="Pilih penitip"
                            opsi={OpsiPemasok.filter((p) => p.Aktif || p.Uuid === pemasok).map((p) => ({
                                Nilai: p.Uuid,
                                Label: `${p.Nama} (${p.Kode})`,
                            }))}
                            saatBerubah={AturPemasok}
                            required
                            galat={
                                galatServer.UuidPemasok ?? (periksa && pemasok === '' ? 'Pilih penitip.' : undefined)
                            }
                        />
                        <PemilihTanggal
                            id="tanggal-konsinyasi"
                            label="Tanggal"
                            nilai={tanggal}
                            max={HariIni}
                            required
                            saatBerubah={AturTanggal}
                            galat={galatServer.Tanggal}
                        />
                    </div>
                    <BidangTeksPanjang
                        label="Catatan (opsional)"
                        nilai={catatan}
                        saatBerubah={AturCatatan}
                        galat={galatServer.Catatan}
                        baris={2}
                        maksimal={500}
                    />
                </Panel>

                <Panel
                    judul={`Barang (${LabelJenis.toLowerCase()})`}
                    idJudul="judul-barang-konsinyasi"
                    keterangan={`${daftar.length.toLocaleString('id-ID')} dari ${MaksBaris.toLocaleString('id-ID')} baris${masuk ? ` | total ${FormatRupiah(total)}` : ''}`}
                >
                    <PemilihProdukStok
                        label="Tambah produk titipan"
                        uuidGudang={gudang === '' ? null : gudang}
                        saatPilih={Tambah}
                        kecuali={daftar.map((b) => b.UuidProduk)}
                        disabled={gudang === '' || daftar.length >= MaksBaris}
                        buatUrl={(kata, uuidGudang) => {
                            const parameter = new URLSearchParams({ kata, batas: '20' });

                            if (uuidGudang !== null) {
                                parameter.set('gudang', uuidGudang);
                            }

                            return `${AlamatKonsinyasi}/produk/cari?${parameter.toString()}`;
                        }}
                        keterangan={
                            gudang === ''
                                ? 'Pilih lokasi stok dulu.'
                                : 'Hanya produk berjenis Konsinyasi. Ubah jenis produk di Katalog bila belum ada.'
                        }
                    />
                    {galatServer.Baris ? (
                        <GalatBidang>{galatServer.Baris}</GalatBidang>
                    ) : periksa && daftar.length === 0 ? (
                        <GalatBidang>Tambahkan minimal satu barang titipan.</GalatBidang>
                    ) : null}
                    <ul className="flex flex-col divide-y divide-garis" aria-label="Barang titipan">
                        {daftar.map((b, indeks) => {
                            const galat =
                                galatServer[`Baris.${String(indeks)}.UuidProduk`] ??
                                galatServer[`Baris.${String(indeks)}.Jumlah`] ??
                                galatServer[`Baris.${String(indeks)}.HargaTitip`] ??
                                (periksa ? (PeriksaBarisKonsinyasi(b, masuk) ?? undefined) : undefined);

                            return (
                                <li key={b.Kunci} className="flex flex-col gap-2 py-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <span className="block font-semibold break-words text-teks-utama">
                                                {b.NamaProduk}
                                            </span>
                                            <span className="block text-keterangan text-teks-sekunder">
                                                <span className="font-mono">{b.Sku ?? 'Tanpa SKU'}</span>
                                                {b.SaldoDiGudang !== null
                                                    ? ` | stok ${FormatJumlahStok(b.SaldoDiGudang, b.SimbolSatuan)}`
                                                    : null}
                                            </span>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={`Hapus baris ${b.NamaProduk}`}
                                            onClick={() =>
                                                AturDaftar((lama) => lama.filter((x) => x.Kunci !== b.Kunci))
                                            }
                                        >
                                            <Trash2Icon aria-hidden="true" />
                                        </Button>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                        <BidangJumlah
                                            label={`Jumlah ${b.NamaProduk}`}
                                            nilai={b.Jumlah}
                                            saatBerubah={(jumlah) => Ubah(b.Kunci, { Jumlah: jumlah })}
                                            desimal={b.BolehDesimal ? 4 : 0}
                                            akhiran={b.SimbolSatuan}
                                            required
                                        />
                                        {masuk ? (
                                            <BidangUang
                                                label={`Harga titip per ${b.SimbolSatuan}`}
                                                nilai={b.HargaTitip}
                                                saatBerubah={(harga) => Ubah(b.Kunci, { HargaTitip: harga })}
                                                keterangan="Yang dibayar ke penitip untuk tiap barang yang laku."
                                                required
                                            />
                                        ) : null}
                                    </div>
                                    {galat ? <GalatBidang>{galat}</GalatBidang> : null}
                                </li>
                            );
                        })}
                    </ul>
                </Panel>

                <BilahAksiForm>
                    <Tombol type="submit" memproses={memproses}>
                        {masuk ? 'Simpan titipan masuk' : 'Simpan retur ke penitip'}
                    </Tombol>
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={AlamatKonsinyasi}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
