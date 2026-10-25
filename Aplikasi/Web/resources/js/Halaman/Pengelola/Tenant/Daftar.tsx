import { Link } from '@inertiajs/react';

import AksiMassalTenant from '@/Komponen/Pengelola/Tenant/AksiMassalTenant';
import LabelKesehatan from '@/Komponen/Pengelola/Tenant/LabelKesehatan';
import { LabelPenanda, LabelStatusLangganan } from '@/Komponen/Pengelola/Tenant/LabelLangganan';
import TabTenant from '@/Komponen/Pengelola/Tenant/TabTenant';
import TabelData, { type KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { HasilTabel, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import type { Pilihan } from '@/Tipe/Pengelola';
import type { BarisTenant } from '@/Tipe/TenantPengelola';

type PropsDaftar = {
    Tenant: HasilTabel<BarisTenant>;
    PilihanStatus: Pilihan[];
    PilihanPenanda: Pilihan[];
    PilihanKesehatan: Pilihan[];
    PilihanPaket: Pilihan[];
    PilihanKategoriPenangguhan: Pilihan[];
};

const kolom: KolomTabel<BarisTenant>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Tenant',
        meta: { label: 'Tenant', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: tenant } }) => (
            <>
                <Link href={`/tenant/${tenant.Uuid}`} className="font-semibold text-brand underline">
                    {tenant.Nama}
                </Link>
                <span className="block font-mono text-keterangan font-normal text-teks-sekunder">{tenant.Slug}</span>
            </>
        ),
    },
    {
        id: 'Owner',
        header: 'Owner',
        enableSorting: false,
        meta: { label: 'Owner', prioritas: 'penting', kelasSel: 'break-all text-teks-sekunder' },
        cell: ({ row }) => row.original.EmailPemilik ?? '—',
    },
    {
        id: 'Paket',
        header: 'Paket & status',
        enableSorting: false,
        meta: { label: 'Paket & status', prioritas: 'penting' },
        cell: ({ row: { original: tenant } }) => (
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-mono text-label">{tenant.KodePaket ?? '—'}</span>
                <LabelStatusLangganan status={tenant.StatusLangganan} />
                <LabelPenanda penanda={tenant.Penanda} />
            </div>
        ),
    },
    {
        id: 'Kesehatan',
        header: 'Kesehatan',
        enableSorting: false,
        meta: { label: 'Kesehatan', prioritas: 'penting' },
        cell: ({ row: { original: tenant } }) => (
            <LabelKesehatan
                kategori={tenant.Kesehatan?.Kategori ?? null}
                teks={tenant.Kesehatan?.LabelKategori}
                skor={tenant.Kesehatan?.Skor}
            />
        ),
    },
    {
        id: 'TrialBerakhirPada',
        header: 'Trial berakhir',
        enableSorting: false,
        meta: { label: 'Trial berakhir', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row }) =>
            row.original.StatusLangganan === 'Trial' ? FormatTanggalWaktu(row.original.TrialBerakhirPada) : '—',
    },
    {
        id: 'DibuatPada',
        accessorKey: 'DibuatPada',
        header: 'Terdaftar',
        meta: { label: 'Terdaftar', prioritas: 'rendah', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.DibuatPada),
    },
];

/** Daftar tenant (P-07, TabelData D-16): cari nama, slug, atau email Owner; saring status langganan & penanda. */
export default function Daftar({
    Tenant,
    PilihanStatus,
    PilihanPenanda,
    PilihanKesehatan,
    PilihanPaket,
    PilihanKategoriPenangguhan,
}: PropsDaftar) {
    return (
        <TataLetakPengelola judul="Tenant">
            <TabTenant />
            <TabelData
                id="pengelola-tenant"
                label="Daftar tenant"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: '/tenant', awal: Tenant }}
                ambilIdBaris={(tenant) => tenant.Uuid}
                aksiMassal={(konteks: KonteksAksiMassal<BarisTenant>) => (
                    <AksiMassalTenant konteks={konteks} pilihanKategori={PilihanKategoriPenangguhan} />
                )}
                urutBawaan="-DibuatPada"
                cari="Cari nama usaha, slug, atau email Owner"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status langganan',
                        jenis: 'pilihanBanyak',
                        opsi: PilihanStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
                    },
                    {
                        id: 'Paket',
                        label: 'Paket',
                        jenis: 'pilihanBanyak',
                        opsi: PilihanPaket.map((o) => ({ nilai: o.Nilai, label: o.Label })),
                    },
                    {
                        id: 'Kesehatan',
                        label: 'Kesehatan',
                        jenis: 'pilihanBanyak',
                        opsi: PilihanKesehatan.map((o) => ({ nilai: o.Nilai, label: o.Label })),
                    },
                    {
                        id: 'Penanda',
                        label: 'Penanda',
                        jenis: 'pilihanBanyak',
                        opsi: PilihanPenanda.map((o) => ({ nilai: o.Nilai, label: o.Label })),
                    },
                ]}
                alamatDetail={(tenant) => `/tenant/${tenant.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada tenant. Tenant muncul di sini setelah calon pelanggan mendaftar dari halaman Daftar Gratis.',
                }}
            />
        </TataLetakPengelola>
    );
}
