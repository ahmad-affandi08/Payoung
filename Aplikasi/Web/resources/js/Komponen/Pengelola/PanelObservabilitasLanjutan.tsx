import { useForm } from '@inertiajs/react';
import { type FormEvent, type ReactNode } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Card, CardContent, CardHeader, CardTitle } from '@/Komponen/Ui/card';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';

export type RuteTerlambat = {
    Kelompok: string;
    NamaRute: string;
    Jumlah: number;
    GalatServer: number;
    RataRataMs: number;
    P95Ms: number | null;
    P95Lebih: boolean;
};

export type GalatSinkron = { JenisItem: string; KodeGalat: string; Jumlah: number };

export type WebhookGagal = { NamaTenant: string; JumlahGagal: number; TerakhirPada: string | null };

export type DataLanjutan = {
    JamJendela: number;
    MinimumPermintaanRute: number;
    RuteTerlambat: RuteTerlambat[];
    GalatSinkron: GalatSinkron[];
    WebhookGagal: WebhookGagal[];
};

const NamaKelompok: Record<string, string> = { Pos: 'API POS', Pemilik: 'API Owner', ApiPublik: 'API publik' };

/** "paling lama 400 ms" / "lebih dari 1.600 ms": p95 dibaca dari kelompok latensi, bukan angka tepat. */
export function TeksP95Rute(baris: Pick<RuteTerlambat, 'P95Ms' | 'P95Lebih'>): string {
    if (baris.P95Lebih) {
        return 'lebih dari 1.600 ms';
    }

    return baris.P95Ms === null ? '—' : `paling lama ${baris.P95Ms.toLocaleString('id-ID')} ms`;
}

