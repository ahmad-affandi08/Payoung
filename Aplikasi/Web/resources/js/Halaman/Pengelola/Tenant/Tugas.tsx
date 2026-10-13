import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import LabelKesehatan from '@/Komponen/Pengelola/Tenant/LabelKesehatan';
import TabTenant from '@/Komponen/Pengelola/Tenant/TabTenant';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { HasilTabel, KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, PunyaIzin, type Pilihan, type PropsBersamaPengelola } from '@/Tipe/Pengelola';
import type { BarisTugasTenant, SebaranKesehatan } from '@/Tipe/TenantPengelola';

type PropsTugas = {
    Tugas: HasilTabel<BarisTugasTenant>;
    Sebaran: SebaranKesehatan;
    PilihanJenis: Pilihan[];
};

const kolom: KolomTabel<BarisTugasTenant>[] = [
    {
        id: 'Tenant',
        header: 'Tenant',
        enableSorting: false,
        meta: { label: 'Tenant', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: t } }) => (
            <>
                {t.UuidTenant ? (
                    <Link href={`/tenant/${t.UuidTenant}`} className="font-semibold text-brand underline">
                        {t.NamaTenant}
                    </Link>
                ) : (
                    <span className="font-semibold">{t.NamaTenant}</span>
                )}
                <span className="block font-mono text-keterangan font-normal text-teks-sekunder">{t.Slug}</span>
            </>
        ),
    },
    {
        id: 'Jenis',
        accessorKey: 'Jenis',
        header: 'Tugas',
        enableSorting: false,
        meta: { label: 'Tugas', prioritas: 'utama' },
        cell: ({ row: { original: t } }) => (
            <span className="flex flex-col">
                <span className="font-semibold text-teks-utama">{t.LabelJenis}</span>
                <span className="text-keterangan break-words text-teks-sekunder">{t.Ringkasan}</span>
            </span>
        ),
    },
    {
        id: 'Kesehatan',
        header: 'Kesehatan',
        enableSorting: false,
        meta: { label: 'Kesehatan', prioritas: 'penting' },
        cell: ({ row: { original: t } }) => (
            <LabelKesehatan kategori={t.Kategori} teks={t.LabelKategori} skor={t.Skor} />
        ),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'utama' },
        cell: ({ row: { original: t } }) => (
            <span className="flex flex-col gap-1">
                <LabelStatus
                    jenis={t.Status === 'Terbuka' ? 'peringatan' : 'sukses'}
                    teks={
                        t.Status === 'Terbuka'
                            ? 'Terbuka'
                            : t.CaraSelesai === 'Manual'
                              ? 'Sudah dihubungi'
                              : 'Pulih sendiri'
                    }
                />
                {t.CatatanSelesai ? (
                    <span className="text-keterangan break-words text-teks-sekunder">{t.CatatanSelesai}</span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'DibuatPada',
        accessorKey: 'DibuatPada',
        header: 'Dibuat',
        meta: { label: 'Dibuat', prioritas: 'rendah', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.DibuatPada),
    },
];

/**
 * Tugas otomatis dari skor kesehatan tenant (P-07 PGL-09): tenant baru yang belum bertransaksi 48 jam setelah daftar dan
 * tenant lama yang berisiko. Tugas hilang sendiri bila kondisinya pulih; tim menandai "sudah dihubungi" dengan catatan,
 * dan tenant itu tidak muncul lagi selama 14 hari.
 */
export default function HalamanTugasTenant({ Tugas, Sebaran, PilihanJenis }: PropsTugas) {
    const { props } = usePage<PropsBersamaPengelola>();
    const bolehMenangani = PunyaIzin(props.Pengguna, IzinPengelola.TenantCatatanTulis);
    const [dipilih, AturDipilih] = useState<BarisTugasTenant | null>(null);

    return (
        <TataLetakPengelola judul="Tenant">
            <TabTenant />
            <section aria-label="Sebaran kesehatan tenant" className="grid gap-3 sm:grid-cols-4">
                <KartuSebaran judul="Sehat" nilai={Sebaran.Sehat} />
                <KartuSebaran judul="Perlu perhatian" nilai={Sebaran.PerluPerhatian} />
                <KartuSebaran judul="Berisiko" nilai={Sebaran.Berisiko} />
                <KartuSebaran judul="Tugas terbuka" nilai={Sebaran.TugasTerbuka} />
            </section>
            <p className="max-w-3xl text-isi text-teks-sekunder">
                {Sebaran.Tanggal === null
                    ? 'Skor belum pernah dihitung. Perhitungan berjalan setiap dini hari.'
                    : `Skor dihitung dini hari, terakhir untuk tanggal ${Sebaran.Tanggal}.`}
            </p>
            <TabelData
                id="pengelola-tugas-tenant"
                label="Tugas tenant"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: '/tugas-tenant', awal: Tugas }}
                ambilIdBaris={(t) => t.Uuid}
                labelBaris={(t) => `${t.LabelJenis} ${t.NamaTenant}`}
                urutBawaan="-DibuatPada"
                cari="Cari nama usaha atau slug"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: [
                            { nilai: 'Terbuka', label: 'Terbuka' },
                            { nilai: 'Selesai', label: 'Selesai' },
                        ],
                    },
                    {
                        id: 'Jenis',
                        label: 'Jenis tugas',
                        jenis: 'pilihanBanyak',
                        opsi: PilihanJenis.map((o) => ({ nilai: o.Nilai, label: o.Label })),
                    },
                ]}
                {...(bolehMenangani
                    ? {
                          aksiBaris: (t: BarisTugasTenant) =>
                              t.Status === 'Terbuka' ? (
                                  <DropdownMenuItem onSelect={() => AturDipilih(t)}>
                                      Tandai sudah dihubungi
                                  </DropdownMenuItem>
                              ) : null,
                      }
                    : {})}
                kosong={{
                    ilustrasi: true,
                    judul: 'Tidak ada tugas tenant. Tugas muncul otomatis dari skor kesehatan harian.',
                }}
            />
            {dipilih ? <FormSelesai tugas={dipilih} saatSelesai={() => AturDipilih(null)} /> : null}
        </TataLetakPengelola>
    );
}

function KartuSebaran({ judul, nilai }: { judul: string; nilai: number }) {
    return (
        <div className="flex flex-col rounded-panel border border-garis bg-permukaan p-4">
            <span className="text-keterangan text-teks-sekunder">{judul}</span>
            <span className="text-judul font-bold text-teks-utama tabular-nums">{nilai}</span>
        </div>
    );
}

function FormSelesai({ tugas, saatSelesai }: { tugas: BarisTugasTenant; saatSelesai: () => void }) {
    const { props } = usePage<PropsBersamaPengelola>();
    const [catatan, AturCatatan] = useState('');
    const [memproses, AturMemproses] = useState(false);

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.post(
            `/tugas-tenant/${tugas.Uuid}/selesai`,
            { Catatan: catatan },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: saatSelesai,
            },
        );
    };

    return (
        <DialogFormulir
            judul={`${tugas.LabelJenis}: ${tugas.NamaTenant}`}
            keterangan="Tenant ini tidak muncul lagi di daftar tugas selama 14 hari."
            galatUmum={props.errors.Umum}
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeksPanjang
                    label="Catatan"
                    nilai={catatan}
                    saatBerubah={AturCatatan}
                    galat={props.errors.Catatan}
                    baris={3}
                    maksimal={500}
                    required
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={memproses}>
                        Tandai sudah dihubungi
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
