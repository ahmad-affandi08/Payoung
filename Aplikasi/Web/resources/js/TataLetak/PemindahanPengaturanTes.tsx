import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanPengaturan from '@/Halaman/Kelola/Pengaturan/Indeks';
import { AturHalamanUji, TambahPropsHalamanUji } from '@/Komponen/Katalog/TiruanInertia';
import { butirAkun, daftarPengaturan, pintasanPengaturan } from '@/Pustaka/DaftarPengaturan';

import { daftarMenu, SaringMenuTerlihat, SusunPencarian } from './TataLetakAplikasi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const akunPemilik = { Pemilik: true, Izin: [] };

function AlamatGrup(label: string): string[] {
    const grup = daftarMenu.find((butir) => butir.label === label);

    return (grup && 'sub' in grup ? grup.sub : []).map((sub) => sub.href);
}

/*
 * v6.49 (penyesuaian D-27 atas permintaan pemilik produk: "pengaturannya terlalu banyak"): butir yang dibuka berkala
 * kembali ke menu yang menjawab pertanyaannya, dan butir akun pindah ke menu avatar.
 */
describe('Pemindahan butir dari Pengaturan (v6.49)', () => {
    it('butir sering dipakai punya rumah baru di menu samping', () => {
        expect(AlamatGrup('Produk')).toEqual(
            expect.arrayContaining([
                '/kelola/daftar-harga',
                '/kelola/satuan',
                '/kelola/kelompok-pilihan',
                '/kelola/produk/impor',
            ]),
        );
        expect(AlamatGrup('Penjualan & kasir')).toContain('/kelola/perangkat');
        expect(AlamatGrup('Laporan')).toContain('/kelola/unduhan');
        expect(AlamatGrup('Tim')).toContain('/kelola/log-audit');
    });

    it('butir yang pindah tidak lagi ada di Pengaturan (satu rumah)', () => {
        const alamat = new Set(daftarPengaturan.flatMap(({ butir }) => butir.map((satu) => satu.href)));

        for (const pindah of [
            '/kelola/daftar-harga',
            '/kelola/satuan',
            '/kelola/kelompok-pilihan',
            '/kelola/produk/impor',
            '/kelola/perangkat',
            '/kelola/unduhan',
            '/kelola/log-audit',
            '/kelola/langganan',
            '/kelola/keamanan',
        ]) {
            expect(alamat.has(pindah), pindah).toBe(false);
        }

        // D-253: yang tersisa di Pengaturan hanya usaha dan integrasi.
        expect(alamat.has('/kelola/pengaturan/profil-usaha')).toBe(true);
        expect(alamat.has('/kelola/outlet')).toBe(true);
    });

    it('D-253: pengaturan modul pindah ke menu modulnya dan tidak dobel di Pengaturan', () => {
        const alamat = new Set(daftarPengaturan.flatMap(({ butir }) => butir.map((satu) => satu.href)));

        expect(daftarPengaturan.map(({ judul }) => judul)).toEqual(['Usaha', 'Integrasi & API']);
        expect(AlamatGrup('Penjualan & kasir')).toEqual(
            expect.arrayContaining(['/kelola/kasir/pengaturan', '/kelola/kasir/struk']),
        );
        expect(AlamatGrup('Akuntansi')).toEqual(
            expect.arrayContaining(['/kelola/akuntansi/akun', '/kelola/akuntansi/pemetaan']),
        );
        expect(alamat.has('/kelola/kasir/pengaturan')).toBe(false);
        expect(alamat.has('/kelola/kasir/struk')).toBe(false);
        expect(alamat.has('/kelola/akuntansi/akun')).toBe(false);
    });

    it('halaman menu akun (langganan, keamanan) tetap ditemukan lewat Ctrl+K dan tidak dobel di Pengaturan', () => {
        const { halaman } = SusunPencarian(SaringMenuTerlihat(akunPemilik), akunPemilik, 'Saas');
        const akun = halaman.filter((butir) => butir.grup === 'Akun').map((butir) => butir.label);

        expect(akun).toEqual(['Langganan & tagihan', 'Keamanan akun saya']);
        expect(butirAkun.map((butir) => butir.href)).toEqual(['/kelola/langganan', '/kelola/keamanan']);
        // Edisi Lisensi tidak berlangganan.
        const lisensi = SusunPencarian(SaringMenuTerlihat(akunPemilik), akunPemilik, 'Lisensi').halaman;
        expect(lisensi.filter((butir) => butir.grup === 'Akun').map((butir) => butir.label)).toEqual([
            'Keamanan akun saya',
        ]);
    });

    it('setiap pintasan "Paling sering dipakai" menunjuk butir Pengaturan yang ada', () => {
        const alamat = new Set(daftarPengaturan.flatMap(({ butir }) => butir.map((satu) => satu.href)));

        expect(pintasanPengaturan.length).toBeLessThanOrEqual(5);
        expect(pintasanPengaturan.filter((href) => !alamat.has(href))).toEqual([]);
    });
});

describe('Halaman Pengaturan: Paling sering dipakai', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/pengaturan');
        TambahPropsHalamanUji({ Akses: akunPemilik, Edisi: 'Saas', SektorOutlet: ['FNB'] });
    });

    afterEach(() => {
        cleanup();
    });

    it('menampilkan pintasan sebagai tautan yang boleh dibuka pengguna', () => {
        render(<HalamanPengaturan />);

        const bagian = screen.getByRole('region', { name: 'Paling sering dipakai' });
        const label = within(bagian)
            .getAllByRole('link')
            .map((tautan) => tautan.textContent);

        // D-253: pintasan hanya menunjuk butir yang masih tinggal di Pengaturan.
        expect(label).toEqual(['Profil usaha', 'Outlet & gudang']);
    });
});
