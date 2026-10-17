import { Link, router, usePage } from '@inertiajs/react';
import { useId, useRef, useState, type ChangeEvent, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import { AmbilEkstensiBerkas } from '@/Komponen/Formulir/BidangGambar';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import LangkahImpor, { JenisLabelImpor } from '@/Komponen/Katalog/LangkahImpor';
import PeringatanAsumsi from '@/Komponen/Katalog/PeringatanAsumsi';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
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
import type { PropsDaftarImpor, RingkasanImpor } from '@/Tipe/Katalog';
import { FormatBatas } from '@/Tipe/Organisasi';

/** Pemeriksaan berkas sebelum unggah (server tetap memeriksa ulang): ekstensi dan ukuran. */
export function PeriksaBerkasImpor(berkas: File, batas: PropsDaftarImpor['BatasBerkas']): string | null {
    if (!batas.Ekstensi.includes(AmbilEkstensiBerkas(berkas.name))) {
        return `Format ${berkas.name} tidak didukung. Pilih berkas ${batas.Ekstensi.join(' atau ')}.`;
    }

    if (berkas.size > batas.UkuranMaksimalKb * 1024) {
        return `Ukuran ${FormatUkuranBerkas(berkas.size)} melebihi batas ${FormatUkuranBerkas(batas.UkuranMaksimalKb * 1024)}. Bagi berkas menjadi beberapa bagian.`;
    }

    return null;
}

function RingkasHasil(impor: RingkasanImpor): string {
    if (impor.Status === 'Selesai') {
        return `${String(impor.JumlahDibuat)} dibuat, ${String(impor.JumlahDiperbarui)} diperbarui, ${String(impor.JumlahGagal)} gagal`;
    }

    return `${String(impor.JumlahBaris)} baris`;
}

const kolomRiwayat: KolomTabel<RingkasanImpor>[] = [
    {
        id: 'NamaBerkas',
        accessorKey: 'NamaBerkas',
        header: 'Berkas',
        meta: { label: 'Berkas', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: impor } }) => (
            <>
                <Link
                    href={`/kelola/produk/impor/${impor.Uuid}`}
                    className="font-semibold break-all text-brand underline"
                >
                    {impor.NamaBerkas}
                </Link>
                <span className="block text-keterangan font-normal text-teks-sekunder">
                    Format {impor.LabelSumber} | {impor.NamaPengguna ?? 'Sistem'}
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
        cell: ({ row }) => RingkasHasil(row.original),
    },
];

/** F-03 impor produk langkah 1: pilih format sumber, unggah Excel/CSV, dan riwayat impor (BR-03.6). */
export default function HalamanDaftarImpor({
    Riwayat,
    OpsiStatus,
    Preset,
    BatasBerkas,
    BatasSku,
    Izin,
}: PropsDaftarImpor) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const id = useId();
    const masukan = useRef<HTMLInputElement>(null);
    const [sumber, AturSumber] = useState(Preset[0]?.Kode ?? '');
    const [berkas, AturBerkas] = useState<File | null>(null);
    const [galatBerkas, AturGalatBerkas] = useState<string | null>(null);
    const [mengunggah, AturMengunggah] = useState(false);
    const preset = Preset.find((item) => item.Kode === sumber);
    const pesanBerkas = galatBerkas ?? props.errors.Berkas;

    const Pilih = (peristiwa: ChangeEvent<HTMLInputElement>) => {
        const terpilih = peristiwa.target.files?.[0] ?? null;

        if (terpilih === null) {
            return;
        }

        const pesan = PeriksaBerkasImpor(terpilih, BatasBerkas);
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
            '/kelola/produk/impor',
            { Berkas: berkas, Sumber: sumber },
            { forceFormData: true, onStart: () => AturMengunggah(true), onFinish: () => AturMengunggah(false) },
        );
    };

    return (
        <TataLetakAplikasi judul="Impor produk">
            <p className="text-label">
                <Link href="/kelola/produk" className="font-semibold text-brand underline">
                    Kembali ke daftar produk
                </Link>
            </p>
            <LangkahImpor status={null} />
            {!Izin.Kelola ? <PesanHanyaLihat izin="produk.kelola" objek="riwayat impor" /> : null}
            <DaftarGalatServer galat={props.errors} kecuali={['Berkas']} />

            {Izin.Kelola ? (
                <Card className="gap-0 rounded-panel p-4 shadow-none">
                    <form onSubmit={Unggah} noValidate aria-labelledby={`${id}-judul`} className="flex flex-col gap-4">
                        <h2 id={`${id}-judul`} className="text-subjudul font-semibold text-teks-utama">
                            Unggah berkas
                        </h2>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1">
                                <BidangPilihan
                                    label="Format berkas dari"
                                    nilai={sumber}
                                    opsi={Preset.map((item) => ({ Nilai: item.Kode, Label: item.Nama }))}
                                    saatBerubah={AturSumber}
                                    required
                                    galat={props.errors.Sumber}
                                />
                                {preset ? (
                                    <p className="text-keterangan text-teks-sekunder">{preset.Keterangan}</p>
                                ) : null}
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
                        {preset?.Asumsi ? (
                            <PeringatanAsumsi>
                                Nama kolom ekspor {preset.Nama} belum diverifikasi dengan berkas asli. Setelah unggah,
                                cocokkan kembali setiap kolom di langkah Pemetaan kolom.
                            </PeringatanAsumsi>
                        ) : null}
                        <p className="text-keterangan text-teks-sekunder">
                            Yang diimpor: produk, satuan, barcode, harga dan harga grosir, varian, kategori, dan
                            kelompok pajak. Modifier, resep, daftar harga, stok awal, dan HPP tidak ikut diimpor. Produk
                            terhitung paket saat ini:{' '}
                            <span className="tabular-nums">{FormatBatas(BatasSku, 'produk')}</span>.
                        </p>
                        <p className="text-keterangan text-teks-sekunder">
                            Di templat, judul kolom bertanda <span className="font-semibold text-bahaya">*</span> wajib
                            diisi (hanya Nama Produk). Kolom lain boleh kosong atau dihapus.
                        </p>
                        <div className="flex flex-wrap items-center gap-3">
                            <Tombol type="submit" memproses={mengunggah}>
                                Unggah & lanjut ke pemetaan
                            </Tombol>
                            <Button asChild variant="link" className="px-0">
                                <a href="/kelola/produk/impor/templat?format=xlsx">Unduh templat Excel</a>
                            </Button>
                            <Button asChild variant="link" className="px-0">
                                <a href="/kelola/produk/impor/templat?format=csv">Unduh templat CSV</a>
                            </Button>
                        </div>
                    </form>
                </Card>
            ) : null}

            <section aria-labelledby="judul-riwayat-impor" className="flex flex-col gap-2">
                <h2 id="judul-riwayat-impor" className="text-subjudul font-semibold text-teks-utama">
                    Riwayat impor
                </h2>
                <TabelData
                    id="katalog-riwayat-impor"
                    label="Riwayat impor produk"
                    kolom={kolomRiwayat}
                    sumber={{ mode: 'server', alamat: '/kelola/produk/impor', awal: Riwayat }}
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
                    alamatDetail={(impor) => `/kelola/produk/impor/${impor.Uuid}`}
                    kosong={{ ilustrasi: true, judul: 'Belum pernah mengimpor produk. Riwayat disimpan 30 hari.' }}
                />
            </section>
        </TataLetakAplikasi>
    );
}
