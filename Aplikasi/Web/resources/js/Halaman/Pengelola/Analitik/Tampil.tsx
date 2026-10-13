import { KolomBilangan } from '@/Komponen/Laporan/KolomLaporan';
import SaringLaporan from '@/Komponen/Laporan/SaringLaporan';
import { kelasDaftarTabPanel, kelasItemTabPanel } from '@/Komponen/Navigasi/TabTautan';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Card } from '@/Komponen/Ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Komponen/Ui/tabs';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import type {
    BarisAdopsi,
    BarisFunnelSektor,
    BarisRetensi,
    PropsAnalitikPlatform,
    TahapFunnel,
} from '@/Tipe/AnalitikPlatform';

/** "45.5" dari server → "45,5%". */
function FormatPersen(nilai: string): string {
    return `${nilai.replace('.', ',')}%`;
}

function FormatHari(nilai: string | null): string {
    return nilai === null ? '-' : `${nilai.replace('.', ',')} hari`;
}

const kolomTahap: KolomTabel<TahapFunnel>[] = [
    { id: 'Label', accessorKey: 'Label', header: 'Tahap', meta: { label: 'Tahap', prioritas: 'utama', wajib: true } },
    KolomBilangan<TahapFunnel>('Jumlah', 'Tenant', 'utama'),
    {
        id: 'PersenDaftar',
        accessorKey: 'PersenDaftar',
        header: 'Dari pendaftar',
        meta: { label: 'Dari pendaftar', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: t } }) => FormatPersen(t.PersenDaftar),
    },
    {
        id: 'PersenSebelumnya',
        accessorKey: 'PersenSebelumnya',
        header: 'Dari tahap sebelumnya',
        meta: { label: 'Dari tahap sebelumnya', angka: true, prioritas: 'rendah' },
        cell: ({ row: { original: t } }) => (t.PersenSebelumnya === null ? '-' : FormatPersen(t.PersenSebelumnya)),
    },
    {
        id: 'RataRataHari',
        accessorKey: 'RataRataHari',
        header: 'Rata-rata sejak daftar',
        meta: { label: 'Rata-rata sejak daftar', angka: true, prioritas: 'rendah' },
        cell: ({ row: { original: t } }) => FormatHari(t.RataRataHari),
    },
];

const kolomFunnelSektor: KolomTabel<BarisFunnelSektor>[] = [
    {
        id: 'Sektor',
        accessorKey: 'Sektor',
        header: 'Sektor',
        meta: { label: 'Sektor', prioritas: 'utama', wajib: true },
    },
    KolomBilangan<BarisFunnelSektor>('Daftar', 'Mendaftar', 'utama'),
    KolomBilangan<BarisFunnelSektor>('Panduan', 'Panduan selesai', 'rendah'),
    KolomBilangan<BarisFunnelSektor>('Transaksi', 'Transaksi pertama', 'penting'),
    KolomBilangan<BarisFunnelSektor>('Bayar', 'Berbayar', 'penting'),
    {
        id: 'PersenBayar',
        accessorKey: 'PersenBayar',
        header: 'Konversi berbayar',
        meta: { label: 'Konversi berbayar', angka: true, prioritas: 'penting' },
        sortingFn: (a, b) => Number(a.original.PersenBayar) - Number(b.original.PersenBayar),
        cell: ({ row: { original: t } }) => FormatPersen(t.PersenBayar),
    },
];

function Angka({ label, nilai, keterangan }: { label: string; nilai: string; keterangan?: string }) {
    return (
        <Card className="flex flex-col gap-1 p-4 rounded-panel shadow-none">
            <span className="text-keterangan text-teks-sekunder">{label}</span>
            <span className="text-subjudul font-semibold text-teks-utama tabular-nums">{nilai}</span>
            {keterangan ? <span className="text-keterangan text-teks-sekunder">{keterangan}</span> : null}
        </Card>
    );
}

/**
 * P-07 PGL-24 (PRD v5.99): analitik platform — funnel daftar → panduan → transaksi → bayar, adopsi fitur per sektor,
 * dan retensi kohort bulanan. Hanya angka agregat (tanpa nama tenant); tenant uji, demo, dan internal tidak dihitung.
 * Bahan dihitung ulang tiap pukul 03.50 WIB.
 */
