import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanPengaturanStruk, { KeIsian, KePengaturan } from '@/Halaman/Kelola/Kasir/Struk';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { DuaKolom, PecahBaris, SusunPratinjauStruk } from '@/Komponen/Struk/PratinjauStruk';
import type { PengaturanStruk, ProfilPratinjauStruk } from '@/Tipe/Kasir';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const bawaan: PengaturanStruk = {
    TampilkanLogo: true,
    NamaDicetak: null,
    TeksKepala: [],
    TampilkanAlamat: true,
    TampilkanTelepon: true,
    TampilkanNpwp: true,
    TampilkanKasir: true,
    TampilkanPelanggan: true,
    TampilkanHemat: true,
    CatatanKaki: null,
    TeksPenutup: null,
    TampilkanStrukDigital: true,
    TampilkanUmpanBalik: true,
};

const profil: ProfilPratinjauStruk = {
    NamaUsaha: 'Kopi Senja Solo',
    Npwp: '0123456789012345',
    NamaOutlet: 'Outlet Utama',
    TautanLogo: null,
    TandaAir: true,
};

describe('Pengaturan struk (PRD v1.79)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/kasir/struk'));
    afterEach(() => cleanup());

    it('tata letak: pecah baris di spasi dan dua kolom selebar kertas 58 mm (32 karakter)', () => {
        expect(PecahBaris('Barang yang sudah dibeli bisa ditukar dalam 7 hari', 32)).toEqual([
            'Barang yang sudah dibeli bisa',
            'ditukar dalam 7 hari',
        ]);
        expect(DuaKolom('TOTAL', 'Rp 56.000', 32)).toHaveLength(32);
        const baris = SusunPratinjauStruk(bawaan, profil, '58').map((b) => b.teks);
        // D-75: judul = nama outlet; nama usaha/akun pemilik tidak pernah tampil, dan nama outlet tidak diulang.
        expect(baris[0]).toBe('Outlet Utama');
        expect(baris.filter((b) => b === 'Outlet Utama')).toHaveLength(1);
        expect(baris).not.toContain('Kopi Senja Solo');
        expect(baris).toContain('NPWP 0123456789012345');
        expect(baris.at(-1)).toBe('Dibuat dengan Payoung');
        expect(baris.every((b) => b.length <= 32)).toBe(true);
    });

    it('nama di struk yang diisi menggantikan nama outlet sebagai judul (D-75)', () => {
        const baris = SusunPratinjauStruk({ ...bawaan, NamaDicetak: 'Lil Escape' }, profil, '58').map((b) => b.teks);
        expect(baris[0]).toBe('Lil Escape');
        expect(baris[1]).toBe('Outlet Utama');
    });

    it('isian ↔ pengaturan: teks kosong menjadi null dan baris kepala kosong dibuang', () => {
        const isian = KeIsian({ ...bawaan, TeksKepala: ['Buka 07.00-22.00'] });
        expect(isian.TeksKepala).toEqual(['Buka 07.00-22.00', '', '']);
        expect(KePengaturan({ ...isian, NamaDicetak: '  ', TeksPenutup: ' Sampai jumpa ' })).toMatchObject({
            NamaDicetak: null,
            TeksKepala: ['Buka 07.00-22.00'],
            TeksPenutup: 'Sampai jumpa',
        });
    });

    it('mengubah isian memperbarui pratinjau dan menyimpan lewat PUT', () => {
        RenderUji(<HalamanPengaturanStruk Pengaturan={bawaan} Profil={profil} />);
        expect(screen.getByRole('button', { name: 'Simpan pengaturan struk' })).toHaveProperty('disabled', true);
        expect(screen.getByText('Kasir: Rina Wulandari')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Nama di struk'), { target: { value: 'Senja Coffee' } });
        fireEvent.change(screen.getByLabelText('Baris kepala 1'), { target: { value: '@kopisenja' } });
        fireEvent.click(screen.getByLabelText('Nama kasir'));
        expect(screen.getByText('Senja Coffee')).toBeTruthy();
        expect(screen.getByText('@kopisenja')).toBeTruthy();
        expect(screen.queryByText('Kasir: Rina Wulandari')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan struk' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/kasir/struk',
            expect.objectContaining({ NamaDicetak: 'Senja Coffee', TeksKepala: ['@kopisenja'], TampilkanKasir: false }),
            expect.anything(),
        );
    });
});
