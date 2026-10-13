import { Link, useForm } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import TabSitus from '@/Komponen/Pengelola/Situs/TabSitus';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { HasilTabel, KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';

export type RingkasArtikelBantuan = {
    Uuid: string;
    Slug: string;
    Judul: string;
    Kategori: string | null;
    Urutan: number;
    JumlahKonteks: number;
    Status: 'Draf' | 'Terbit';
    LabelStatus: string;
    Jalur: string;
    DiterbitkanPada: string | null;
    DiubahPada: string | null;
};

const kolom: KolomTabel<RingkasArtikelBantuan>[] = [
    {
        id: 'Judul',
        accessorKey: 'Judul',
        header: 'Artikel',
        meta: { label: 'Artikel', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: a } }) => (
            <>
                <Link
                    href={`/situs/bantuan/${a.Uuid}`}
                    className="block font-semibold break-words text-teks-utama underline"
                >
                    {a.Judul}
                </Link>
                <span className="block font-mono text-keterangan break-all text-teks-sekunder">{a.Jalur}</span>
            </>
        ),
    },
    {
        id: 'Kategori',
        header: 'Kategori',
        enableSorting: false,
        meta: { label: 'Kategori', prioritas: 'rendah' },
        cell: ({ row }) => row.original.Kategori ?? '–',
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: a } }) => (
            <LabelStatus jenis={a.Status === 'Terbit' ? 'sukses' : 'peringatan'} teks={a.LabelStatus} />
        ),
    },
    {
        id: 'JumlahKonteks',
        header: 'Halaman konteks',
        enableSorting: false,
        meta: { label: 'Halaman konteks', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => (row.original.JumlahKonteks === 0 ? '–' : String(row.original.JumlahKonteks)),
    },
    {
        id: 'DiterbitkanPada',
        accessorKey: 'DiterbitkanPada',
        header: 'Terbit',
        meta: { label: 'Terbit', prioritas: 'rendah', kelasSel: 'text-teks-sekunder whitespace-nowrap' },
        cell: ({ row: { original: a } }) => (a.DiterbitkanPada ? FormatTanggalWaktu(a.DiterbitkanPada) : '–'),
    },
    {
        id: 'DiubahPada',
        accessorKey: 'DiubahPada',
        header: 'Diubah',
        meta: { label: 'Diubah', prioritas: 'rendah', kelasSel: 'text-teks-sekunder whitespace-nowrap' },
        cell: ({ row: { original: a } }) => (a.DiubahPada ? FormatTanggalWaktu(a.DiubahPada) : '–'),
    },
];

/** Artikel Pusat Bantuan (PLT-12): tulis draf, terbitkan ke `/pusat-bantuan` dan bantuan kontekstual back-office. */
export default function HalamanDaftarArtikelBantuan({
    Artikel,
    PilihanKategori,
    UrlPusatBantuan,
    Izin,
}: {
    Artikel: HasilTabel<RingkasArtikelBantuan>;
    PilihanKategori: string[];
    UrlPusatBantuan: string;
    Izin: { Kelola: boolean };
}) {
    const [buat, AturBuat] = useState(false);

    return (
        <TataLetakPengelola judul="Situs pemasaran">
            <TabSitus />
            {buat ? <FormBuatArtikelBantuan saatTutup={() => AturBuat(false)} /> : null}
            <AksiHalaman>
                {
                    <div className="flex flex-wrap gap-2">
                        <a
                            href={UrlPusatBantuan}
                            target="_blank"
                            rel="noopener"
                            className="inline-flex h-8 items-center gap-2 rounded-kontrol border border-garis-input px-3 text-isi font-medium text-teks-utama hover:bg-permukaan-sorot pointer-coarse:h-11"
                        >
                            Buka Pusat Bantuan <ExternalLink className="size-4" aria-hidden />
                        </a>
                        {Izin.Kelola ? <Tombol onClick={() => AturBuat(true)}>Tulis artikel bantuan</Tombol> : null}
                    </div>
                }
            </AksiHalaman>
            <TabelData
                id="pengelola-situs-bantuan"
                label="Artikel bantuan"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: '/situs/bantuan', awal: Artikel }}
                ambilIdBaris={(a) => a.Uuid}
                cari="Cari judul, alamat, atau kategori"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: [
                            { nilai: 'Draf', label: 'Draf' },
                            { nilai: 'Terbit', label: 'Terbit' },
                        ],
                    },
                    {
                        id: 'Kategori',
                        label: 'Kategori',
                        jenis: 'pilihan',
                        opsi: PilihanKategori.map((k) => ({ nilai: k, label: k })),
                    },
                ]}
                alamatDetail={(a) => `/situs/bantuan/${a.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada artikel. Tulis artikel bantuan pertama untuk blog situs.',
                }}
            />
        </TataLetakPengelola>
    );
}

function FormBuatArtikelBantuan({ saatTutup }: { saatTutup: () => void }) {
    const formulir = useForm({ Judul: '' });

    const Kirim = (p: FormEvent) => {
        p.preventDefault();
        formulir.post('/situs/bantuan', { preserveScroll: true });
    };

    return (
        <DialogFormulir
            judul="Tulis artikel bantuan"
            saatTutup={saatTutup}
            galatUmum={(formulir.errors as Record<string, string | undefined>).Umum}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeks
                    label="Judul artikel"
                    keterangan="Alamat artikel dibuat otomatis dari judul dan bisa diubah nanti."
                    nilai={formulir.data.Judul}
                    saatBerubah={(v) => formulir.setData('Judul', v)}
                    galat={formulir.errors.Judul}
                    maxLength={150}
                    required
                    autoFocus
                />
                <div className="flex justify-end gap-2">
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                    <Tombol type="submit" memproses={formulir.processing}>
                        Buat draf
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}
