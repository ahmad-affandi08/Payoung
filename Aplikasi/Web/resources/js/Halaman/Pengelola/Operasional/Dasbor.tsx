import { Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent, type ReactNode } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import {
    Alert as KotakPeringatan,
    AlertDescription as IsiPeringatan,
    AlertTitle as JudulPeringatan,
} from '@/Komponen/Ui/alert';
import { Button } from '@/Komponen/Ui/button';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/Komponen/Ui/card';
import { Progress } from '@/Komponen/Ui/progress';
import { Separator } from '@/Komponen/Ui/separator';
import { cn } from '@/Komponen/Ui/utils';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { FormatDurasi, FormatUkuranBerkas } from '@/Pustaka/FormatUkuran';
import { BandingkanDesimal } from '@/Pustaka/HitungDesimal';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, PunyaIzin, type PropsBersamaPengelola } from '@/Tipe/Pengelola';
import PemilihTanggalWaktu from '@/Komponen/Tanggal/PemilihTanggalWaktu';
import PanelObservabilitasLanjutan, {
    AlamatRunbookAlert,
    FormTautanRunbook,
    type DataLanjutan,
} from '@/Komponen/Pengelola/PanelObservabilitasLanjutan';

type CatatanBackup = {
    Uuid: string;
    Jenis: 'Backup' | 'UjiRestore';
    LabelJenis: string;
    Hasil: 'Berhasil' | 'Gagal';
    SelesaiPada: string;
    UkuranByte: number | null;
    Lokasi: string | null;
    Keterangan: string | null;
    Sumber: 'Skrip' | 'Manual';
    DicatatOleh?: string;
};

type Alert = {
    Id: number;
    Jangkar: string;
    Label: string;
    Tingkat: 'Kritis' | 'Peringatan';
    Pesan: string;
    MulaiPada: string;
    SelesaiPada: string | null;
    EmailTerkirimPada: string | null;
    WaTerkirimPada: string | null;
};

type MetrikKelompok = {
    Kelompok: string;
    Jumlah: number;
    GalatServer: number;
    ErrorRatePersen: string;
    RataRataMs: number | null;
    P95Ms: number | null;
    P95Lebih: boolean;
};

type PerangkatMacet = {
    Uuid: string;
    NamaTenant: string;
    NamaOutlet: string;
    NamaPerangkat: string;
    JumlahOutbox: number;
    TertundaSejak: string | null;
    TerakhirAktifPada: string | null;
    VersiAplikasi: string | null;
};

type BarisAntrean = { Antrean: string; Menunggu: number; Diproses: number; UmurTertuaDetik: number | null };

type TugasGagal = { Uuid: string; Antrean: string; NamaTugas: string; RingkasanGalat: string; GagalPada: string };

type Dasbor = {
    Penjadwal: { TerakhirPada: string | null; UmurDetik: number | null; Sehat: boolean; BatasMenit: number };
    Antrean: {
        PerAntrean: BarisAntrean[];
        UmurTertuaDetik: number | null;
        Sehat: boolean;
        BatasMenit: number;
    };
    TugasGagal: {
        Total: number;
        Data: TugasGagal[];
    };
    Backup: {
        BackupTerakhir: CatatanBackup | null;
        UjiRestoreTerakhir: CatatanBackup | null;
        Sehat: boolean;
        BatasJam: number;
        Riwayat: CatatanBackup[];
    };
    Metrik: {
        Jendela: number;
        Terkini: MetrikKelompok[];
        SatuJam: MetrikKelompok[];
        BatasErrorRatePersen: string;
        BatasP95PosMs: number;
        MinimumPermintaan: number;
        KonfirmasiMenit: number;
        PulihMenit: number;
    };
    OutboxMacet: { BatasJam: number; Daftar: PerangkatMacet[] };
    Lanjutan: DataLanjutan;
    AlertWa: { Nomor: string[]; Maks: number };
    Runbook: { Tautan: string | null };
    Alert: { Aktif: Alert[]; Riwayat: Alert[] };
    Kesehatan: {
        PeringatanIntegrasi: string[];
        UkuranDatabaseByte: number | null;
        Disk: { SisaByte: number; TotalByte: number } | null;
    };
};

/** Persentase disk terpakai (bilangan bulat 0–100) untuk indikator; bukan uang/kuantitas. */
function HitungPersenDiskTerpakai(disk: { SisaByte: number; TotalByte: number }): number {
    if (disk.TotalByte <= 0) {
        return 0;
    }

    return Math.min(100, Math.max(0, Math.round(((disk.TotalByte - disk.SisaByte) / disk.TotalByte) * 100)));
}

