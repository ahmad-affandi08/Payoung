import type { JenisKolomGa } from '@/Tipe/AnalitikGoogle';

/** Angka dari Google datang sebagai string; nilai yang tidak terbaca dianggap 0 supaya tampilan tidak pernah "NaN". */
function KeAngka(nilai: string): number {
    const angka = Number(nilai);

    return Number.isFinite(angka) ? angka : 0;
}

export function FormatBilanganGa(nilai: string): string {
    return KeAngka(nilai).toLocaleString('id-ID', { maximumFractionDigits: 0 });
}

/** Rasio pecahan (0,6667) → "66,7%". */
export function FormatPersenGa(nilai: string): string {
    return `${(KeAngka(nilai) * 100).toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`;
}

export function FormatDesimalGa(nilai: string): string {
    return KeAngka(nilai).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** Detik → "1j 5m 3d" (jam, menit, detik); bagian nol di depan dibuang. */
export function FormatDurasiGa(nilai: string): string {
    const total = Math.round(KeAngka(nilai));
    const jam = Math.floor(total / 3600);
    const menit = Math.floor((total % 3600) / 60);
    const detik = total % 60;

    if (jam > 0) {
        return `${String(jam)}j ${String(menit)}m ${String(detik)}d`;
    }

    return menit > 0 ? `${String(menit)}m ${String(detik)}d` : `${String(detik)}d`;
}

export function FormatNilaiGa(jenis: JenisKolomGa, nilai: string): string {
    switch (jenis) {
        case 'Bilangan':
            return FormatBilanganGa(nilai);
        case 'Persen':
            return FormatPersenGa(nilai);
        case 'Durasi':
            return FormatDurasiGa(nilai);
        case 'Desimal':
            return FormatDesimalGa(nilai);
        case 'Teks':
            return nilai;
    }
}

export type PerubahanGa = { Arah: 'naik' | 'turun' | 'tetap' | 'baru'; Teks: string };

/**
 * Perubahan relatif terhadap periode sebelumnya. Tanpa dasar pembanding (sebelumnya 0) tidak ada persen yang masuk
 * akal: nilai naik dari 0 ditandai "Baru", dua-duanya 0 dianggap tetap.
 */
export function HitungPerubahanGa(sekarang: string, sebelumnya: string): PerubahanGa {
    const baru = KeAngka(sekarang);
    const lama = KeAngka(sebelumnya);

    if (lama === 0) {
        return baru === 0 ? { Arah: 'tetap', Teks: '0%' } : { Arah: 'baru', Teks: 'Baru' };
    }

    const persen = ((baru - lama) / Math.abs(lama)) * 100;

    if (persen === 0) {
        return { Arah: 'tetap', Teks: '0%' };
    }

    const teks = `${persen > 0 ? '+' : '-'}${Math.abs(persen).toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`;

    return { Arah: persen > 0 ? 'naik' : 'turun', Teks: teks };
}

/** "2026-10-25" → "25/10" untuk sumbu grafik. */
export function FormatTanggalPendekGa(tanggal: string): string {
    const [, bulan = '', hari = ''] = tanggal.split('-');

    return `${String(Number(hari))}/${String(Number(bulan))}`;
}
