import { Link, router, usePage } from '@inertiajs/react';
import { useId, useRef, useState, type ChangeEvent, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import { AmbilEkstensiBerkas } from '@/Komponen/Formulir/BidangGambar';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import KeadaanKosong from '@/Komponen/Katalog/KeadaanKosong';
import LangkahImpor, { JenisLabelImpor } from '@/Komponen/Katalog/LangkahImpor';
import { LabelOpsiGudang } from '@/Komponen/Persediaan/Impor/PemetaanImporStokAwal';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { Card } from '@/Komponen/Ui/card';
import { Input } from '@/Komponen/Ui/input';
import { Label } from '@/Komponen/Ui/label';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatUkuranBerkas } from '@/Pustaka/FormatUkuran';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsDaftarImporStokAwal, RingkasanImporStokAwal } from '@/Tipe/Persediaan';

const alamatImpor = '/kelola/persediaan/stok-awal/impor';

/** Pemeriksaan berkas sebelum unggah (server tetap memeriksa ulang isi berkas): ekstensi dan ukuran. */
export function PeriksaBerkasImporStokAwal(
    berkas: File,
    batas: PropsDaftarImporStokAwal['BatasBerkas'],
): string | null {
    if (!batas.Ekstensi.includes(AmbilEkstensiBerkas(berkas.name))) {
        return `Format ${berkas.name} tidak didukung. Pilih berkas ${batas.Ekstensi.join(' atau ')}.`;
    }

    if (berkas.size > batas.UkuranMaksimalKb * 1024) {
        return `Ukuran ${FormatUkuranBerkas(berkas.size)} melebihi batas ${FormatUkuranBerkas(batas.UkuranMaksimalKb * 1024)}. Bagi berkas menjadi beberapa bagian.`;
    }

    return null;
}

/** Tautan unduhan templat stok awal; `isiProduk` mengisi daftar produk berstok (lokasi = lokasi terpilih). */
export function BuatUrlTemplatStokAwal(format: 'xlsx' | 'csv', isiProduk: boolean, uuidGudang: string | null): string {
    const parameter = new URLSearchParams({ format });

    if (isiProduk) {
        parameter.set('isi', 'produk');
    }

    if (isiProduk && uuidGudang) {
        parameter.set('gudang', uuidGudang);
    }

    return `${alamatImpor}/templat?${parameter.toString()}`;
}

export function RingkasHasilImporStokAwal(impor: RingkasanImporStokAwal): string {
    if (impor.Status === 'Selesai') {
        return `${impor.JumlahDokumen.toLocaleString('id-ID')} draf dibuat dari ${impor.JumlahValid.toLocaleString('id-ID')} baris`;
    }

    if (impor.Status === 'Pratinjau') {
        return `${impor.JumlahValid.toLocaleString('id-ID')} valid, ${impor.JumlahGalat.toLocaleString('id-ID')} bermasalah`;
    }

    return `${impor.JumlahBaris.toLocaleString('id-ID')} baris`;
}

const kolomRiwayat: KolomTabel<RingkasanImporStokAwal>[] = [
    {
        id: 'NamaBerkas',
        accessorKey: 'NamaBerkas',
        header: 'Berkas',
        meta: { label: 'Berkas', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: impor } }) => (
            <>
                <Link href={`${alamatImpor}/${impor.Uuid}`} className="font-semibold break-all text-brand underline">
                    {impor.NamaBerkas}
                </Link>
                <span className="block text-keterangan font-normal text-teks-sekunder">
                    {impor.NamaGudangBawaan ? `Lokasi bawaan ${impor.NamaGudangBawaan}` : 'Tanpa lokasi bawaan'} |{' '}
                    {impor.NamaPengguna ?? 'Sistem'}
                </span>
            </>
        ),
    },
    {
        id: 'DibuatPada',
        accessorKey: 'DibuatPada',
        header: 'Waktu',
        meta: { label: 'Waktu', prioritas: 'penting', kelasSel: 'text-teks-sekunder whitespace-nowrap' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.DibuatPada),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => <LabelStatus jenis={JenisLabelImpor(row.original.Status)} teks={row.original.LabelStatus} />,
    },
    {
        id: 'Hasil',
        header: 'Hasil',
        enableSorting: false,
        meta: { label: 'Hasil', prioritas: 'rendah', kelasSel: 'text-teks-sekunder tabular-nums' },
        cell: ({ row }) => RingkasHasilImporStokAwal(row.original),
    },
];

