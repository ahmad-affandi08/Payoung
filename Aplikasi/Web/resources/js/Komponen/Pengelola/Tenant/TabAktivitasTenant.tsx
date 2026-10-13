import { Link } from '@inertiajs/react';

import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { PerangkatTenantRingkas, TagihanTenantRingkas, TiketTenantRingkas } from '@/Tipe/TenantPengelola';

type BarisTagihan = TagihanTenantRingkas['Data'][number];
type BarisTiket = TiketTenantRingkas['Data'][number];
type BarisPerangkat = PerangkatTenantRingkas['Data'][number];

const AmbilJenisStatusTagihan = (status: string) =>
    status === 'Lunas' ? 'sukses' : status === 'JatuhTempo' ? 'bahaya' : status === 'Terbit' ? 'peringatan' : 'netral';

const kolomTagihan: KolomTabel<BarisTagihan>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        enableSorting: false,
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: t } }) => (
            <Link href={`/tagihan/${t.Uuid}`} className="font-mono font-semibold text-brand underline">
                {t.Nomor}
            </Link>
        ),
    },
    {
        id: 'Jenis',
        accessorKey: 'LabelJenis',
        header: 'Jenis',
        enableSorting: false,
        meta: { label: 'Jenis', prioritas: 'penting' },
    },
    {
        id: 'Total',
        accessorKey: 'Total',
        header: 'Total',
        enableSorting: false,
        meta: { label: 'Total', prioritas: 'utama', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.Total),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'utama' },
        cell: ({ row: { original: t } }) => (
            <LabelStatus jenis={AmbilJenisStatusTagihan(t.Status)} teks={t.LabelStatus} />
        ),
    },
    {
        id: 'JatuhTempoPada',
        accessorKey: 'JatuhTempoPada',
        header: 'Jatuh tempo',
        enableSorting: false,
        meta: { label: 'Jatuh tempo', prioritas: 'rendah', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggal(row.original.JatuhTempoPada),
    },
    {
        id: 'DibayarPada',
        accessorKey: 'DibayarPada',
        header: 'Dibayar',
        enableSorting: false,
        meta: { label: 'Dibayar', prioritas: 'rendah', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => (row.original.DibayarPada ? FormatTanggal(row.original.DibayarPada) : '—'),
    },
];

const kolomTiket: KolomTabel<BarisTiket>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Tiket',
        enableSorting: false,
        meta: { label: 'Tiket', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: t } }) => (
            <span className="flex flex-col">
                <Link href={`/dukungan/tiket/${t.Uuid}`} className="font-mono font-semibold text-brand underline">
                    {t.Nomor}
                </Link>
                <span className="text-keterangan break-words text-teks-sekunder">{t.Judul}</span>
            </span>
        ),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'utama' },
        cell: ({ row: { original: t } }) => (
            <span className="flex flex-wrap items-center gap-2">
                <LabelStatus
                    jenis={t.Status === 'Selesai' || t.Status === 'Ditutup' ? 'sukses' : 'peringatan'}
                    teks={t.LabelStatus}
                />
                {t.LewatSla ? <LabelStatus jenis="bahaya" teks="Lewat SLA" /> : null}
            </span>
        ),
    },
    {
        id: 'Prioritas',
        accessorKey: 'Prioritas',
        header: 'Prioritas',
        enableSorting: false,
        meta: { label: 'Prioritas', prioritas: 'penting' },
    },
    {
        id: 'DibuatPada',
        accessorKey: 'DibuatPada',
        header: 'Dibuat',
        enableSorting: false,
        meta: { label: 'Dibuat', prioritas: 'rendah', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.DibuatPada),
    },
];

