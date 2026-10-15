import { describe, expect, it } from 'vitest';

import { AmbilKodeLogo, AmbilSingkatan } from '@/Pustaka/LogoBayar';
import { AmbilPetunjukBayar } from '@/Pustaka/PetunjukBayar';
import { NamaBerkasQr } from '@/Pustaka/UnduhQr';

describe('AmbilKodeLogo', () => {
    it('QRIS dan e-wallet dipetakan dari metodenya', () => {
        expect(AmbilKodeLogo('Qris', 'QRIS')).toBe('qris');
        expect(AmbilKodeLogo('Dana', 'DANA')).toBe('dana');
        expect(AmbilKodeLogo('ShopeePay', 'ShopeePay')).toBe('shopeepay');
    });

    it('bank dicocokkan dari kode channel atau label, DANAMON tidak tertukar dengan DANA', () => {
        expect(AmbilKodeLogo('VirtualAccount', 'VIRTUAL_ACCOUNT_BNI')).toBe('bni');
        expect(AmbilKodeLogo('VirtualAccount', 'Virtual account BRI')).toBe('bri');
        expect(AmbilKodeLogo('VirtualAccount', 'VIRTUAL_ACCOUNT_BANK_MANDIRI')).toBe('mandiri');
        expect(AmbilKodeLogo('VirtualAccount', 'Bank Syariah Indonesia')).toBe('bsi');
        expect(AmbilKodeLogo('VirtualAccount', 'VIRTUAL_ACCOUNT_BANK_CIMB')).toBe('cimb');
        expect(AmbilKodeLogo('VirtualAccount', 'Danamon')).toBe('danamon');
    });

    it('bank yang tidak dikenal memakai kode umum dan singkatannya diambil dari nama', () => {
        expect(AmbilKodeLogo('VirtualAccount', 'Bank Antah Berantah')).toBe('bank');
        expect(AmbilSingkatan('Virtual account Mega Syariah')).toBe('MEGA');
        expect(AmbilSingkatan('BNI')).toBe('BNI');
    });
});

describe('NamaBerkasQr & AmbilPetunjukBayar', () => {
    it('nama berkas aman dari karakter aneh', () => {
        expect(NamaBerkasQr('INV-2026-0042')).toBe('QRIS-INV-2026-0042.png');
        expect(NamaBerkasQr('INV/2026 0042')).toBe('QRIS-INV-2026-0042.png');
        expect(NamaBerkasQr('///')).toBe('QRIS-tagihan.png');
    });

    it('tiap cara bayar punya petunjuk berlangkah', () => {
        expect(AmbilPetunjukBayar('Qris', 'QRIS').map((butir) => butir.Judul)).toEqual([
            'Pindai dari aplikasi bank atau dompet digital',
            'Kamera tidak bisa memindai layar',
        ]);
        expect(AmbilPetunjukBayar('VirtualAccount', 'Virtual account BNI').map((butir) => butir.Judul)).toEqual([
            'ATM',
            'Mobile banking',
            'Internet banking',
        ]);
        expect(AmbilPetunjukBayar('VirtualAccount', 'Virtual account BNI')[1]?.Langkah[0]).toContain('m-banking BNI');
        expect(AmbilPetunjukBayar('Dana', 'DANA')[0]?.Langkah[0]).toContain('Lanjut ke DANA');
    });
});
