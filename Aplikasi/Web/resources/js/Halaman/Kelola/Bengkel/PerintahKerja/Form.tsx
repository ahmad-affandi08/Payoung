import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import DialogKendaraan from '@/Komponen/Bengkel/DialogKendaraan';
import IsianBarisBengkel, {
    BuatKunciBarisBengkel,
    PecahNomorSeri,
    type BarisIsianBengkel,
} from '@/Komponen/Bengkel/IsianBarisBengkel';
import { AlamatPerintahKerja } from '@/Komponen/Bengkel/KolomPerintahKerja';
import PemilihCariBengkel from '@/Komponen/Bengkel/PemilihCariBengkel';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import PemilihTanggalWaktu from '@/Komponen/Tanggal/PemilihTanggalWaktu';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import { JudulUnit, KataUnit, PakaiJenisUnitBawaan, RincianUnit } from '@/Pustaka/UnitServis';
import type { PropsFormPerintahKerja, RingkasKendaraan } from '@/Tipe/Bengkel';

const MAKSIMAL_BARIS = 100;

type PelangganPilihan = { Uuid: string; Nama: string; NoHp: string | null };
type KendaraanPilihan = RingkasKendaraan & {
    KmTerakhir: number | null;
    Pelanggan: { Uuid: string | null; Nama: string };
};

/**
 * Formulir perintah kerja bengkel (§9.10): outlet, pelanggan & kendaraannya, KM masuk, keluhan, diagnosis, perkiraan
 * selesai, lalu jasa (dengan mekanik) dan sparepart. **Harga tidak diisi di sini**: server mengambilnya dari daftar
 * harga (termasuk harga tier pelanggan) saat disimpan, lalu menampilkannya di halaman detail sebelum persetujuan
 * pelanggan diminta.
 */
