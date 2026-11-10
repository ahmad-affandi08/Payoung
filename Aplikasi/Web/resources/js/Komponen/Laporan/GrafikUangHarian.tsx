import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';

import { ChartContainer, ChartTooltip, ChartTooltipContent, type ChartConfig } from '@/Komponen/Ui/chart';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { AmbilTinggiGrafik } from '@/Pustaka/Laporan';
import type { TitikUang } from '@/Tipe/Laporan';

const konfigurasi = {
    Masuk: { label: 'Uang masuk', color: 'var(--color-grafik-1)' },
    Keluar: { label: 'Uang keluar', color: 'var(--color-grafik-2)' },
} satisfies ChartConfig;

const namaBulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

/** Label sumbu: "7/10" untuk hari, "Okt 26" untuk bulan. */
export function LabelSumbuUang(kunci: string, satuan: 'Hari' | 'Bulan'): string {
    const [tahun = '', bulan = '', hari = ''] = kunci.split('-');

    if (satuan === 'Bulan') {
        return `${namaBulan[Number(bulan) - 1] ?? bulan} ${tahun.slice(2)}`;
    }

    return `${String(Number(hari))}/${String(Number(bulan))}`;
}

/** Label sumbu nilai ringkas: 1.250.000 → "1,3 jt", 25.000 → "25 rb". */
function FormatSumbu(nilai: number): string {
    if (nilai >= 1_000_000) {
        return `${(nilai / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} jt`;
    }

    return nilai >= 1_000
        ? `${(nilai / 1_000).toLocaleString('id-ID', { maximumFractionDigits: 0 })} rb`
        : String(nilai);
}

/**
 * Grafik batang uang masuk dan uang keluar per hari (atau per bulan bila rentangnya panjang). Dua seri memakai token
 * `grafik-1` dan `grafik-2`, tanpa gradien. Nilai juga tersedia sebagai daftar bagi pembaca layar.
 */
export default function GrafikUangHarian({
    titik,
    satuan,
    judul,
}: {
    titik: TitikUang[];
    satuan: 'Hari' | 'Bulan';
    judul: string;
}) {
    const data = titik.map((t) => ({
        ...t,
        TinggiMasuk: AmbilTinggiGrafik(t.Masuk),
        TinggiKeluar: AmbilTinggiGrafik(t.Keluar),
    }));
    const Label = (kunci: string): string => (satuan === 'Hari' ? FormatTanggal(kunci) : LabelSumbuUang(kunci, satuan));

    return (
        <figure className="flex flex-col gap-2">
            <figcaption className="sr-only">{judul}</figcaption>
            <ChartContainer config={konfigurasi} className="aspect-auto h-56 w-full" aria-hidden="true">
                <BarChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                    <CartesianGrid vertical={false} stroke="var(--color-garis)" />
                    <XAxis
                        dataKey="Kunci"
                        tickLine={false}
                        axisLine={false}
                        tickMargin={8}
                        tickFormatter={(kunci: string) => LabelSumbuUang(kunci, satuan)}
                        interval="preserveStartEnd"
                    />
                    <YAxis tickLine={false} axisLine={false} width={56} tickFormatter={FormatSumbu} />
                    <ChartTooltip
                        cursor={{ fill: 'var(--color-permukaan-redup)' }}
                        content={
                            <ChartTooltipContent
                                labelFormatter={(_, isi) => Label(String(isi[0]?.payload?.Kunci ?? ''))}
                                formatter={(_, nama, item) => (
                                    <span className="tabular-nums">
                                        {nama === 'TinggiMasuk' ? 'Uang masuk' : 'Uang keluar'}:{' '}
                                        {FormatRupiah(
                                            String(
                                                nama === 'TinggiMasuk'
                                                    ? (item.payload?.Masuk ?? '0')
                                                    : (item.payload?.Keluar ?? '0'),
                                            ),
                                        )}
                                    </span>
                                )}
                            />
                        }
                    />
                    <Bar dataKey="TinggiMasuk" fill="var(--color-Masuk)" radius={[4, 4, 0, 0]} maxBarSize={20} />
                    <Bar dataKey="TinggiKeluar" fill="var(--color-Keluar)" radius={[4, 4, 0, 0]} maxBarSize={20} />
                </BarChart>
            </ChartContainer>
            <ul className="sr-only">
                {titik.map((t) => (
                    <li key={t.Kunci}>
                        {Label(t.Kunci)}: masuk {FormatRupiah(t.Masuk)}, keluar {FormatRupiah(t.Keluar)}
                    </li>
                ))}
            </ul>
        </figure>
    );
}