export default function HalamanAnalitikPlatform({
    Saring,
    PilihanSektor,
    Funnel,
    Adopsi,
    Retensi,
    HariFitur,
}: PropsAnalitikPlatform) {
    const query: Record<string, string> = { dari: Saring.Dari, sampai: Saring.Sampai, sektor: Saring.Sektor };
    const daftar = Funnel.Tahap[0]?.Jumlah ?? 0;
    const berbayar = Funnel.Tahap[Funnel.Tahap.length - 1];

    const kolomAdopsi: KolomTabel<BarisAdopsi>[] = [
        {
            id: 'Sektor',
            accessorKey: 'Sektor',
            header: 'Sektor',
            meta: { label: 'Sektor', prioritas: 'utama', wajib: true },
        },
        KolomBilangan<BarisAdopsi>('TenantAktif', 'Tenant aktif', 'utama'),
        ...Adopsi.Fitur.map((f): KolomTabel<BarisAdopsi> => ({
            id: f.Kunci,
            header: f.Label,
            enableSorting: false,
            meta: { label: f.Label, angka: true, prioritas: 'penting' },
            cell: ({ row: { original: b } }) => {
                const sel = b.Fitur[f.Kunci];

                return sel === undefined ? '-' : `${FormatPersen(sel.Persen)} (${String(sel.Jumlah)})`;
            },
        })),
    ];

    const kolomRetensi: KolomTabel<BarisRetensi>[] = [
        {
            id: 'Label',
            accessorKey: 'Label',
            header: 'Kohort',
            meta: { label: 'Kohort', prioritas: 'utama', wajib: true },
        },
        KolomBilangan<BarisRetensi>('Ukuran', 'Tenant', 'utama'),
        ...Retensi.Bulan.map((judul, indeks): KolomTabel<BarisRetensi> => ({
            id: `Bulan${String(indeks)}`,
            header: judul,
            enableSorting: false,
            meta: { label: judul, angka: true, prioritas: indeks < 4 ? 'penting' : 'rendah' },
            cell: ({ row: { original: b } }) => {
                const sel = b.Retensi[indeks];

                return sel === null || sel === undefined || b.Ukuran === 0 ? '-' : FormatPersen(sel.Persen);
            },
        })),
    ];

    return (
        <TataLetakPengelola judul="Analitik platform">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Angka agregat dari semua tenant tanpa nama atau data pribadi. Tenant uji, demo, dan internal tidak
                dihitung. Bahan dihitung ulang tiap pukul 03.50 WIB, jadi transaksi hari ini baru tampil besok.
            </p>

            <SaringLaporan
                alamat="/analitik"
                query={query}
                pilihan={[{ kunci: 'sektor', label: 'Sektor', kosong: 'Semua sektor', opsi: PilihanSektor }]}
            />

            <Tabs defaultValue="funnel" className="gap-4">
                <div className="overflow-x-auto">
                    <TabsList aria-label="Bagian analitik platform" className={kelasDaftarTabPanel}>
                        <TabsTrigger value="funnel" className={kelasItemTabPanel}>
                            Funnel pendaftaran
                        </TabsTrigger>
                        <TabsTrigger value="adopsi" className={kelasItemTabPanel}>
                            Adopsi fitur
                        </TabsTrigger>
                        <TabsTrigger value="retensi" className={kelasItemTabPanel}>
                            Retensi kohort
                        </TabsTrigger>
                    </TabsList>
                </div>

                <TabsContent value="funnel" className="flex flex-col gap-4">
                    <p className="max-w-3xl text-isi text-teks-sekunder">
                        Periode di atas memilih tanggal pendaftaran tenant. Tanpa periode, semua tenant dihitung.
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Angka label="Mendaftar" nilai={String(daftar)} />
                        <Angka
                            label="Sudah bertransaksi"
                            nilai={FormatPersen(Funnel.Tahap[2]?.PersenDaftar ?? '0.0')}
                            keterangan={`${String(Funnel.Tahap[2]?.Jumlah ?? 0)} tenant`}
                        />
                        <Angka
                            label="Berlangganan berbayar"
                            nilai={FormatPersen(berbayar?.PersenDaftar ?? '0.0')}
                            keterangan={`${String(berbayar?.Jumlah ?? 0)} tenant`}
                        />
                        <Angka
                            label="Waktu ke berbayar"
                            nilai={FormatHari(berbayar?.RataRataHari ?? null)}
                            keterangan="rata-rata sejak daftar"
                        />
                    </div>
                    <TabelData
                        id="analitik-funnel"
                        label="Tahap funnel"
                        kolom={kolomTahap}
                        sumber={{ mode: 'lokal', data: Funnel.Tahap }}
                        ambilIdBaris={(b) => b.Kunci}
                        cari={false}
                        kosong={{ ilustrasi: true, judul: 'Belum ada tenant.' }}
                    />
                    <h2 className="text-subjudul font-semibold text-teks-utama">Funnel per sektor</h2>
                    <TabelData
                        id="analitik-funnel-sektor"
                        label="Funnel per sektor"
                        kolom={kolomFunnelSektor}
                        sumber={{ mode: 'lokal', data: Funnel.PerSektor }}
                        ambilIdBaris={(b) => b.Kunci}
                        urutBawaan="-Daftar"
                        cari={false}
                        kosong={{ ilustrasi: true, judul: 'Belum ada tenant pada periode ini.' }}
                    />
                </TabsContent>

                <TabsContent value="adopsi" className="flex flex-col gap-3">
                    <p className="max-w-3xl text-isi text-teks-sekunder">
                        Dari tenant yang menjual dalam {String(HariFitur)} hari terakhir, berapa persen yang memakai
                        tiap fitur (opname, pesanan pembelian, dan pelanggan baru dalam {String(HariFitur)} hari; promo
                        dan karyawan cukup ada). Angka dalam kurung = jumlah tenant.
                    </p>
                    <TabelData
                        id="analitik-adopsi"
                        label="Adopsi fitur per sektor"
                        kolom={kolomAdopsi}
                        sumber={{ mode: 'lokal', data: Adopsi.Baris }}
                        ambilIdBaris={(b) => b.Kunci}
                        cari={false}
                        kosong={{ ilustrasi: true, judul: 'Belum ada tenant yang menjual dalam 30 hari terakhir.' }}
                    />
                </TabsContent>

                <TabsContent value="retensi" className="flex flex-col gap-3">
                    <p className="max-w-3xl text-isi text-teks-sekunder">
                        Kohort = bulan pendaftaran (12 bulan terakhir). M+0 = bulan daftar; M+n = persen tenant kohort
                        yang punya transaksi pada bulan ke-n sesudahnya. Bulan yang belum tiba ditandai &quot;-&quot;.
                    </p>
                    <TabelData
                        id="analitik-retensi"
                        label="Retensi kohort bulanan"
                        kolom={kolomRetensi}
                        sumber={{ mode: 'lokal', data: Retensi.Baris }}
                        ambilIdBaris={(b) => b.Kunci}
                        cari={false}
                        kosong={{ ilustrasi: true, judul: 'Belum ada kohort.' }}
                    />
                </TabsContent>
            </Tabs>
        </TataLetakPengelola>
    );
}
