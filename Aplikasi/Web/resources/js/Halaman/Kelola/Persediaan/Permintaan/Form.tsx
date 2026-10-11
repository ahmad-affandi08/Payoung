import { Link, router, usePage } from '@inertiajs/react';
import { Trash2Icon } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import { GalatBidang } from '@/Komponen/Formulir/BagianBidang';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import PemilihProdukStok, { type ProdukStokTerpilih } from '@/Komponen/Persediaan/PemilihProdukStok';
import { BuatUlid } from '@/Komponen/Persediaan/UlidKlien';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import { FormatLabelGudang } from '@/Pustaka/FormatPersediaan';
import { AmbilTandaDesimal, CekDesimalValid } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisFormPermintaanStok, PropsFormPermintaanStok } from '@/Tipe/DokumenPersediaan';

const alamat = '/kelola/persediaan/permintaan';

type BarisForm = BarisFormPermintaanStok & { Kunci: string };

let nomorKunci = 0;

function BuatKunci(): string {
    nomorKunci += 1;

    return `permintaan-${String(nomorKunci)}`;
}

/** Galat lokal satu baris permintaan (server tetap memeriksa ulang). */
export function PeriksaBarisPermintaan(baris: BarisFormPermintaanStok): string | null {
    if (!CekDesimalValid(baris.Jumlah) || AmbilTandaDesimal(baris.Jumlah) <= 0) {
        return 'Isi jumlah lebih dari 0.';
    }

    return !baris.BolehDesimal && baris.Jumlah.includes('.') ? 'Jumlah harus bilangan bulat.' : null;
}