const kolomAntrean: KolomTabel<BarisAntrean>[] = [
    {
        id: 'Antrean',
        accessorKey: 'Antrean',
        header: 'Antrean',
        meta: { label: 'Antrean', prioritas: 'utama', wajib: true, kelasSel: 'font-mono text-label' },
    },
    {
        id: 'Menunggu',
        accessorKey: 'Menunggu',
        header: 'Menunggu',
        meta: { label: 'Menunggu', angka: true, prioritas: 'penting' },
    },
    {
        id: 'Diproses',
        accessorKey: 'Diproses',
        header: 'Diproses',
        meta: { label: 'Diproses', angka: true, prioritas: 'penting' },
    },
    {
        id: 'UmurTertuaDetik',
        accessorKey: 'UmurTertuaDetik',
        header: 'Umur tertua',
        meta: { label: 'Umur tertua', prioritas: 'penting' },
        cell: ({ row }) => FormatDurasi(row.original.UmurTertuaDetik),
    },
];

const NamaKelompokApi: Record<string, string> = { Pos: 'API POS', Pemilik: 'API Owner', ApiPublik: 'API publik' };

function TeksP95(baris: MetrikKelompok): string {
    if (baris.Jumlah === 0 || (baris.P95Ms === null && !baris.P95Lebih)) {
        return '—';
    }

    return baris.P95Lebih ? 'lebih dari 1.600 ms' : `paling lama ${String(baris.P95Ms)} ms`;
}

/** Status teks per kelompok API; aturan sama dengan alert di server (jumlah minimum, error rate, p95 POS). */
function StatusMetrik(
    baris: MetrikKelompok,
    metrik: Dasbor['Metrik'],
): { jenis: 'sukses' | 'peringatan' | 'bahaya' | 'netral'; teks: string } {
    if (baris.Jumlah < metrik.MinimumPermintaan) {
        return { jenis: 'netral', teks: 'Data sedikit' };
    }

    if (BandingkanDesimal(baris.ErrorRatePersen, metrik.BatasErrorRatePersen) >= 0) {
        return { jenis: 'bahaya', teks: 'Error tinggi' };
    }

    if (baris.Kelompok === 'Pos' && (baris.P95Lebih || (baris.P95Ms ?? 0) > metrik.BatasP95PosMs)) {
        return { jenis: 'peringatan', teks: 'Lambat' };
    }

    return { jenis: 'sukses', teks: 'Normal' };
}

function BuatKolomMetrik(metrik: Dasbor['Metrik']): KolomTabel<MetrikKelompok>[] {
    return [
        {
            id: 'Kelompok',
            accessorFn: (baris) => NamaKelompokApi[baris.Kelompok] ?? baris.Kelompok,
            header: 'API',
            enableSorting: false,
            meta: { label: 'API', prioritas: 'utama', wajib: true },
        },
        {
            id: 'Jumlah',
            accessorKey: 'Jumlah',
            header: 'Permintaan',
            enableSorting: false,
            meta: { label: 'Permintaan', angka: true, prioritas: 'penting' },
            cell: ({ row }) => row.original.Jumlah.toLocaleString('id-ID'),
        },
        {
            id: 'ErrorRate',
            header: 'Galat server (5xx)',
            enableSorting: false,
            meta: { label: 'Galat server (5xx)', angka: true, prioritas: 'penting' },
            cell: ({ row: { original: baris } }) =>
                `${baris.ErrorRatePersen.replace('.', ',')}% (${baris.GalatServer.toLocaleString('id-ID')})`,
        },
        {
            id: 'RataRata',
            header: 'Rata-rata',
            enableSorting: false,
            meta: { label: 'Rata-rata', angka: true, prioritas: 'rendah' },
            cell: ({ row }) => (row.original.RataRataMs === null ? '—' : `${String(row.original.RataRataMs)} ms`),
        },
        {
            id: 'P95',
            header: 'p95',
            enableSorting: false,
            meta: { label: 'p95', prioritas: 'penting' },
            cell: ({ row }) => TeksP95(row.original),
        },
        {
            id: 'Status',
            header: 'Status',
            enableSorting: false,
            meta: { label: 'Status', prioritas: 'utama' },
            cell: ({ row }) => {
                const status = StatusMetrik(row.original, metrik);

                return <LabelStatus jenis={status.jenis} teks={status.teks} />;
            },
        },
    ];
}

