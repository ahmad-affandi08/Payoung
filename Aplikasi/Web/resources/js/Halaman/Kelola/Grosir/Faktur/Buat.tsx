import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import Tombol from '@/Komponen/Formulir/Tombol';
import { AlamatGrosir, HalamanGrosir } from '@/Komponen/Grosir/BagianDokumenGrosir';
import Panel from '@/Komponen/Kelola/Panel';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import { Checkbox } from '@/Komponen/Ui/checkbox';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { JumlahkanDesimal } from '@/Pustaka/HitungDesimal';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisDaftarSuratJalan, PropsBuatFaktur } from '@/Tipe/Grosir';

const alamat = `${AlamatGrosir}/faktur`;

type Kelompok = { Kunci: string; Judul: string; Baris: BarisDaftarSuratJalan[] };

const NAMA_BULAN = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

/** Satu faktur = satu pelanggan, satu outlet, satu bulan penyerahan (BR-12.4): daftar dikelompokkan dengan aturan yang sama. */
export function KelompokkanSuratJalan(baris: BarisDaftarSuratJalan[]): Kelompok[] {
    const peta = new Map<string, Kelompok>();

    for (const sj of baris) {
        const bulan = sj.Tanggal.slice(0, 7);
        const kunci = `${sj.NamaPelanggan}|${sj.KodeOutlet}|${bulan}`;
        const nomorBulan = Number(bulan.slice(5, 7));
        const nama = NAMA_BULAN[nomorBulan - 1] ?? bulan;
        const kelompok = peta.get(kunci) ?? {
            Kunci: kunci,
            Judul: `${sj.NamaPelanggan} | ${sj.KodeOutlet} | ${nama} ${bulan.slice(0, 4)}`,
            Baris: [],
        };

        kelompok.Baris.push(sj);
        peta.set(kunci, kelompok);
    }

    return [...peta.values()];
}

/** Surat jalan yang sudah terpilih dari tautan (`?surat-jalan=UUID`), mis. dari halaman detail surat jalan. */
function AmbilPilihanAwal(url: string): string[] {
    const nilai = new URL(url, 'http://lokal').searchParams.getAll('surat-jalan');

    return nilai.filter((uuid) => uuid !== '');
}

/**
 * Menerbitkan faktur penjualan grosir dari surat jalan yang belum difakturkan (BR-12.4).
 *
 * Batasnya ditegakkan server, bukan di sini: satu pelanggan, satu outlet, satu bulan kalender (UU PPN Pasal 13 ayat
 * 2a), dan tarif PPN seragam. Halaman ini membantu memilih dengan menampilkan pelanggan & bulan penyerahan tiap baris,
 * lalu server menolak kombinasi yang tidak sah dengan alasan yang menyebut sebabnya.
 */