/** F-05b, D-105: form mengajukan permintaan stok dari lokasi yang butuh barang ke lokasi pengirim. */
export default function HalamanFormPermintaanStok({
    OpsiGudangTujuan,
    OpsiGudangAsal,
    UuidGudangTujuanAwal,
    HariIni,
    BatasBaris,
}: PropsFormPermintaanStok) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galatServer = props.errors;
    const [uuidBaru] = useState(() => BuatUlid());
    const [tujuan, AturTujuan] = useState(UuidGudangTujuanAwal);
    const [asal, AturAsal] = useState('');
    const [tanggal, AturTanggal] = useState(HariIni);
    const [catatan, AturCatatan] = useState('');
    const [daftar, AturDaftar] = useState<BarisForm[]>([]);
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const galatTujuan =
        galatServer.UuidGudangTujuan ?? (periksa && tujuan === '' ? 'Pilih lokasi yang meminta.' : undefined);
    const galatAsal =
        galatServer.UuidGudangAsal ??
        (periksa && asal === ''
            ? 'Pilih lokasi pengirim.'
            : periksa && asal === tujuan
              ? 'Lokasi pengirim harus berbeda dari lokasi yang meminta.'
              : undefined);
    const galatDaftar =
        galatServer.Baris ?? (periksa && daftar.length === 0 ? 'Tambahkan minimal satu produk.' : undefined);

    const Ubah = (kunci: string, jumlah: string) =>
        AturDaftar((lama) => lama.map((b) => (b.Kunci === kunci ? { ...b, Jumlah: jumlah } : b)));

    const Tambah = (p: ProdukStokTerpilih) =>
        AturDaftar((lama) => [
            ...lama,
            {
                Kunci: BuatKunci(),
                UuidProduk: p.Uuid,
                NamaProduk: p.Nama,
                Sku: p.Sku,
                SimbolSatuan: p.SimbolSatuan,
                BolehDesimal: p.BolehDesimal,
                Pelacakan: p.Pelacakan,
                Jumlah: '',
            },
        ]);

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksa(true);

        if (
            tujuan === '' ||
            asal === '' ||
            asal === tujuan ||
            daftar.length === 0 ||
            daftar.some((b) => PeriksaBarisPermintaan(b) !== null)
        ) {
            return;
        }

        router.post(
            alamat,
            {
                Uuid: uuidBaru,
                UuidGudangAsal: asal,
                UuidGudangTujuan: tujuan,
                Tanggal: tanggal,
                Catatan: catatan.trim() === '' ? null : catatan.trim(),
                Baris: daftar.map((b) => ({ UuidProduk: b.UuidProduk, Jumlah: b.Jumlah })),
            },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );
    };

    return (
        <TataLetakAplikasi judul="Minta stok">
            <DaftarGalatServer
                galat={galatServer}
                kecuali={['UuidGudangAsal', 'UuidGudangTujuan', 'Tanggal', 'Catatan', 'Baris']}
            />
            <form onSubmit={Simpan} noValidate aria-label="Minta stok" className="flex flex-col gap-4">
                <Panel
                    judul="Permintaan"
                    idJudul="judul-dokumen-permintaan"
                    keterangan="Stok tidak berubah saat permintaan diajukan; barang berpindah saat pengirim mengirim transfer."
                >
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <BidangOutlet
                            label="Barang dikirim ke"
                            nilai={tujuan}
                            kosong="Pilih lokasi Anda"
                            opsi={OpsiGudangTujuan.filter((g) => g.Aktif).map((g) => ({
                                Nilai: g.Uuid,
                                Label: FormatLabelGudang(g),
                            }))}
                            saatBerubah={AturTujuan}
                            galat={galatTujuan}
                        />
                        <BidangOutlet
                            label="Diminta dari"
                            nilai={asal}
                            kosong="Pilih gudang atau outlet pengirim"
                            opsi={OpsiGudangAsal.filter((g) => g.Aktif && g.Uuid !== tujuan).map((g) => ({
                                Nilai: g.Uuid,
                                Label: FormatLabelGudang(g),
                            }))}
                            saatBerubah={AturAsal}
                            galat={galatAsal}
                        />
                        <PemilihTanggal
                            id="tanggal-permintaan"
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
                    judul="Barang yang diminta"
                    idJudul="judul-barang-permintaan"
                    keterangan={`${daftar.length.toLocaleString('id-ID')} dari ${BatasBaris.toLocaleString('id-ID')} baris`}
                >
                    <PemilihProdukStok
                        label="Tambah produk"
                        uuidGudang={tujuan === '' ? null : tujuan}
                        saatPilih={Tambah}
                        kecuali={daftar.map((b) => b.UuidProduk)}
                        disabled={tujuan === '' || daftar.length >= BatasBaris}
                        keterangan={
                            tujuan === ''
                                ? 'Pilih lokasi Anda dulu agar stok saat ini ikut tampil.'
                                : 'Stok yang tampil adalah stok di lokasi Anda. Minta dalam satuan dasar produk.'
                        }
                    />
                    {galatDaftar ? <GalatBidang>{galatDaftar}</GalatBidang> : null}
                    {daftar.length === 0 ? (
                        <p className="rounded-kontrol border border-dashed border-garis-input px-3 py-2 text-isi text-teks-sekunder">
                            Belum ada barang. Cari produk di atas untuk menambah baris.
                        </p>
                    ) : (
                        <ul className="flex flex-col divide-y divide-garis" aria-label="Barang yang diminta">
                            {daftar.map((b, indeks) => {
                                const galat =
                                    galatServer[`Baris.${String(indeks)}.Jumlah`] ??
                                    (periksa ? (PeriksaBarisPermintaan(b) ?? undefined) : undefined);

                                return (
                                    <li
                                        key={b.Kunci}
                                        className="grid gap-3 py-3 sm:grid-cols-[minmax(0,1fr)_12rem_auto] sm:items-start"
                                    >
                                        <div className="min-w-0">
                                            <span className="block font-semibold break-words text-teks-utama">
                                                {b.NamaProduk}
                                            </span>
                                            <span className="block font-mono text-keterangan text-teks-sekunder">
                                                {b.Sku ?? 'Tanpa SKU'}
                                            </span>
                                        </div>
                                        <BidangJumlah
                                            label={`Jumlah ${b.NamaProduk}`}
                                            labelTersembunyi
                                            nilai={b.Jumlah}
                                            saatBerubah={(jumlah) => Ubah(b.Kunci, jumlah)}
                                            desimal={b.BolehDesimal ? 4 : 0}
                                            akhiran={b.SimbolSatuan}
                                            galat={galat}
                                            required
                                        />
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
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Panel>

                <BilahAksiForm>
                    <Tombol type="submit" memproses={memproses}>
                        Ajukan permintaan
                    </Tombol>
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={alamat}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
