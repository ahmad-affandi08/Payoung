import { describe, expect, it } from 'vitest';

import {
    FormatDurasiGa,
    FormatNilaiGa,
    FormatPersenGa,
    FormatTanggalPendekGa,
    HitungPerubahanGa,
} from '@/Pustaka/FormatAnalitikGoogle';

describe('format angka Google Analytics', () => {
    it('bilangan berformat Indonesia, rasio jadi persen, nilai rusak jadi 0', () => {
        expect(FormatNilaiGa('Bilangan', '1234567')).toBe('1.234.567');
        expect(FormatPersenGa('0.6667')).toBe('66,7%');
        expect(FormatNilaiGa('Desimal', '2.5')).toBe('2,50');
        expect(FormatNilaiGa('Bilangan', 'bukan-angka')).toBe('0');
        expect(FormatNilaiGa('Teks', 'Direct')).toBe('Direct');
    });

    it('durasi detik jadi jam, menit, detik tanpa bagian nol di depan', () => {
        expect(FormatDurasiGa('0')).toBe('0d');
        expect(FormatDurasiGa('95.5')).toBe('1m 36d');
        expect(FormatDurasiGa('3903')).toBe('1j 5m 3d');
    });

    it('perubahan terhadap periode sebelumnya: naik, turun, tetap, dan tanpa dasar pembanding', () => {
        expect(HitungPerubahanGa('120', '100')).toEqual({ Arah: 'naik', Teks: '+20%' });
        expect(HitungPerubahanGa('75', '100')).toEqual({ Arah: 'turun', Teks: '-25%' });
        expect(HitungPerubahanGa('100', '100')).toEqual({ Arah: 'tetap', Teks: '0%' });
        expect(HitungPerubahanGa('5', '0')).toEqual({ Arah: 'baru', Teks: 'Baru' });
        expect(HitungPerubahanGa('0', '0')).toEqual({ Arah: 'tetap', Teks: '0%' });
        expect(HitungPerubahanGa('1', '3').Teks).toBe('-66,7%');
    });

    it('tanggal sumbu grafik ringkas', () => {
        expect(FormatTanggalPendekGa('2026-10-05')).toBe('5/10');
    });
});