const kolomPerangkat: KolomTabel<BarisPerangkat>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Perangkat',
        enableSorting: false,
        meta: { label: 'Perangkat', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: p } }) => (
            <span className="flex flex-col">
                <span className="font-semibold break-words text-teks-utama">{p.Nama}</span>
                <span className="font-mono text-keterangan text-teks-sekunder">{p.Kode}</span>
            </span>
        ),
    },
    {
        id: 'Outlet',
        header: 'Outlet & jenis',
        enableSorting: false,
        meta: { label: 'Outlet & jenis', prioritas: 'penting' },
        cell: ({ row: { original: p } }) => `${p.Outlet} | ${p.Jenis}`,
    },
    {
        id: 'Versi',
        header: 'Platform & versi',
        enableSorting: false,
        meta: { label: 'Platform & versi', prioritas: 'penting', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: p } }) =>
            p.Platform ? `${p.Platform}${p.VersiAplikasi ? ` ${p.VersiAplikasi}` : ''}` : '—',
    },
    {
        id: 'TerakhirAktifPada',
        header: 'Terakhir aktif',
        enableSorting: false,
        meta: { label: 'Terakhir aktif', prioritas: 'rendah', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => (row.original.TerakhirAktifPada ? FormatTanggalWaktu(row.original.TerakhirAktifPada) : '—'),
    },
    {
        id: 'Outbox',
        accessorKey: 'OutboxTertunda',
        header: 'Outbox tertunda',
        enableSorting: false,
        meta: { label: 'Outbox tertunda', prioritas: 'penting', angka: true },
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'utama' },
        cell: ({ row: { original: p } }) =>
            p.Dicabut ? (
                <LabelStatus jenis="netral" teks="Dicabut" />
            ) : p.LamaOffline ? (
                <LabelStatus jenis="peringatan" teks="Lama offline" />
            ) : (
                <LabelStatus jenis="sukses" teks="Aktif" />
            ),
    },
];

/** Tab Tagihan di tampilan 360° (P-07, P-08): tagihan terbaru dan yang masih menunggu pembayaran. */
export function TabTagihanTenant({ tagihan }: { tagihan: TagihanTenantRingkas }) {
    return (
        <Panel
            judul="Tagihan langganan"
            keterangan={
                tagihan.JumlahTerbuka === 0
                    ? 'Tidak ada tagihan yang menunggu pembayaran.'
                    : `${String(tagihan.JumlahTerbuka)} tagihan menunggu pembayaran, total ${FormatRupiah(tagihan.TotalTerbuka)}.`
            }
        >
            <TabelData
                id="pengelola-tenant-tagihan"
                label="Tagihan tenant"
                kolom={kolomTagihan}
                sumber={{ mode: 'lokal', data: tagihan.Data }}
                ambilIdBaris={(t) => t.Uuid}
                labelBaris={(t) => `Tagihan ${t.Nomor}`}
                kosong={{ judul: 'Belum ada tagihan untuk tenant ini.' }}
            />
        </Panel>
    );
}

/** Tab Tiket di tampilan 360° (P-07, P-09): tiket terbaru tenant dan yang lewat batas SLA. */
export function TabTiketTenant({ tiket }: { tiket: TiketTenantRingkas }) {
    return (
        <Panel
            judul="Tiket dukungan"
            keterangan={
                tiket.JumlahTerbuka === 0
                    ? 'Tidak ada tiket terbuka.'
                    : `${String(tiket.JumlahTerbuka)} tiket terbuka${tiket.JumlahLewatSla > 0 ? `, ${String(tiket.JumlahLewatSla)} lewat batas SLA` : ''}.`
            }
        >
            <TabelData
                id="pengelola-tenant-tiket"
                label="Tiket tenant"
                kolom={kolomTiket}
                sumber={{ mode: 'lokal', data: tiket.Data }}
                ambilIdBaris={(t) => t.Uuid}
                labelBaris={(t) => `Tiket ${t.Nomor}`}
                kosong={{ judul: 'Tenant ini belum pernah membuat tiket.' }}
            />
        </Panel>
    );
}

/** Tab Perangkat di tampilan 360° (P-07): platform, versi aplikasi, terakhir aktif, dan outbox tertunda. */
export function TabPerangkatTenant({ perangkat }: { perangkat: PerangkatTenantRingkas }) {
    return (
        <Panel
            judul="Perangkat POS"
            keterangan={`${String(perangkat.JumlahAktif)} perangkat aktif | ${String(perangkat.JumlahOffline)} lama offline (lebih dari 7 hari) | ${String(perangkat.TotalOutboxTertunda)} item outbox tertunda.`}
        >
            <TabelData
                id="pengelola-tenant-perangkat"
                label="Perangkat tenant"
                kolom={kolomPerangkat}
                sumber={{ mode: 'lokal', data: perangkat.Data }}
                ambilIdBaris={(p) => p.Uuid}
                labelBaris={(p) => `Perangkat ${p.Nama}`}
                kosong={{ judul: 'Belum ada perangkat kasir yang diaktifkan.' }}
            />
        </Panel>
    );
}
