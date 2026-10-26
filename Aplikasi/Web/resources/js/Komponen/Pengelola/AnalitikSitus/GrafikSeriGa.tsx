import { Bar, BarChart, CartesianGrid, Line, LineChart, XAxis, YAxis } from 'recharts';

import { ChartContainer, ChartTooltip, ChartTooltipContent, type ChartConfig } from '@/Komponen/Ui/chart';
import { FormatBilanganGa, FormatTanggalPendekGa } from '@/Pustaka/FormatAnalitikGoogle';

export type TitikGa = { Etiket: string; Nilai: number };

const konfigurasi = { Nilai: { label: 'Jumlah', color: 'var(--color-grafik-1)' } } satisfies ChartConfig;

/**
 * Grafik satu seri (garis untuk tren tanggal, batang untuk urutan menit) dengan tooltip. Nilai juga ada sebagai daftar
 * tersembunyi untuk pembaca layar. Warna dari token `grafik-1`, tanpa gradien.
 */
export default function GrafikSeriGa({
    data,
    judul,
    labelSeri,
    jenis,
}: {
    data: TitikGa[];
    judul: string;
    labelSeri: string;
    jenis: 'garis' | 'batang';
}) {
    const FormatEtiket = (etiket: string) => (jenis === 'garis' ? FormatTanggalPendekGa(etiket) : etiket);
    const margin = { top: 8, right: 8, left: 0, bottom: 0 };
    const sumbu = (
        <>
            <CartesianGrid vertical={false} stroke="var(--color-garis)" />
            <XAxis
                dataKey="Etiket"
                tickLine={false}
                axisLine={false}
                tickMargin={8}
                tickFormatter={FormatEtiket}
                interval="preserveStartEnd"
            />
            <YAxis
                tickLine={false}
                axisLine={false}
                width={48}
                allowDecimals={false}
                tickFormatter={(nilai: number) => FormatBilanganGa(String(nilai))}
            />
            <ChartTooltip
                cursor={{ fill: 'var(--color-permukaan-redup)' }}
                content={
                    <ChartTooltipContent
                        labelFormatter={(_, isi) => String(isi[0]?.payload?.Etiket ?? '')}
                        formatter={(nilai) => (
                            <span className="tabular-nums">
                                {labelSeri}: {FormatBilanganGa(String(nilai))}
                            </span>
                        )}
                    />
                }
            />
        </>
    );

    return (
        <figure className="flex flex-col gap-2">
            <figcaption className="sr-only">{judul}</figcaption>
            <ChartContainer config={konfigurasi} className="aspect-auto h-56 w-full" aria-hidden="true">
                {jenis === 'garis' ? (
                    <LineChart data={data} margin={margin}>
                        {sumbu}
                        <Line
                            dataKey="Nilai"
                            type="monotone"
                            stroke="var(--color-Nilai)"
                            strokeWidth={2}
                            dot={false}
                            isAnimationActive={false}
                        />
                    </LineChart>
                ) : (
                    <BarChart data={data} margin={margin}>
                        {sumbu}
                        <Bar dataKey="Nilai" fill="var(--color-Nilai)" radius={[4, 4, 0, 0]} maxBarSize={20} />
                    </BarChart>
                )}
            </ChartContainer>
            <ul className="sr-only">
                {data.map((titik) => (
                    <li key={titik.Etiket}>
                        {titik.Etiket}: {FormatBilanganGa(String(titik.Nilai))} {labelSeri}
                    </li>
                ))}
            </ul>
        </figure>
    );
}
