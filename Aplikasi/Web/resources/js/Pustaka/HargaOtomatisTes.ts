import { describe, expect, it } from 'vitest';

import {
    FormatPerubahanPersen,
    HitungContohHarga,
    LabelPembulatanPendek,
    PersenIsian,
    RapikanPersen,
    SamaDenganPersen,
} from '@/Pustaka/HargaOtomatis';

describe('Pustaka HargaOtomatis (D-266, D-268)', () => {
    it('merapikan persen untuk tampilan dan isian', () => {
        expect(RapikanPersen('20.0000')).toBe('20');
        expect(RapikanPersen('7.5000')).toBe('7,5');
        expect(PersenIsian('20.0000')).toBe('20');
        expect(PersenIsian('7.50')).toBe('7.5');
    });

    it('perubahan persen bertanda: naik dengan plus, turun dengan minus', () => {
        expect(FormatPerubahanPersen('9.10')).toBe('+9,1%');
        expect(FormatPerubahanPersen('-12.50')).toBe('-12,5%');
        expect(FormatPerubahanPersen('900.00')).toBe('+900%');
    });

    it('label pembulatan pendek', () => {
        expect(LabelPembulatanPendek(0)).toBe('Tidak dibulatkan');
        expect(LabelPembulatanPendek(500)).toBe('Rp 500 terdekat');
        expect(LabelPembulatanPendek(1000)).toBe('Rp 1.000 terdekat');
    });

    it('pilihan cepat cocok dengan isian walau ditulis berbeda, dan tidak cocok dengan isian yang bukan angka', () => {
        expect(SamaDenganPersen('20', 20)).toBe(true);
        expect(SamaDenganPersen('20.00', 20)).toBe(true);
        expect(SamaDenganPersen('20,0', 20)).toBe(true);
        expect(SamaDenganPersen('25', 20)).toBe(false);
        expect(SamaDenganPersen('', 20)).toBe(false);
        expect(SamaDenganPersen('abc', 20)).toBe(false);
    });

    it('contoh hitungan: modal Rp 10.000 ditambah untung lalu dibulatkan setengah naik', () => {
        expect(HitungContohHarga('20', 0)).toEqual({ Modal: '10000', Harga: '12000' });
        expect(HitungContohHarga('7.5', 0)).toEqual({ Modal: '10000', Harga: '10750' });
        expect(HitungContohHarga('7,5', 500)).toEqual({ Modal: '10000', Harga: '11000' });
        expect(HitungContohHarga('7.25', 100)).toEqual({ Modal: '10000', Harga: '10700' });
        // Tepat di tengah dibulatkan ke atas: 10.750 ke kelipatan 500 menjadi 11.000.
        expect(HitungContohHarga('7.5', 500)?.Harga).toBe('11000');
        expect(HitungContohHarga('12.5', 1000)?.Harga).toBe('11000');
        expect(HitungContohHarga('0', 500)).toEqual({ Modal: '10000', Harga: '10000' });
    });

    it('contoh hitungan kosong bila untung belum angka sah', () => {
        expect(HitungContohHarga('', 500)).toBeNull();
        expect(HitungContohHarga('abc', 500)).toBeNull();
        expect(HitungContohHarga('1.234', 500)).toBeNull();
    });
});
