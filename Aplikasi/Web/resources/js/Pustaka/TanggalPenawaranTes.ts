import { describe, expect, it } from 'vitest';

import { TambahHariTanggal } from './TanggalPenawaran';

describe('TambahHariTanggal', () => {
    it('menambah hari lintas bulan dan tahun tanpa terpengaruh zona waktu', () => {
        expect(TambahHariTanggal('2026-09-27', 14)).toBe('2026-10-11');
        expect(TambahHariTanggal('2026-12-25', 14)).toBe('2027-01-08');
        expect(TambahHariTanggal('2028-02-20', 10)).toBe('2028-03-01');
    });

    it('nol hari mengembalikan tanggal yang sama. Tanggal tidak valid dikembalikan apa adanya', () => {
        expect(TambahHariTanggal('2026-09-27', 0)).toBe('2026-09-27');
        expect(TambahHariTanggal('', 14)).toBe('');
        expect(TambahHariTanggal('bukan-tanggal', 14)).toBe('bukan-tanggal');
    });
});
