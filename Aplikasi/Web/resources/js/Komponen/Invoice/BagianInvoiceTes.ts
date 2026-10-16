import { describe, expect, it } from 'vitest';

import { AmbilJenisStatusBayar, FormatJumlahInvoice, KeteranganTermin } from '@/Komponen/Invoice/BagianInvoice';

describe('Bagian bersama invoice', () => {
    it('jumlah dirapikan: angka bulat tidak disentuh, desimal berkoma tanpa nol berlebih', () => {
        expect(FormatJumlahInvoice('100')).toBe('100');
        expect(FormatJumlahInvoice('10.0000')).toBe('10');
        expect(FormatJumlahInvoice('1.5000')).toBe('1,5');
        expect(FormatJumlahInvoice('0.2500')).toBe('0,25');
        expect(FormatJumlahInvoice('1200')).toBe('1200');
    });

    it('termin dalam kata', () => {
        expect(KeteranganTermin(0)).toBe('Bayar langsung');
        expect(KeteranganTermin(14)).toBe('14 hari');
    });

    it('warna status hanya penguat: lunas sukses, sebagian peringatan, lewat tempo & batal bahaya, sisanya netral', () => {
        expect(AmbilJenisStatusBayar('Lunas')).toBe('sukses');
        expect(AmbilJenisStatusBayar('DibayarSebagian')).toBe('peringatan');
        expect(AmbilJenisStatusBayar('LewatTempo')).toBe('bahaya');
        expect(AmbilJenisStatusBayar('Dibatalkan')).toBe('bahaya');
        expect(AmbilJenisStatusBayar('BelumDibayar')).toBe('netral');
        expect(AmbilJenisStatusBayar('Draf')).toBe('netral');
        expect(AmbilJenisStatusBayar('DihapusBuku')).toBe('netral');
    });
});
