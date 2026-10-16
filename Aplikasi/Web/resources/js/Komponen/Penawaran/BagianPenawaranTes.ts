import { describe, expect, it } from 'vitest';

import { AmbilJenisStatusPenawaran } from '@/Komponen/Penawaran/BagianPenawaran';
import type { StatusTampilPenawaran } from '@/Tipe/Penawaran';

describe('Status tampil penawaran', () => {
    it('warna hanya penguat: diterima/sudah jadi invoice sukses, menunggu peringatan, ditolak/batal/kedaluwarsa bahaya', () => {
        expect(AmbilJenisStatusPenawaran('Diterima')).toBe('sukses');
        expect(AmbilJenisStatusPenawaran('DiInvoicekan')).toBe('sukses');
        expect(AmbilJenisStatusPenawaran('MenungguKeputusan')).toBe('peringatan');
        expect(AmbilJenisStatusPenawaran('Ditolak')).toBe('bahaya');
        expect(AmbilJenisStatusPenawaran('Dibatalkan')).toBe('bahaya');
        expect(AmbilJenisStatusPenawaran('Kedaluwarsa')).toBe('bahaya');
        expect(AmbilJenisStatusPenawaran('Draf')).toBe('netral');
    });

    it('setiap status tampil punya jenis (tidak ada yang terlewat)', () => {
        const semua: StatusTampilPenawaran[] = [
            'Draf',
            'MenungguKeputusan',
            'Diterima',
            'DiInvoicekan',
            'Ditolak',
            'Kedaluwarsa',
            'Dibatalkan',
        ];

        for (const status of semua) {
            expect(['sukses', 'peringatan', 'bahaya', 'netral']).toContain(AmbilJenisStatusPenawaran(status));
        }
    });
});
