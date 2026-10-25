import type { TitikTrenMrr } from '@/Tipe/Pengelola';

/** Kode widget Beranda konsol (D-207), urut tampil. */
export type KodeWidgetPengelola =
    'angka' | 'mrr' | 'perhatian' | 'paket' | 'sektor' | 'risiko' | 'sistem' | 'kerja' | 'aktivitas' | 'pintasan';

export const DAFTAR_WIDGET_PENGELOLA: readonly { Kode: KodeWidgetPengelola; Judul: string }[] = [
    { Kode: 'angka', Judul: 'Angka utama' },
    { Kode: 'mrr', Judul: 'Pendapatan berulang bulanan (MRR)' },
    { Kode: 'perhatian', Judul: 'Perlu perhatian' },
    { Kode: 'paket', Judul: 'Pendapatan per paket' },
    { Kode: 'sektor', Judul: 'Tenant per sektor' },
    { Kode: 'risiko', Judul: 'Tenant berisiko' },
    { Kode: 'sistem', Judul: 'Kondisi sistem' },
    { Kode: 'kerja', Judul: 'Antrean kerja' },
    { Kode: 'aktivitas', Judul: 'Aktivitas terbaru' },
    { Kode: 'pintasan', Judul: 'Pintasan petugas' },
];

export const KUNCI_WIDGET_PENGELOLA = 'payoung.konsol.widget-tersembunyi';

const NAMA_BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

/** "2026-10" → "Okt"; bentuk lain dikembalikan apa adanya. */
export function NamaBulanSingkat(bulan: string): string {
    const cocok = /^\d{4}-(\d{2})$/.exec(bulan);
    const urutan = cocok === null ? -1 : Number(cocok[1]) - 1;

    return NAMA_BULAN[urutan] ?? bulan;
}

/** Tinggi batang 0–100 per titik tren, relatif terhadap yang tertinggi; nol tetap 0, selain itu minimal 3 supaya terlihat. */
export function HitungTinggiBatang(tren: readonly TitikTrenMrr[]): number[] {
    const angka = tren.map((t) => BigInt(/^\d+/.exec(t.Mrr)?.[0] ?? '0'));
    const tertinggi = angka.reduce((maks, n) => (n > maks ? n : maks), 0n);

    return angka.map((n) =>
        tertinggi === 0n || n === 0n ? 0 : Math.max(3, Number((n * 100n + tertinggi / 2n) / tertinggi)),
    );
}

/** "8.4" → "+8,4%", "-2.0" → "−2,0%", null → null. Format saja, bukan hitungan uang. */
export function FormatPerubahanPersen(persen: string | null): string | null {
    if (persen === null) {
        return null;
    }

    const negatif = persen.startsWith('-');
    const isi = persen.replace('-', '').replace('.', ',');

    return `${negatif ? '−' : '+'}${isi}%`;
}