const kolomOutboxMacet: KolomTabel<PerangkatMacet>[] = [
    {
        id: 'NamaTenant',
        accessorKey: 'NamaTenant',
        header: 'Tenant',
        enableSorting: false,
        meta: { label: 'Tenant', prioritas: 'utama', wajib: true },
    },
    {
        id: 'Perangkat',
        accessorFn: (baris) => `${baris.NamaPerangkat} ${baris.NamaOutlet}`,
        header: 'Perangkat',
        enableSorting: false,
        meta: { label: 'Perangkat', prioritas: 'utama' },
        cell: ({ row: { original: baris } }) => (
            <span className="flex flex-col">
                <span className="font-semibold break-words">{baris.NamaPerangkat}</span>
                <span className="text-keterangan text-teks-sekunder">{baris.NamaOutlet}</span>
            </span>
        ),
    },
    {
        id: 'JumlahOutbox',
        accessorKey: 'JumlahOutbox',
        header: 'Belum terkirim',
        enableSorting: false,
        meta: { label: 'Belum terkirim', angka: true, prioritas: 'penting' },
        cell: ({ row }) => `${row.original.JumlahOutbox.toLocaleString('id-ID')} transaksi`,
    },
    {
        id: 'TertundaSejak',
        accessorKey: 'TertundaSejak',
        header: 'Tertunda sejak',
        enableSorting: false,
        meta: { label: 'Tertunda sejak', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => (row.original.TertundaSejak ? FormatTanggalWaktu(row.original.TertundaSejak) : '—'),
    },
    {
        id: 'TerakhirAktifPada',
        accessorKey: 'TerakhirAktifPada',
        header: 'Terakhir aktif',
        enableSorting: false,
        meta: { label: 'Terakhir aktif', prioritas: 'rendah', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => (row.original.TerakhirAktifPada ? FormatTanggalWaktu(row.original.TerakhirAktifPada) : '—'),
    },
    {
        id: 'VersiAplikasi',
        accessorKey: 'VersiAplikasi',
        header: 'Versi',
        enableSorting: false,
        meta: { label: 'Versi', prioritas: 'rendah', kelasSel: 'font-mono text-label' },
        cell: ({ row }) => row.original.VersiAplikasi ?? '—',
    },
];

const kolomTugasGagal: KolomTabel<TugasGagal>[] = [
    {
        id: 'NamaTugas',
        accessorKey: 'NamaTugas',
        header: 'Job',
        meta: { label: 'Job', prioritas: 'utama', wajib: true, kelasSel: 'font-mono text-label' },
        cell: ({ row: { original: tugas } }) => (
            <Button
                asChild
                variant="link"
                className="h-auto p-0 font-mono text-label font-semibold break-all whitespace-normal"
            >
                <Link href={`/operasional/tugas-gagal/${tugas.Uuid}`}>{tugas.NamaTugas}</Link>
            </Button>
        ),
    },
    {
        id: 'GagalPada',
        accessorKey: 'GagalPada',
        header: 'Waktu gagal',
        meta: { label: 'Waktu gagal', prioritas: 'penting', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.GagalPada),
    },
    {
        id: 'Antrean',
        accessorKey: 'Antrean',
        header: 'Antrean',
        meta: { label: 'Antrean', prioritas: 'penting', kelasSel: 'font-mono text-label' },
    },
    {
        id: 'RingkasanGalat',
        accessorKey: 'RingkasanGalat',
        header: 'Galat',
        enableSorting: false,
        meta: { label: 'Galat', prioritas: 'rendah', kelasSel: 'text-keterangan break-all text-teks-sekunder' },
    },
];

const kolomBackup: KolomTabel<CatatanBackup>[] = [
    {
        id: 'SelesaiPada',
        accessorKey: 'SelesaiPada',
        header: 'Selesai',
        meta: { label: 'Selesai', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.SelesaiPada),
    },
    {
        id: 'Jenis',
        accessorKey: 'Jenis',
        header: 'Jenis',
        meta: { label: 'Jenis', prioritas: 'penting' },
        cell: ({ row }) => row.original.LabelJenis,
    },
    {
        id: 'Hasil',
        accessorKey: 'Hasil',
        header: 'Hasil',
        meta: { label: 'Hasil', prioritas: 'penting' },
        cell: ({ row }) => (
            <LabelStatus jenis={row.original.Hasil === 'Berhasil' ? 'sukses' : 'bahaya'} teks={row.original.Hasil} />
        ),
    },
    {
        id: 'UkuranByte',
        accessorKey: 'UkuranByte',
        header: 'Ukuran',
        meta: { label: 'Ukuran', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatUkuranBerkas(row.original.UkuranByte),
    },
    {
        id: 'Lokasi',
        header: 'Lokasi & keterangan',
        enableSorting: false,
        meta: {
            label: 'Lokasi & keterangan',
            prioritas: 'rendah',
            kelasSel: 'text-keterangan break-all text-teks-sekunder',
        },
        cell: ({ row: { original: catatan } }) => (
            <>
                {catatan.Lokasi ? <span className="block font-mono">{catatan.Lokasi}</span> : null}
                {catatan.Keterangan}
            </>
        ),
    },
    {
        id: 'DicatatOleh',
        accessorKey: 'DicatatOleh',
        header: 'Dicatat oleh',
        enableSorting: false,
        meta: { label: 'Dicatat oleh', prioritas: 'rendah' },
    },
];

/** Dasbor operasional dasar (P-11): scheduler, antrean, job gagal, backup, alert, kesehatan server. TabelData D-16. */
export default function DasborOperasional({ Dasbor }: { Dasbor: Dasbor }) {
    const { props } = usePage<PropsBersamaPengelola>();
    const bolehKelola = PunyaIzin(props.Pengguna, IzinPengelola.OperasionalKelola);
    const {
        Penjadwal,
        Antrean,
        TugasGagal,
        Backup,
        Alert,
        Kesehatan,
        Metrik,
        OutboxMacet,
        AlertWa,
        Lanjutan,
        Runbook,
    } = Dasbor;
    const kolomMetrik = BuatKolomMetrik(Metrik);

    return (
        <TataLetakPengelola judul="Operasional">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="max-w-3xl text-isi text-teks-sekunder">
                    Kondisi scheduler, antrean, backup, dan alert. Gangguan layanan yang perlu diumumkan ke pengguna
                    dicatat sebagai insiden.
                </p>
                <Button asChild variant="outline">
                    <Link href="/operasional/insiden">Insiden & halaman status</Link>
                </Button>
            </div>
            <div className="grid gap-4 md:grid-cols-3">
                <Ringkasan
                    judul="Scheduler"
                    sehat={Penjadwal.Sehat}
                    nilai={
                        Penjadwal.TerakhirPada
                            ? `Detak ${FormatDurasi(Penjadwal.UmurDetik)} lalu`
                            : 'Belum pernah berdetak'
                    }
                    keterangan={`Alert bila tidak berdetak lebih dari ${String(Penjadwal.BatasMenit)} menit.`}
                />
                <Ringkasan
                    judul="Antrean"
                    sehat={Antrean.Sehat}
                    nilai={
                        Antrean.UmurTertuaDetik === null
                            ? 'Kosong'
                            : `Job tertua ${FormatDurasi(Antrean.UmurTertuaDetik)}`
                    }
                    keterangan={`Alert bila job tertua lebih dari ${String(Antrean.BatasMenit)} menit.`}
                />
                <Ringkasan
                    judul="Backup"
                    sehat={Backup.Sehat}
                    nilai={
                        Backup.BackupTerakhir
                            ? `Berhasil ${FormatTanggalWaktu(Backup.BackupTerakhir.SelesaiPada)}`
                            : 'Belum ada backup berhasil'
                    }
                    keterangan={`Alert bila backup berhasil terakhir lebih dari ${String(Backup.BatasJam)} jam.`}
                />
            </div>

            <Bagian judul="Alert aktif">
                {Alert.Aktif.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">Tidak ada alert aktif.</p>
                ) : (
                    <DaftarAlertAktif alert={Alert.Aktif} runbook={Runbook.Tautan} />
                )}
            </Bagian>

            <Bagian judul="Antrean per nama">
                <TabelData
                    id="pengelola-operasional-antrean"
                    label="Job per antrean"
                    kolom={kolomAntrean}
                    sumber={{ mode: 'lokal', data: Antrean.PerAntrean }}
                    ambilIdBaris={(baris) => baris.Antrean}
                    kosong={{ ilustrasi: true, judul: 'Tidak ada job di antrean.' }}
                />
            </Bagian>

            <Bagian judul={`Error rate & p95 API (${String(Metrik.Jendela)} menit terakhir)`}>
                <p className="max-w-3xl text-label text-teks-sekunder">
                    Alert kritis bila galat server (5xx) mencapai {String(Metrik.BatasErrorRatePersen)}% dengan paling
                    sedikit {String(Metrik.MinimumPermintaan)} permintaan; peringatan bila p95 API POS melewati{' '}
                    {String(Metrik.BatasP95PosMs)} ms. Insiden baru terbuka setelah kondisi buruk bertahan{' '}
                    {String(Metrik.KonfirmasiMenit)} menit dan baru tertutup setelah sehat {String(Metrik.PulihMenit)}{' '}
                    menit, supaya gangguan sesaat tidak mengirim email berulang. p95 dibaca dari kelompok latensi, jadi
                    angkanya batas atas kelompok tempat persentil ke-95 jatuh. Data terlambat sampai satu menit.
                </p>
                <TabelData
                    id="pengelola-operasional-metrik-terkini"
                    label="Error rate dan p95 API menit terakhir"
                    kolom={kolomMetrik}
                    sumber={{ mode: 'lokal', data: Metrik.Terkini }}
                    ambilIdBaris={(baris) => baris.Kelompok}
                    kosong={{ ilustrasi: true, judul: 'Belum ada metrik API.' }}
                />
                <p className="text-label font-semibold text-teks-utama">Satu jam terakhir</p>
                <TabelData
                    id="pengelola-operasional-metrik-sejam"
                    label="Error rate dan p95 API satu jam terakhir"
                    kolom={kolomMetrik}
                    sumber={{ mode: 'lokal', data: Metrik.SatuJam }}
                    ambilIdBaris={(baris) => baris.Kelompok}
                    kosong={{ ilustrasi: true, judul: 'Belum ada metrik API.' }}
                />
            </Bagian>

            <Bagian judul={`Perangkat dengan outbox macet (${String(OutboxMacet.Daftar.length)})`}>
                <p className="max-w-3xl text-label text-teks-sekunder">
                    Transaksi sudah terjadi di kasir tetapi belum sampai ke server lebih dari{' '}
                    {String(OutboxMacet.BatasJam)} jam. Hubungi tenant agar kasir tersambung ke internet; jangan
                    mencabut perangkat sebelum outbox terkirim. Membuka daftar ini tercatat di log audit.
                </p>
                <TabelData
                    id="pengelola-operasional-outbox-macet"
                    label="Perangkat dengan outbox macet"
                    kolom={kolomOutboxMacet}
                    sumber={{ mode: 'lokal', data: OutboxMacet.Daftar }}
                    ambilIdBaris={(baris) => baris.Uuid}
                    cari="Cari tenant, outlet, atau perangkat"
                    kosong={{ ilustrasi: true, judul: 'Tidak ada perangkat dengan outbox macet.' }}
                />
            </Bagian>

            <PanelObservabilitasLanjutan data={Lanjutan} />

            <Bagian judul={`Job gagal (${String(TugasGagal.Total)})`}>
                <TabelData
                    id="pengelola-operasional-tugas-gagal"
                    label="Job gagal terbaru"
                    kolom={kolomTugasGagal}
                    sumber={{ mode: 'lokal', data: TugasGagal.Data }}
                    ambilIdBaris={(tugas) => tugas.Uuid}
                    cari="Cari nama job, antrean, atau galat"
                    saring={[
                        {
                            id: 'Antrean',
                            label: 'Antrean',
                            jenis: 'pilihanBanyak',
                            opsi: [...new Set(TugasGagal.Data.map((tugas) => tugas.Antrean))].map((nama) => ({
                                nilai: nama,
                                label: nama,
                            })),
                        },
                    ]}
                    alamatDetail={(tugas) => `/operasional/tugas-gagal/${tugas.Uuid}`}
                    kosong={{ ilustrasi: true, judul: 'Tidak ada job gagal.' }}
                />
            </Bagian>

            <Bagian judul="Backup & uji restore">
                <dl className="grid gap-2 text-label sm:grid-cols-2">
                    <div>
                        <dt className="text-teks-sekunder">Backup berhasil terakhir</dt>
                        <dd className="text-teks-utama">
                            {Backup.BackupTerakhir
                                ? `${FormatTanggalWaktu(Backup.BackupTerakhir.SelesaiPada)} | ${FormatUkuranBerkas(Backup.BackupTerakhir.UkuranByte)}`
                                : 'Belum ada'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-teks-sekunder">Uji restore terakhir</dt>
                        <dd className="text-teks-utama">
                            {Backup.UjiRestoreTerakhir
                                ? `${Backup.UjiRestoreTerakhir.Hasil} | ${FormatTanggalWaktu(Backup.UjiRestoreTerakhir.SelesaiPada)}`
                                : 'Belum pernah dicatat (wajib tiap bulan, §14.5)'}
                        </dd>
                    </div>
                </dl>
                {Backup.Riwayat.length > 0 ? (
                    <TabelData
                        id="pengelola-operasional-backup"
                        label="Riwayat backup & uji restore"
                        kolom={kolomBackup}
                        sumber={{ mode: 'lokal', data: Backup.Riwayat }}
                        ambilIdBaris={(catatan) => catatan.Uuid}
                        saring={[
                            {
                                id: 'Jenis',
                                label: 'Jenis',
                                jenis: 'pilihanBanyak',
                                opsi: [
                                    { nilai: 'Backup', label: 'Backup' },
                                    { nilai: 'UjiRestore', label: 'Uji restore' },
                                ],
                            },
                            {
                                id: 'Hasil',
                                label: 'Hasil',
                                jenis: 'pilihanBanyak',
                                opsi: [
                                    { nilai: 'Berhasil', label: 'Berhasil' },
                                    { nilai: 'Gagal', label: 'Gagal' },
                                ],
                            },
                        ]}
                        kosong={{ ilustrasi: true, judul: 'Belum ada catatan backup.' }}
                    />
                ) : null}
                {bolehKelola ? <FormCatatBackup /> : null}
            </Bagian>

            <Bagian judul="Kesehatan server">
                <dl className="grid gap-2 text-label sm:grid-cols-3">
                    <div>
                        <dt className="text-teks-sekunder">Ukuran database</dt>
                        <dd className="text-teks-utama">{FormatUkuranBerkas(Kesehatan.UkuranDatabaseByte)}</dd>
                    </div>
                    <div>
                        <dt className="text-teks-sekunder">Ruang disk tersisa</dt>
                        <dd className="flex flex-col gap-1 text-teks-utama">
                            {Kesehatan.Disk
                                ? `${FormatUkuranBerkas(Kesehatan.Disk.SisaByte)} dari ${FormatUkuranBerkas(Kesehatan.Disk.TotalByte)}`
                                : 'Tidak tersedia di hosting ini'}
                            {Kesehatan.Disk ? (
                                <Progress
                                    value={HitungPersenDiskTerpakai(Kesehatan.Disk)}
                                    aria-label={`Disk terpakai ${String(HitungPersenDiskTerpakai(Kesehatan.Disk))}%`}
                                    className="max-w-60"
                                />
                            ) : null}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-teks-sekunder">Integrasi (P-05)</dt>
                        <dd className="text-teks-utama">
                            {Kesehatan.PeringatanIntegrasi.length === 0
                                ? 'Tidak ada masalah'
                                : `${String(Kesehatan.PeringatanIntegrasi.length)} peringatan`}
                            {PunyaIzin(props.Pengguna, IzinPengelola.IntegrasiLihat) ? (
                                <>
                                    {' | '}
                                    <Button asChild variant="link" className="h-auto p-0 text-label font-semibold">
                                        <Link href="/integrasi">Buka integrasi</Link>
                                    </Button>
                                </>
                            ) : null}
                        </dd>
                    </div>
                </dl>
            </Bagian>

            <Bagian judul="Penerima alert lewat WhatsApp">
                <p className="max-w-3xl text-label text-teks-sekunder">
                    Alert kritis dikirim ke nomor-nomor ini lewat WhatsApp Payoung, di samping email ke Teknis. Alert
                    peringatan hanya lewat email. Kosongkan untuk mematikan alert WhatsApp.
                </p>
                {bolehKelola ? (
                    <FormNomorWaAlert nomor={AlertWa.Nomor} maks={AlertWa.Maks} />
                ) : (
                    <p className="text-isi text-teks-utama">
                        {AlertWa.Nomor.length === 0 ? 'Belum ada nomor penerima.' : AlertWa.Nomor.join(', ')}
                    </p>
                )}
            </Bagian>

            <Bagian judul="Runbook alert">
                <p className="max-w-3xl text-label text-teks-sekunder">
                    Alamat dokumen runbook Teknis (https://). Tiap alert menautkan jangkar sesuai namanya, misalnya{' '}
                    <code>#penjadwal-berhenti</code>. Panduan isi runbook ada di{' '}
                    <code>Panduan/RunbookOperasional.md</code>. Kosongkan untuk menyembunyikan tautan.
                </p>
                {bolehKelola ? (
                    <FormTautanRunbook tautan={Runbook.Tautan} />
                ) : (
                    <p className="text-isi break-all text-teks-utama">{Runbook.Tautan ?? 'Belum diatur.'}</p>
                )}
            </Bagian>

            <Bagian judul="Riwayat alert">
                {Alert.Riwayat.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">Belum ada alert yang selesai.</p>
                ) : (
                    <DaftarAlert alert={Alert.Riwayat} runbook={Runbook.Tautan} />
                )}
            </Bagian>
        </TataLetakPengelola>
    );
}

function Ringkasan({
    judul,
    sehat,
    nilai,
    keterangan,
}: {
    judul: string;
    sehat: boolean;
    nilai: string;
    keterangan: string;
}) {
    return (
        <Card className="gap-1 py-4 rounded-panel shadow-none">
            <CardHeader className="px-4">
                <CardTitle>
                    <h2 className="text-subjudul font-semibold text-teks-utama">{judul}</h2>
                </CardTitle>
                <CardAction>
                    <LabelStatus jenis={sehat ? 'sukses' : 'bahaya'} teks={sehat ? 'Normal' : 'Bermasalah'} />
                </CardAction>
            </CardHeader>
            <CardContent className="flex flex-col gap-1 px-4">
                <p className="text-isi text-teks-utama">{nilai}</p>
                <CardDescription className="text-keterangan text-teks-sekunder">{keterangan}</CardDescription>
            </CardContent>
        </Card>
    );
}

function Bagian({ judul, children }: { judul: string; children: ReactNode }) {
    return (
        <Card className="gap-3 py-4 rounded-panel shadow-none">
            <CardHeader className="px-4">
                <CardTitle>
                    <h2 className="text-subjudul font-semibold text-teks-utama">{judul}</h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3 px-4">{children}</CardContent>
        </Card>
    );
}

function TeksWaktuAlert({ baris }: { baris: Alert }) {
    return (
        <>
            {FormatTanggalWaktu(baris.MulaiPada)}
            {baris.SelesaiPada ? ` – ${FormatTanggalWaktu(baris.SelesaiPada)}` : ''}
        </>
    );
}

function TeksEmailAlert({ baris }: { baris: Alert }) {
    const email = baris.EmailTerkirimPada
        ? `Email ke Teknis terkirim ${FormatTanggalWaktu(baris.EmailTerkirimPada)}`
        : 'Email belum terkirim (dicoba lagi pada pemeriksaan berikutnya)';

    if (baris.Tingkat !== 'Kritis') {
        return email;
    }

    return `${email} | ${
        baris.WaTerkirimPada
            ? `WhatsApp terkirim ${FormatTanggalWaktu(baris.WaTerkirimPada)}`
            : 'WhatsApp belum terkirim'
    }`;
}

/** Alert yang masih terbuka: kotak Alert per kejadian, tingkat selalu tertulis (tidak hanya warna). */
function TautanRunbookAlert({ runbook, baris }: { runbook: string | null; baris: Alert }) {
    const alamat = AlamatRunbookAlert(runbook, baris.Jangkar);

    return alamat === null ? null : (
        <Button asChild variant="link" className="h-auto p-0 text-label font-semibold">
            <a href={alamat} target="_blank" rel="noreferrer">
                Runbook
            </a>
        </Button>
    );
}

function DaftarAlertAktif({ alert, runbook }: { alert: Alert[]; runbook: string | null }) {
    return (
        <ul className="flex flex-col gap-2">
            {alert.map((baris) => (
                <li key={baris.Id}>
                    <KotakPeringatan
                        variant={baris.Tingkat === 'Kritis' ? 'destructive' : 'default'}
                        className={cn(baris.Tingkat === 'Kritis' ? 'border-bahaya' : 'border-peringatan')}
                    >
                        <JudulPeringatan className="flex flex-wrap items-center gap-2 text-label">
                            <LabelStatus
                                jenis={baris.Tingkat === 'Kritis' ? 'bahaya' : 'peringatan'}
                                teks={baris.Tingkat}
                            />
                            <span className="font-semibold text-teks-utama">{baris.Label}</span>
                            <span className="font-normal text-teks-sekunder">
                                <TeksWaktuAlert baris={baris} />
                            </span>
                        </JudulPeringatan>
                        <IsiPeringatan>
                            <p className="text-isi text-teks-utama">{baris.Pesan}</p>
                            <p className="text-keterangan text-teks-sekunder">
                                <TeksEmailAlert baris={baris} />
                            </p>
                            <TautanRunbookAlert runbook={runbook} baris={baris} />
                        </IsiPeringatan>
                    </KotakPeringatan>
                </li>
            ))}
        </ul>
    );
}

function DaftarAlert({ alert, runbook }: { alert: Alert[]; runbook: string | null }) {
    return (
        <ul className="flex flex-col gap-2">
            {alert.map((baris, indeks) => (
                <li key={baris.Id} className="flex flex-col gap-1">
                    {indeks > 0 ? <Separator className="mb-1" /> : null}
                    <p className="flex flex-wrap items-center gap-2 text-label">
                        <LabelStatus
                            jenis={baris.Tingkat === 'Kritis' ? 'bahaya' : 'peringatan'}
                            teks={baris.Tingkat}
                        />
                        <span className="font-semibold text-teks-utama">{baris.Label}</span>
                        <span className="text-teks-sekunder">
                            <TeksWaktuAlert baris={baris} />
                        </span>
                    </p>
                    <p className="text-isi text-teks-utama">{baris.Pesan}</p>
                    <p className="text-keterangan text-teks-sekunder">
                        <TeksEmailAlert baris={baris} />
                    </p>
                    <TautanRunbookAlert runbook={runbook} baris={baris} />
                </li>
            ))}
        </ul>
    );
}

function FormCatatBackup() {
    const formulir = useForm({
        Jenis: 'UjiRestore',
        Hasil: 'Berhasil',
        SelesaiPada: '',
        UkuranMb: '',
        Lokasi: '',
        Keterangan: '',
    });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/operasional/backup', { preserveScroll: true, onSuccess: () => formulir.reset() });
    };

    return (
        <form onSubmit={Kirim} className="grid gap-3 sm:grid-cols-3" noValidate>
            <Separator className="sm:col-span-3" />
            <p className="text-label font-semibold text-teks-utama sm:col-span-3">Catat hasil secara manual</p>
            <BidangPilihan
                label="Jenis"
                nilai={formulir.data.Jenis}
                opsi={[
                    { Nilai: 'UjiRestore', Label: 'Uji restore' },
                    { Nilai: 'Backup', Label: 'Backup' },
                ]}
                saatBerubah={(nilai) => formulir.setData('Jenis', nilai)}
                galat={formulir.errors.Jenis}
                required
            />
            <BidangPilihan
                label="Hasil"
                nilai={formulir.data.Hasil}
                opsi={[
                    { Nilai: 'Berhasil', Label: 'Berhasil' },
                    { Nilai: 'Gagal', Label: 'Gagal' },
                ]}
                saatBerubah={(nilai) => formulir.setData('Hasil', nilai)}
                galat={formulir.errors.Hasil}
                required
            />
            <BidangWaktu
                label="Selesai pada (WIB)"
                nilai={formulir.data.SelesaiPada}
                saatBerubah={(nilai) => formulir.setData('SelesaiPada', nilai)}
                galat={formulir.errors.SelesaiPada}
            />
            <BidangTeks
                label="Ukuran (MB)"
                nilai={formulir.data.UkuranMb}
                inputMode="numeric"
                saatBerubah={(nilai) => formulir.setData('UkuranMb', nilai)}
                galat={formulir.errors.UkuranMb}
            />
            <BidangTeks
                label="Lokasi (path, tanpa kredensial)"
                nilai={formulir.data.Lokasi}
                kode
                saatBerubah={(nilai) => formulir.setData('Lokasi', nilai)}
                galat={formulir.errors.Lokasi}
            />
            <BidangTeks
                label="Keterangan"
                nilai={formulir.data.Keterangan}
                saatBerubah={(nilai) => formulir.setData('Keterangan', nilai)}
                galat={formulir.errors.Keterangan}
            />
            <div className="sm:col-span-3">
                <Tombol type="submit" varian="sekunder" memproses={formulir.processing}>
                    Simpan catatan
                </Tombol>
            </div>
        </form>
    );
}

function FormNomorWaAlert({ nomor, maks }: { nomor: string[]; maks: number }) {
    const formulir = useForm({ NomorWa: nomor.join('\n') });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/operasional/nomor-wa-alert', { preserveScroll: true });
    };

    return (
        <form onSubmit={Kirim} className="grid gap-3" noValidate>
            <BidangTeksPanjang
                label={`Nomor WhatsApp (satu per baris, maksimal ${String(maks)})`}
                nilai={formulir.data.NomorWa}
                baris={3}
                kode
                saatBerubah={(nilai) => formulir.setData('NomorWa', nilai)}
                galat={formulir.errors.NomorWa}
            />
            <div>
                <Tombol type="submit" varian="sekunder" memproses={formulir.processing}>
                    Simpan nomor penerima
                </Tombol>
            </div>
        </form>
    );
}

/** Tanggal & jam lokal (setara `datetime-local`); nilai string dikirim apa adanya. */
function BidangWaktu({
    label,
    nilai,
    saatBerubah,
    galat,
}: {
    label: string;
    nilai: string;
    saatBerubah: (nilai: string) => void;
    galat?: string | undefined;
}) {
    return <PemilihTanggalWaktu label={label} nilai={nilai} saatBerubah={saatBerubah} galat={galat} />;
}
