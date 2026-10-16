import { describe, expect, it } from 'vitest';

import { HitungSubtotalBaris, HitungSubtotalInvoice } from './HitungInvoice';

describe('Pratinjau subtotal invoice', () => {
    it('jumlah × harga − diskon', () => {
        expect(HitungSubtotalBaris({ Jumlah: '2', Harga: '1500000', Diskon: '' })).toBe('3000000.00');
        expect(HitungSubtotalBaris({ Jumlah: '2.5', Harga: '100000', Diskon: '50000' })).toBe('200000.00');
    });

    it('null bila belum lengkap atau diskon melebihi nilai baris', () => {
        expect(HitungSubtotalBaris({ Jumlah: '', Harga: '1000', Diskon: '' })).toBeNull();
        expect(HitungSubtotalBaris({ Jumlah: '1', Harga: '', Diskon: '' })).toBeNull();
        expect(HitungSubtotalBaris({ Jumlah: '1', Harga: '1000', Diskon: '2000' })).toBeNull();
    });

    it('nominal besar tidak bergeser (tanpa float)', () => {
        expect(HitungSubtotalBaris({ Jumlah: '3', Harga: '3333333333333.33', Diskon: '' })).toBe('9999999999999.99');
    });

    it('menjumlahkan hanya baris yang valid', () => {
        expect(
            HitungSubtotalInvoice([
                { Jumlah: '1', Harga: '10000000', Diskon: '' },
                { Jumlah: '', Harga: '5', Diskon: '' },
                { Jumlah: '2', Harga: '250000.50', Diskon: '0.01' },
            ]),
        ).toBe('10500000.99');
        expect(HitungSubtotalInvoice([])).toBe('0.00');
    });
});
