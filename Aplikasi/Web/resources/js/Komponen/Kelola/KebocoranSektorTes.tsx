import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanPengaturan from '@/Halaman/Kelola/Pengaturan/Indeks';
import { AturHalamanUji, TambahPropsHalamanUji } from '@/Komponen/Katalog/TiruanInertia';
import { CekSesuaiSektor } from '@/Pustaka/Sektor';
import { SaringMenuTerlihat } from '@/TataLetak/TataLetakAplikasi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-183: fitur khusus sektor tidak boleh bocor ke sektor lain. Menu samping dan Pengaturan memakai aturan `sektor`
 * (hanya sektor itu) dan `kecualiSektor` (semua sektor usaha ada di daftar itu, mis. usaha tanpa kasir). Sejak D-253
 * pengaturan modul tinggal di menu modulnya, jadi yang diperiksa adalah menu samping dan halaman Pengaturan sekaligus.
 */
const akunPemilik = { Pemilik: true, Izin: [] };

let labelMenu = new Set<string>();

function Muat(sektor: string[]) {
    AturHalamanUji({}, '/kelola/pengaturan');
    TambahPropsHalamanUji({ Akses: akunPemilik, Edisi: 'Saas', SektorOutlet: sektor });
    labelMenu = new Set(
        SaringMenuTerlihat(akunPemilik, { sektorOutlet: sektor, edisi: 'Saas' }).flatMap((terlihat) =>
            terlihat.sub.length > 0 ? terlihat.sub.map((item) => item.label) : [terlihat.menu.label],
        ),
    );
    render(<HalamanPengaturan />);
}

/** Ada di menu samping (pengaturan modul) atau di halaman Pengaturan (profil usaha, integrasi). */
function Ada(label: string): boolean {
    return labelMenu.has(label) || screen.queryAllByText(label).length > 0;
}

describe('Menu dan Pengaturan menurut sektor (D-183, D-253)', () => {
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

    it('komisi staf lazim di salon, restoran, butik, toko HP, toko bangunan, dan grosir, bukan di sayur/buah atau apotek', () => {
        for (const sektor of ['SVC-SLN', 'FNB-RST', 'RTL-FSH', 'RTL-ELC', 'RTL-BLD', 'WHS-DST']) {
            Muat([sektor]);
            expect(Ada('Aturan komisi'), sektor).toBe(true);
            cleanup();
        }

        for (const sektor of ['RTL-FRS', 'RTL-PHR']) {
            Muat([sektor]);
            expect(Ada('Aturan komisi'), sektor).toBe(false);
            cleanup();
        }
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
