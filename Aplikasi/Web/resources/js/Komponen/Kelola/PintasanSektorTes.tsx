import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, TambahPropsHalamanUji } from '@/Komponen/Katalog/TiruanInertia';
import PintasanSektor from '@/Komponen/Kelola/PintasanSektor';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

describe('Komponen/Kelola/PintasanSektor (pintasan Beranda untuk fitur khusus jenis usaha)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola'));
    afterEach(() => cleanup());

    it('usaha apotek mendapat pintasan Laporan apotek ke halamannya', () => {
        TambahPropsHalamanUji({ SektorOutlet: ['RTL-PHR'] });
        render(<PintasanSektor />);

        const tautan = screen.getByRole('link', { name: /Laporan apotek/ });

        expect(tautan.getAttribute('href')).toBe('/kelola/laporan/apotek');
        expect(screen.getByText('Untuk jenis usaha Anda')).toBeTruthy();
    });

    it('usaha campuran yang memuat apotek ikut mendapat pintasan', () => {
        TambahPropsHalamanUji({ SektorOutlet: ['FNB-CAF', 'RTL-PHR'] });
        render(<PintasanSektor />);

        expect(screen.getByRole('link', { name: /Laporan apotek/ })).toBeTruthy();
    });

    it('jenis usaha lain atau yang belum diketahui tidak menampilkan apa pun', () => {
        TambahPropsHalamanUji({ SektorOutlet: ['FNB-CAF'] });
        const { container, rerender: Render } = render(<PintasanSektor />);

        expect(container.textContent).toBe('');

        TambahPropsHalamanUji({ SektorOutlet: [] });
        Render(<PintasanSektor />);

        expect(container.textContent).toBe('');
    });

    it('tanpa izin laporan penjualan atau bila fitur di luar paket, pintasan tidak ditampilkan', () => {
        TambahPropsHalamanUji({ SektorOutlet: ['RTL-PHR'], Akses: { Pemilik: false, Izin: [] } });
        const { container, rerender: Render } = render(<PintasanSektor />);

        expect(container.textContent).toBe('');

        TambahPropsHalamanUji({
            Akses: { Pemilik: true, Izin: [] },
            FiturPaket: {
                NamaPaket: 'Starter',
                Terkunci: {
                    'laporan.lengkap': {
                        Nama: 'Laporan lengkap',
                        Paket: { Kode: 'pro', Nama: 'Pro', HargaBulanan: '149000' },
                        Addon: null,
                    },
                },
            },
        });
        Render(<PintasanSektor />);

        expect(container.textContent).toBe('');
    });
});
