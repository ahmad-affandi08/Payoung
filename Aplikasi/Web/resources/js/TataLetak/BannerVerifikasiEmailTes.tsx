import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import BannerVerifikasiEmail from '@/TataLetak/BannerVerifikasiEmail';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

describe('BannerVerifikasiEmail (D-271)', () => {
    beforeEach(() => {
        window.sessionStorage.clear();
        tiruanRouter.post.mockClear();
    });

    afterEach(cleanup);

    it('tampil sebagai satu bilah dengan alamat email, kirim ulang, dan ganti email', () => {
        render(<BannerVerifikasiEmail email="owner@contoh.test" />);

        expect(screen.getByRole('alert').textContent).toContain('owner@contoh.test');
        expect(screen.getByRole('link', { name: 'Ganti email' }).getAttribute('href')).toBe('/kelola/keamanan#email');

        fireEvent.click(screen.getByRole('button', { name: 'Kirim ulang' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith('/verifikasi-email/kirim-ulang', {}, expect.any(Object));
    });

    it('bisa ditutup dan tetap tertutup selama sesi peramban', () => {
        const { unmount } = render(<BannerVerifikasiEmail email="owner@contoh.test" />);
        fireEvent.click(screen.getByRole('button', { name: 'Tutup pengingat verifikasi email' }));
        expect(screen.queryByRole('alert')).toBeNull();

        unmount();
        render(<BannerVerifikasiEmail email="owner@contoh.test" />);
        expect(screen.queryByRole('alert')).toBeNull();
    });
});