/** F-05a impor stok awal langkah 1: unduh templat, unggah Excel/CSV + lokasi bawaan, dan riwayat impor. */
export default function HalamanDaftarImporStokAwal({
    Riwayat,
    OpsiStatus,
    OpsiGudang,
    BatasBerkas,
}: PropsDaftarImporStokAwal) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const id = useId();
    const masukan = useRef<HTMLInputElement>(null);
    const [uuidGudang, AturUuidGudang] = useState<string | null>(
        OpsiGudang.length === 1 ? (OpsiGudang[0]?.Uuid ?? null) : null,
    );
    const [berkas, AturBerkas] = useState<File | null>(null);
    const [galatBerkas, AturGalatBerkas] = useState<string | null>(null);
    const [mengunggah, AturMengunggah] = useState(false);
    const pesanBerkas = galatBerkas ?? props.errors.Berkas;

    const Pilih = (peristiwa: ChangeEvent<HTMLInputElement>) => {
        const terpilih = peristiwa.target.files?.[0] ?? null;

        if (terpilih === null) {
            return;
        }

        const pesan = PeriksaBerkasImporStokAwal(terpilih, BatasBerkas);
        AturGalatBerkas(pesan);
        AturBerkas(pesan === null ? terpilih : null);

        if (pesan !== null) {
            peristiwa.target.value = '';
        }
    };

    const Unggah = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        if (berkas === null) {
            AturGalatBerkas('Pilih berkas Excel atau CSV lebih dulu.');
            masukan.current?.focus();

            return;
        }

        router.post(
            alamatImpor,
            { Berkas: berkas, UuidGudangBawaan: uuidGudang },
            { forceFormData: true, onStart: () => AturMengunggah(true), onFinish: () => AturMengunggah(false) },
        );
    };

    return (
        <TataLetakAplikasi judul="Impor stok awal">
            <p className="text-label">
                <Link href="/kelola/persediaan/stok-awal" className="font-semibold text-brand underline">
                    Kembali ke daftar stok awal
                </Link>
            </p>
            <LangkahImpor status={null} />
            <DaftarGalatServer galat={props.errors} kecuali={['Berkas', 'UuidGudangBawaan']} />

            {OpsiGudang.length === 0 ? (
                <KeadaanKosong judul="Belum ada lokasi stok yang bisa Anda akses.">
                    Minta Pemilik membuat lokasi stok atau memberi akses outlet, lalu kembali ke halaman ini.
                </KeadaanKosong>
            ) : (
                <Card className="gap-0 rounded-panel p-4 shadow-none">
                    <form onSubmit={Unggah} noValidate aria-labelledby={`${id}-judul`} className="flex flex-col gap-4">
                        <h2 id={`${id}-judul`} className="text-subjudul font-semibold text-teks-utama">
                            Unggah berkas stok awal
                        </h2>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1">
                                <BidangPilihan
                                    label="Lokasi stok bawaan"
                                    nilai={uuidGudang ?? ''}
                                    kosong="Tidak ada (isi kolom Lokasi Stok di berkas)"
                                    opsi={OpsiGudang.map((gudang) => ({
                                        Nilai: gudang.Uuid,
                                        Label: LabelOpsiGudang(gudang),
                                    }))}
                                    saatBerubah={(nilai) => AturUuidGudang(nilai === '' ? null : nilai)}
                                    galat={props.errors.UuidGudangBawaan}
                                />
                                <p className="text-keterangan text-teks-sekunder">
                                    Dipakai untuk baris yang kolom Lokasi Stok-nya kosong.
                                </p>
                            </div>
                            <div className="flex flex-col gap-1">
                                <Label htmlFor={`${id}-berkas`} className="text-label font-semibold text-teks-utama">
                                    Berkas Excel atau CSV
                                </Label>
                                <Input
                                    ref={masukan}
                                    id={`${id}-berkas`}
                                    type="file"
                                    accept={BatasBerkas.Ekstensi.map((item) => `.${item}`).join(',')}
                                    onChange={Pilih}
                                    required
                                    aria-invalid={pesanBerkas ? true : undefined}
                                    aria-describedby={`${id}-keterangan${pesanBerkas ? ` ${id}-galat` : ''}`}
                                    className="h-8 pointer-coarse:h-11 py-1.5 text-isi file:mr-3 file:font-semibold"
                                />
                                <p id={`${id}-keterangan`} className="text-keterangan text-teks-sekunder tabular-nums">
                                    Format {BatasBerkas.Ekstensi.join(', ')}, maksimal{' '}
                                    {FormatUkuranBerkas(BatasBerkas.UkuranMaksimalKb * 1024)} dan{' '}
                                    {BatasBerkas.MaksimalBaris.toLocaleString('id-ID')} baris.
                                </p>
                                <div aria-live="polite">
                                    {pesanBerkas ? (
                                        <p id={`${id}-galat`} className="text-keterangan font-semibold text-bahaya">
                                            {pesanBerkas}
                                        </p>
                                    ) : berkas ? (
                                        <p className="text-keterangan text-teks-utama">
                                            {berkas.name} | {FormatUkuranBerkas(berkas.size)}
                                        </p>
                                    ) : null}
                                </div>
                            </div>
                        </div>
                        <p className="text-keterangan text-teks-sekunder">
                            Isi Stok dalam satuan dasar dan Harga Modal per satuan dasar (maksimal 6 angka di belakang
                            koma). Impor hanya membuat <strong>draf</strong> stok awal per lokasi stok; periksa lalu
                            catat setiap draf di menu Stok awal.
                        </p>
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <Tombol type="submit" memproses={mengunggah}>
                                Unggah & lanjut ke pemetaan
                            </Tombol>
                            <Button asChild variant="link" className="px-0">
                                <a href={BuatUrlTemplatStokAwal('xlsx', true, uuidGudang)}>
                                    Unduh templat Excel berisi daftar produk
                                </a>
                            </Button>
                            <Button asChild variant="link" className="px-0">
                                <a href={BuatUrlTemplatStokAwal('xlsx', false, null)}>Unduh templat Excel kosong</a>
                            </Button>
                            <Button asChild variant="link" className="px-0">
                                <a href={BuatUrlTemplatStokAwal('csv', true, uuidGudang)}>Unduh templat CSV</a>
                            </Button>
                        </div>
                    </form>
                </Card>
            )}

            <section aria-labelledby="judul-riwayat-impor-stok-awal" className="flex flex-col gap-2">
                <h2 id="judul-riwayat-impor-stok-awal" className="text-subjudul font-semibold text-teks-utama">
                    Riwayat impor
                </h2>
                <TabelData
                    id="persediaan-riwayat-impor"
                    label="Riwayat impor stok awal"
                    kolom={kolomRiwayat}
                    sumber={{ mode: 'server', alamat: alamatImpor, awal: Riwayat }}
                    ambilIdBaris={(impor) => impor.Uuid}
                    urutBawaan="-DibuatPada"
                    cari="Cari nama berkas"
                    saring={[
                        {
                            id: 'Status',
                            label: 'Status',
                            jenis: 'pilihanBanyak',
                            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
                        },
                    ]}
                    alamatDetail={(impor) => `${alamatImpor}/${impor.Uuid}`}
                    kosong={{ ilustrasi: true, judul: 'Belum pernah mengimpor stok awal. Riwayat disimpan 30 hari.' }}
                />
            </section>
        </TataLetakAplikasi>
    );
}