export default function HalamanBuatFakturGrosir({ SuratJalan, HariIni, Izin }: PropsBuatFaktur) {
    const { props, url } = usePage<PropsBersamaAplikasi>();
    const [dipilih, AturDipilih] = useState<string[]>(() => AmbilPilihanAwal(url));
    const [tanggal, AturTanggal] = useState(HariIni);
    const [nomorPajak, AturNomorPajak] = useState('');
    const [memproses, AturMemproses] = useState(false);

    const baris = SuratJalan.Data;
    const kelompok = KelompokkanSuratJalan(baris);
    const terpilih = baris.filter((sj) => dipilih.includes(sj.Uuid));
    const total = terpilih.length === 0 ? '0' : JumlahkanDesimal(terpilih.map((sj) => sj.Total));

    // Hanya satu kelompok yang bisa dipilih sekaligus, sama seperti aturan server; memilih di kelompok lain mengganti pilihan.
    const Alihkan = (uuid: string, uuidKelompok: string[]) =>
        AturDipilih((lama) => {
            if (lama.includes(uuid)) {
                return lama.filter((u) => u !== uuid);
            }

            return [...lama.filter((u) => uuidKelompok.includes(u)), uuid];
        });
    const AlihkanKelompok = (uuidKelompok: string[]) =>
        AturDipilih((lama) => (uuidKelompok.every((u) => lama.includes(u)) ? [] : uuidKelompok));

    const Kirim = () =>
        router.post(
            alamat,
            { UuidSuratJalan: dipilih, Tanggal: tanggal, NomorFakturPajak: nomorPajak === '' ? null : nomorPajak },
            { onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );

    return (
        <HalamanGrosir
            judul="Buat faktur penjualan"
            keterangan="Surat jalan dikelompokkan per pelanggan, outlet, dan bulan penyerahan karena satu faktur hanya untuk satu kelompok. Tekan Pilih semua pada kelompok yang mau ditagihkan."
            izin={Izin}
            objek="faktur penjualan"
        >
            <Pemberitahuan jenis="info" judul="Faktur tidak menambah pendapatan">
                Pendapatan dan stok sudah tercatat saat barangnya diserahkan. Faktur hanya menagihkan nilainya ke
                pelanggan, dengan jatuh tempo mengikuti termin pelanggan.
            </Pemberitahuan>

            <Panel judul="Surat jalan belum difakturkan">
                {baris.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">
                        Tidak ada surat jalan yang menunggu difakturkan. Semua penyerahan sudah ditagihkan.
                    </p>
                ) : (
                    <div className="flex flex-col gap-4">
                        {kelompok.map((grup) => {
                            const uuidGrup = grup.Baris.map((sj) => sj.Uuid);
                            const semua = uuidGrup.every((uuid) => dipilih.includes(uuid));

                            return (
                                <section key={grup.Kunci} aria-label={grup.Judul} className="flex flex-col gap-2">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <h3 className="text-label font-semibold text-teks-utama">{grup.Judul}</h3>
                                        <Button
                                            type="button"
                                            variant="link"
                                            className="h-8 px-2 pointer-coarse:h-11"
                                            onClick={() => AlihkanKelompok(uuidGrup)}
                                        >
                                            {semua ? 'Batal pilih semua' : `Pilih semua (${String(grup.Baris.length)})`}
                                        </Button>
                                    </div>
                                    <ul className="flex flex-col gap-2">
                                        {grup.Baris.map((sj) => (
                                            <li
                                                key={sj.Uuid}
                                                className="flex items-start gap-3 rounded-panel border border-garis p-3"
                                            >
                                                <Checkbox
                                                    id={`sj-${sj.Uuid}`}
                                                    checked={dipilih.includes(sj.Uuid)}
                                                    onCheckedChange={() => Alihkan(sj.Uuid, uuidGrup)}
                                                    aria-label={`Pilih ${sj.Nomor}`}
                                                />
                                                <label
                                                    htmlFor={`sj-${sj.Uuid}`}
                                                    className="flex min-w-0 flex-col gap-0.5"
                                                >
                                                    <span className="font-mono font-semibold break-all text-teks-utama">
                                                        {sj.Nomor}
                                                    </span>
                                                    <span className="text-keterangan text-teks-sekunder">
                                                        diserahkan {FormatTanggal(sj.Tanggal)} |{' '}
                                                        {FormatRupiah(sj.Total)}
                                                    </span>
                                                </label>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            );
                        })}
                    </div>
                )}
            </Panel>

            <Panel judul="Faktur">
                <div className="grid gap-4 sm:grid-cols-2">
                    <PemilihTanggal
                        label="Tanggal faktur"
                        nilai={tanggal}
                        saatBerubah={AturTanggal}
                        galat={props.errors.Tanggal}
                        required
                    />
                    <BidangTeks
                        label="Nomor Faktur Pajak"
                        nilai={nomorPajak}
                        saatBerubah={AturNomorPajak}
                        keterangan="Boleh dikosongkan dan diisi nanti setelah nomornya didapat dari e-Faktur."
                        galat={props.errors.NomorFakturPajak}
                        maxLength={30}
                        kode
                    />
                </div>
                <p className="mt-3 text-isi text-teks-utama">
                    {terpilih.length} surat jalan dipilih | total tagihan{' '}
                    <span className="font-semibold tabular-nums">{FormatRupiah(total)}</span>
                </p>
            </Panel>

            <BilahAksiForm>
                <Button asChild variant="outline" type="button">
                    <Link href={alamat}>Batal</Link>
                </Button>
                <Tombol type="button" onClick={Kirim} memproses={memproses} disabled={terpilih.length === 0}>
                    Terbitkan faktur
                </Tombol>
            </BilahAksiForm>
        </HalamanGrosir>
    );
}
