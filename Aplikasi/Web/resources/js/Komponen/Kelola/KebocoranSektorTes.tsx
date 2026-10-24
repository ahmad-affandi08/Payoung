import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanPengaturan from '@/Halaman/Kelola/Pengaturan/Indeks';
import { AturHalamanUji, TambahPropsHalamanUji } from '@/Komponen/Katalog/TiruanInertia';
import { CekSesuaiSektor } from '@/Pustaka/Sektor';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-183: fitur khusus sektor tidak boleh bocor ke sektor lain. Pengaturan memakai aturan `sektor` (hanya sektor itu) dan
 * `kecualiSektor` (semua sektor usaha ada di daftar itu, mis. usaha tanpa kasir).
 */
const akunPemilik = { Pemilik: true, Izin: [] };

function Muat(sektor: string[]) {
    AturHalamanUji({}, '/kelola/pengaturan');
    TambahPropsHalamanUji({ Akses: akunPemilik, Edisi: 'Saas', SektorOutlet: sektor });
    render(<HalamanPengaturan />);
}

function Ada(label: string): boolean {
    return screen.queryAllByText(label).length > 0;
}

describe('Pengaturan menurut sektor (D-183)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/pengaturan'));
    afterEach(() => cleanup());

    it('usaha jasa profesional (tanpa kasir & stok) tidak melihat pengaturan kasir, struk, stok, dan loyalti', () => {
        Muat(['BIZ-JSA']);

        for (const label of [
            'Pengaturan kasir',
            'Pengaturan struk',
            'Kategori kas',
            'Metode pembayaran',
            'Pengaturan persediaan',
            'Rak gudang',
            'Stok awal',
            'Impor stok awal',
            'Tier pelanggan',
            'Armada pengiriman',
            'Stasiun dapur',
        ]) {
            expect(Ada(label), label).toBe(false);
        }

        expect(Ada('Profil usaha')).toBe(true);
        expect(Ada('Aturan kehadiran')).toBe(true);
    });

    it('manufaktur (BIZ-MFG) tetap melihat stok dan armada pengiriman, tetapi tidak pengaturan kasir', () => {
        Muat(['BIZ-MFG']);

        expect(Ada('Pengaturan persediaan')).toBe(true);
        expect(Ada('Armada pengiriman')).toBe(true);
        expect(Ada('Pengaturan kasir')).toBe(false);
    });

    it('restoran melihat pengaturan kasir dan stasiun dapur, bukan armada pengiriman grosir', () => {
        Muat(['FNB-RST']);

        expect(Ada('Pengaturan kasir')).toBe(true);
        expect(Ada('Stasiun dapur')).toBe(true);
        expect(Ada('Armada pengiriman')).toBe(false);
    });

    it('grosir melihat armada pengiriman, tetapi bukan stasiun dapur', () => {
        Muat(['WHS-DST']);

        expect(Ada('Armada pengiriman')).toBe(true);
        expect(Ada('Stasiun dapur')).toBe(false);
    });

    it('sektor belum diketahui menampilkan semuanya (tidak ada yang hilang diam-diam)', () => {
        Muat([]);

        expect(Ada('Pengaturan kasir')).toBe(true);
        expect(Ada('Armada pengiriman')).toBe(true);
    });
});

describe('CekSesuaiSektor', () => {
    it('awalan cocok per segmen dan kecualiSektor hanya berlaku bila semua sektor ada di daftarnya', () => {
        expect(CekSesuaiSektor({ sektor: ['SVC'] }, ['SVC-WRK'])).toBe(true);
        expect(CekSesuaiSektor({ sektor: ['SVC-WRK'] }, ['SVC-LDR'])).toBe(false);
        expect(CekSesuaiSektor({ kecualiSektor: ['BIZ'] }, ['BIZ-JSA'])).toBe(false);
        // Usaha campuran (jasa profesional yang juga punya toko) tetap melihat butir kasir.
        expect(CekSesuaiSektor({ kecualiSektor: ['BIZ'] }, ['BIZ-JSA', 'RTL-GEN'])).toBe(true);
    });
});