export default function HalamanFormPerintahKerja({ Isian, Awal, OpsiOutlet, OpsiMekanik }: PropsFormPerintahKerja) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [uuidOutlet, AturOutlet] = useState(
        Isian?.UuidOutlet ?? (OpsiOutlet.length === 1 ? (OpsiOutlet[0]?.Uuid ?? '') : ''),
    );
    const [pelanggan, AturPelanggan] = useState<{ Uuid: string; Nama: string } | null>(() => {
        const uuid = Isian?.UuidPelanggan ?? Awal?.UuidPelanggan ?? null;

        return uuid === null ? null : { Uuid: uuid, Nama: Isian?.NamaPelanggan ?? Awal?.NamaPelanggan ?? '' };
    });
    const [kendaraan, AturKendaraan] = useState<(RingkasKendaraan & { KmTerakhir: number | null }) | null>(
        Isian?.Kendaraan ?? Awal?.Kendaraan ?? null,
    );
    const bawaan = PakaiJenisUnitBawaan();
    const kata = kendaraan === null ? (bawaan === null ? 'Unit servis' : KataUnit(bawaan)) : KataUnit(kendaraan.Jenis);
    const tampilKm = kendaraan === null ? bawaan !== 'Perangkat' : kendaraan.Jenis === 'Kendaraan';
    const [kmMasuk, AturKmMasuk] = useState(Isian?.KmMasuk === null || Isian === null ? '' : String(Isian.KmMasuk));
    const [keluhan, AturKeluhan] = useState(Isian?.Keluhan ?? '');
    const [diagnosis, AturDiagnosis] = useState(Isian?.Diagnosis ?? '');
    const [estimasi, AturEstimasi] = useState(Isian?.EstimasiSelesaiPada ?? '');
    const [baris, AturBaris] = useState<BarisIsianBengkel[]>(() =>
        (Isian?.Baris ?? []).map((b) => ({
            ...b,
            Kunci: BuatKunciBarisBengkel(),
            TeksNomorSeri: b.NomorSeri.join('\n'),
            Jumlah: b.Jumlah.replace(/\.0+$/, ''),
            Diskon: b.Diskon === '0.00' ? '' : b.Diskon,
        })),
    );
    const [dialogKendaraan, AturDialogKendaraan] = useState(false);
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const ubah = Isian !== null;
    const judul = ubah ? `Ubah perintah kerja ${Isian.Nomor}` : 'Buat perintah kerja';

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksa(true);

        const isi = {
            UuidOutlet: uuidOutlet,
            UuidPelanggan: pelanggan?.Uuid ?? '',
            UuidKendaraan: kendaraan?.Uuid ?? '',
            KmMasuk: !tampilKm || kmMasuk === '' ? null : Number(kmMasuk),
            Keluhan: keluhan,
            Diagnosis: diagnosis === '' ? null : diagnosis,
            EstimasiSelesaiPada: estimasi === '' ? null : estimasi,
            Baris: baris
                .filter((b) => Number(b.Jumlah) > 0)
                .map((b) => ({
                    Jenis: b.Jenis,
                    UuidProduk: b.UuidProduk,
                    UuidProdukSatuan: b.UuidProdukSatuan,
                    Jumlah: b.Jumlah,
                    Diskon: b.Diskon === '' ? null : b.Diskon,
                    UuidKaryawan: b.Jenis === 'Jasa' ? b.UuidKaryawan : null,
                    Catatan: b.Catatan,
                    NomorSeri: b.Pelacakan === 'Seri' ? PecahNomorSeri(b.TeksNomorSeri) : [],
                })),
        };
        const pilihan = { onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) };

        if (ubah) {
            router.put(`${AlamatPerintahKerja}/${Isian.Uuid}`, isi, pilihan);

            return;
        }

        router.post(AlamatPerintahKerja, isi, pilihan);
    };

    const siap = uuidOutlet !== '' && pelanggan !== null && kendaraan !== null && keluhan.trim() !== '';

    return (
        <TataLetakAplikasi judul={judul} jejak={[{ label: 'Perintah kerja', href: AlamatPerintahKerja }]}>
            <DaftarGalatServer galat={galat} />
            <Pemberitahuan jenis="info" judul="Harga diambil dari daftar harga">
                Harga jasa & sparepart mengikuti daftar harga (termasuk harga tier pelanggan). Simpan dulu, lalu periksa
                estimasinya di halaman perintah kerja sebelum meminta persetujuan pelanggan. Stok sparepart baru
                berkurang saat ditagih di kasir.
            </Pemberitahuan>

            <form onSubmit={Kirim} noValidate className="flex flex-col gap-4" aria-label={judul}>
                <Panel judul={`${kata} & keluhan`}>
                    <div className="flex flex-col gap-4">
                        <div className="max-w-sm">
                            <BidangOutlet
                                label="Outlet bengkel"
                                nilai={uuidOutlet}
                                opsi={OpsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                                saatBerubah={AturOutlet}
                                galat={galat.UuidOutlet}
                            />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <PemilihCariBengkel<PelangganPilihan>
                                sumber="pelanggan"
                                label="Pelanggan"
                                placeholder="Pilih pelanggan"
                                buatUrl={(kata) =>
                                    `/kelola/bengkel/pelanggan/cari?${new URLSearchParams({ kata }).toString()}`
                                }
                                ambilId={(p) => p.Uuid}
                                ambilJudul={(p) => p.Nama}
                                ambilKeterangan={(p) => p.NoHp}
                                saatPilih={(p) => {
                                    AturPelanggan({ Uuid: p.Uuid, Nama: p.Nama });

                                    if (pelanggan?.Uuid !== p.Uuid) {
                                        AturKendaraan(null);
                                    }
                                }}
                                nilaiTerpilih={pelanggan?.Nama}
                                pesanKosong="Belum ada pelanggan aktif. Tambahkan dulu di Pelanggan."
                                galat={galat.UuidPelanggan}
                                wajib
                            />
                            <div className="flex flex-col gap-2">
                                <PemilihCariBengkel<KendaraanPilihan>
                                    sumber="kendaraan"
                                    label={kata}
                                    placeholder={`Pilih ${kata.toLowerCase()}`}
                                    buatUrl={(kata) =>
                                        `/kelola/bengkel/kendaraan/cari?${new URLSearchParams({ kata, ...(pelanggan ? { pelanggan: pelanggan.Uuid } : {}) }).toString()}`
                                    }
                                    ambilId={(k) => k.Uuid}
                                    ambilJudul={(k) => JudulUnit(k)}
                                    ambilKeterangan={(k) => `${RincianUnit(k)} | ${k.Pelanggan.Nama}`}
                                    saatPilih={(k) => {
                                        AturKendaraan(k);

                                        if (k.Pelanggan.Uuid !== null) {
                                            AturPelanggan({ Uuid: k.Pelanggan.Uuid, Nama: k.Pelanggan.Nama });
                                        }
                                    }}
                                    nilaiTerpilih={
                                        kendaraan === null
                                            ? undefined
                                            : `${JudulUnit(kendaraan)} | ${RincianUnit(kendaraan)}`
                                    }
                                    pesanKosong={
                                        pelanggan === null
                                            ? `Belum ada ${kata.toLowerCase()} terdaftar.`
                                            : `${pelanggan.Nama} belum punya ${kata.toLowerCase()} terdaftar. Tambahkan di bawah.`
                                    }
                                    galat={galat.UuidKendaraan}
                                    wajib
                                />
                                <Button
                                    type="button"
                                    variant="link"
                                    className="self-start px-0"
                                    disabled={pelanggan === null}
                                    onClick={() => AturDialogKendaraan(true)}
                                >
                                    Tambah {kata.toLowerCase()} pelanggan ini
                                </Button>
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {tampilKm ? (
                                <BidangJumlah
                                    label="KM masuk"
                                    nilai={kmMasuk}
                                    saatBerubah={AturKmMasuk}
                                    galat={galat.KmMasuk}
                                    desimal={0}
                                    digitBulat={7}
                                    akhiran="km"
                                    keterangan={
                                        kendaraan?.KmTerakhir === null || kendaraan === null
                                            ? undefined
                                            : `KM terakhir tercatat ${kendaraan.KmTerakhir.toLocaleString('id-ID')} km`
                                    }
                                />
                            ) : null}
                            <PemilihTanggalWaktu
                                label="Perkiraan selesai"
                                nilai={estimasi}
                                saatBerubah={AturEstimasi}
                                galat={galat.EstimasiSelesaiPada}
                                jamBawaan="16:00"
                            />
                        </div>
                        <BidangTeksPanjang
                            label="Keluhan pelanggan"
                            nilai={keluhan}
                            saatBerubah={AturKeluhan}
                            galat={galat.Keluhan}
                            maksimal={2000}
                            required
                        />
                        <BidangTeksPanjang
                            label="Diagnosis mekanik"
                            nilai={diagnosis}
                            saatBerubah={AturDiagnosis}
                            galat={galat.Diagnosis}
                            maksimal={2000}
                        />
                    </div>
                </Panel>

                <Panel judul="Estimasi jasa & sparepart">
                    <IsianBarisBengkel
                        baris={baris}
                        saatBerubah={AturBaris}
                        uuidOutlet={uuidOutlet}
                        opsiMekanik={OpsiMekanik}
                        periksa={periksa}
                        galatServer={galat}
                        maksimal={MAKSIMAL_BARIS}
                    />
                </Panel>

                <BilahAksiForm>
                    <Tombol type="submit" memproses={memproses} disabled={!siap}>
                        Simpan perintah kerja
                    </Tombol>
                    <Button asChild variant="outline" type="button">
                        <Link href={ubah ? `${AlamatPerintahKerja}/${Isian.Uuid}` : AlamatPerintahKerja}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>

            {dialogKendaraan && pelanggan !== null ? (
                <DialogKendaraan kendaraan={null} pelanggan={pelanggan} saatTutup={() => AturDialogKendaraan(false)} />
            ) : null}
        </TataLetakAplikasi>
    );
}