const kolomRute: KolomTabel<RuteTerlambat>[] = [
    {
        id: 'NamaRute',
        accessorKey: 'NamaRute',
        header: 'Rute',
        enableSorting: false,
        meta: { label: 'Rute', prioritas: 'utama', wajib: true, kelasSel: 'font-mono text-label' },
        cell: ({ row: { original: baris } }) => (
            <span className="flex flex-col">
                <span className="break-all">{baris.NamaRute}</span>
                <span className="font-sans text-keterangan text-teks-sekunder">
                    {NamaKelompok[baris.Kelompok] ?? baris.Kelompok}
                </span>
            </span>
        ),
    },
    {
        id: 'P95',
        header: 'p95',
        enableSorting: false,
        meta: { label: 'p95', prioritas: 'utama' },
        cell: ({ row }) => TeksP95Rute(row.original),
    },
    {
        id: 'RataRata',
        header: 'Rata-rata',
        enableSorting: false,
        meta: { label: 'Rata-rata', angka: true, prioritas: 'penting' },
        cell: ({ row }) => `${row.original.RataRataMs.toLocaleString('id-ID')} ms`,
    },
    {
        id: 'Jumlah',
        header: 'Permintaan',
        enableSorting: false,
        meta: { label: 'Permintaan', angka: true, prioritas: 'penting' },
        cell: ({ row }) => row.original.Jumlah.toLocaleString('id-ID'),
    },
    {
        id: 'GalatServer',
        header: 'Masalah server',
        enableSorting: false,
        meta: { label: 'Masalah server', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => row.original.GalatServer.toLocaleString('id-ID'),
    },
];

const kolomGalat: KolomTabel<GalatSinkron>[] = [
    {
        id: 'JenisItem',
        accessorKey: 'JenisItem',
        header: 'Jenis data',
        enableSorting: false,
        meta: { label: 'Jenis data', prioritas: 'utama', wajib: true, kelasSel: 'font-mono text-label' },
    },
    {
        id: 'KodeGalat',
        accessorKey: 'KodeGalat',
        header: 'Kode masalah',
        enableSorting: false,
        meta: { label: 'Kode masalah', prioritas: 'utama', kelasSel: 'font-mono text-label' },
    },
    {
        id: 'Jumlah',
        header: 'Ditolak',
        enableSorting: false,
        meta: { label: 'Ditolak', angka: true, prioritas: 'penting' },
        cell: ({ row }) => row.original.Jumlah.toLocaleString('id-ID'),
    },
];

const kolomWebhook: KolomTabel<WebhookGagal>[] = [
    {
        id: 'NamaTenant',
        accessorKey: 'NamaTenant',
        header: 'Tenant',
        enableSorting: false,
        meta: { label: 'Tenant', prioritas: 'utama', wajib: true },
    },
    {
        id: 'JumlahGagal',
        header: 'Kiriman gagal',
        enableSorting: false,
        meta: { label: 'Kiriman gagal', angka: true, prioritas: 'penting' },
        cell: ({ row }) => row.original.JumlahGagal.toLocaleString('id-ID'),
    },
    {
        id: 'TerakhirPada',
        header: 'Terakhir',
        enableSorting: false,
        meta: { label: 'Terakhir', prioritas: 'penting' },
        cell: ({ row }) => (row.original.TerakhirPada === null ? '—' : FormatTanggalWaktu(row.original.TerakhirPada)),
    },
];

function Bagian({ judul, children }: { judul: string; children: ReactNode }) {
    return (
        <Card className="gap-3 rounded-panel py-4 shadow-none">
            <CardHeader className="px-4">
                <CardTitle>
                    <h2 className="text-subjudul font-semibold text-teks-utama">{judul}</h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3 px-4">{children}</CardContent>
        </Card>
    );
}

/**
 * PGL-20 lanjutan: rute API paling lambat, penolakan sinkron terbanyak, dan webhook keluar gagal per tenant
 * (24 jam terakhir). Tidak ada angka yang memuat data usaha tenant kecuali nama tenant pada webhook gagal, yang dibaca
 * lewat akses lintas tenant yang diaudit.
 */
export default function PanelObservabilitasLanjutan({ data }: { data: DataLanjutan }) {
    return (
        <>
            <Bagian judul={`Rute API paling lambat (${String(data.JamJendela)} jam terakhir)`}>
                <p className="max-w-3xl text-label text-teks-sekunder">
                    Diurutkan menurut p95. Hanya rute dengan paling sedikit {String(data.MinimumPermintaanRute)}{' '}
                    permintaan. p95 dibaca dari kelompok latensi, jadi angkanya batas atas kelompok tempat persentil
                    ke-95 jatuh. Rute dinamai seperti di kode (misalnya <code>pos.sinkron.kirim</code>).
                </p>
                <TabelData
                    id="pengelola-operasional-rute-terlambat"
                    label="Rute API paling lambat"
                    kolom={kolomRute}
                    sumber={{ mode: 'lokal', data: data.RuteTerlambat }}
                    ambilIdBaris={(baris) => `${baris.Kelompok}|${baris.NamaRute}`}
                    kosong={{ ilustrasi: true, judul: 'Belum ada rute dengan permintaan cukup banyak.' }}
                />
            </Bagian>

            <Bagian judul={`Penolakan sinkron terbanyak (${String(data.JamJendela)} jam terakhir)`}>
                <p className="max-w-3xl text-label text-teks-sekunder">
                    Berapa kali server menolak data dari kasir, per jenis data dan kode masalah. Lonjakan satu kode
                    biasanya berarti aturan bisnis yang membingungkan kasir atau versi aplikasi yang bermasalah.
                </p>
                <TabelData
                    id="pengelola-operasional-galat-sinkron"
                    label="Penolakan sinkron terbanyak"
                    kolom={kolomGalat}
                    sumber={{ mode: 'lokal', data: data.GalatSinkron }}
                    ambilIdBaris={(baris) => `${baris.JenisItem}|${baris.KodeGalat}`}
                    kosong={{ ilustrasi: true, judul: 'Tidak ada penolakan sinkron.' }}
                />
            </Bagian>

            <Bagian judul={`Webhook keluar gagal per tenant (${String(data.JamJendela)} jam terakhir)`}>
                <p className="max-w-3xl text-label text-teks-sekunder">
                    Kiriman yang percobaannya habis. Hubungi tenant agar memeriksa alamat dan penerima webhooknya.
                    Membuka daftar ini tercatat di log audit.
                </p>
                <TabelData
                    id="pengelola-operasional-webhook-gagal"
                    label="Webhook keluar gagal per tenant"
                    kolom={kolomWebhook}
                    sumber={{ mode: 'lokal', data: data.WebhookGagal }}
                    ambilIdBaris={(baris) => baris.NamaTenant}
                    kosong={{ ilustrasi: true, judul: 'Tidak ada webhook keluar yang gagal.' }}
                />
            </Bagian>
        </>
    );
}

/** Alamat dasar runbook (Teknis): tiap alert menautkan `{alamat}#{jangkar}`. */
export function FormTautanRunbook({ tautan }: { tautan: string | null }) {
    const formulir = useForm({ TautanRunbook: tautan ?? '' });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/operasional/tautan-runbook', { preserveScroll: true });
    };

    return (
        <form onSubmit={Kirim} className="grid gap-3 sm:max-w-xl" noValidate>
            <BidangTeks
                label="Alamat runbook"
                nilai={formulir.data.TautanRunbook}
                saatBerubah={(nilai) => formulir.setData('TautanRunbook', nilai)}
                galat={formulir.errors.TautanRunbook}
                maxLength={255}
                kode
            />
            <div>
                <Tombol type="submit" varian="sekunder" memproses={formulir.processing}>
                    Simpan alamat runbook
                </Tombol>
            </div>
        </form>
    );
}

/** Tautan "Runbook" untuk satu alert; null bila alamat dasar belum diatur. */
export function AlamatRunbookAlert(tautan: string | null, jangkar: string): string | null {
    return tautan === null || tautan === '' ? null : `${tautan}#${jangkar}`;
}
