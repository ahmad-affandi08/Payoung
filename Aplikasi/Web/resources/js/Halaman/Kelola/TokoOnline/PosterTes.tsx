import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanPoster from '@/Halaman/Kelola/TokoOnline/Poster';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const props = {
    NamaToko: 'Warung Sate Pak Slamet',
    Tautan: 'https://dashboard.payoung.id/warung-slamet',
    QrSvg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"></svg>',
    Aktif: true,
};

beforeEach(() => AturHalamanUji({}, '/kelola/toko-online/poster'));
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('Poster toko online (D-110)', () => {
    it('menampilkan nama toko, QR, dan tautan yang dipindai pembeli', () => {
        RenderUji(<HalamanPoster {...props} />);

        expect(screen.getByRole('heading', { name: 'Warung Sate Pak Slamet' })).toBeTruthy();
        expect(screen.getByAltText('QR toko online Warung Sate Pak Slamet')).toBeTruthy();
        expect(screen.getByText('https://dashboard.payoung.id/warung-slamet')).toBeTruthy();
        expect(screen.queryByText('Toko online belum menerima pesanan')).toBeNull();
    });

    it('memperingatkan bila toko belum menerima pesanan', () => {
        RenderUji(<HalamanPoster {...props} Aktif={false} />);

        expect(screen.getByText('Toko online belum menerima pesanan')).toBeTruthy();
    });

    it('mencetak lewat dialog cetak peramban', () => {
        const Cetak = vi.fn();
        vi.stubGlobal('print', Cetak);
        RenderUji(<HalamanPoster {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Cetak atau simpan PDF' }));

        expect(Cetak).toHaveBeenCalledTimes(1);
    });
});
