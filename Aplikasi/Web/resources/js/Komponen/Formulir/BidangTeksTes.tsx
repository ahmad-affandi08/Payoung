import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import BidangTeks from './BidangTeks';

afterEach(cleanup);

describe('BidangTeks (PRD §17.6 aksesibilitas)', () => {
    it('menghubungkan label ke input dan meneruskan perubahan nilai', () => {
        const SaatBerubah = vi.fn();
        render(<BidangTeks label="Email" nilai="" saatBerubah={SaatBerubah} />);

        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'rina@contoh.id' } });

        expect(SaatBerubah).toHaveBeenCalledWith('rina@contoh.id');
    });

    it('menandai input tidak valid dan mengaitkan pesan masalah', () => {
        render(<BidangTeks label="Kode" nilai="1" saatBerubah={() => undefined} galat="Kode tidak cocok." />);

        const input = screen.getByLabelText('Kode');
        const galat = screen.getByText('Kode tidak cocok.');

        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(input.getAttribute('aria-describedby')).toContain(galat.id);
    });
    it('bidang kata sandi punya tombol mata yang menampilkan lalu menyembunyikan isinya', () => {
        render(<BidangTeks label="Kata sandi" nilai="rahasia" saatBerubah={() => undefined} jenis="password" />);

        const input = screen.getByLabelText('Kata sandi');
        expect(input.getAttribute('type')).toBe('password');

        const tombol = screen.getByRole('button', { name: 'Tampilkan Kata sandi' });
        fireEvent.click(tombol);

        // Hanya tampilannya yang berubah; nilainya tetap utuh.
        expect(input.getAttribute('type')).toBe('text');
        expect((input as HTMLInputElement).value).toBe('rahasia');

        fireEvent.click(screen.getByRole('button', { name: 'Sembunyikan Kata sandi' }));
        expect(input.getAttribute('type')).toBe('password');
    });

    it('label tombol menyebut nama bidang agar tiga bidang kata sandi tidak ambigu', () => {
        render(
            <>
                <BidangTeks label="Kata sandi lama" nilai="" saatBerubah={() => undefined} jenis="password" />
                <BidangTeks label="Kata sandi baru" nilai="" saatBerubah={() => undefined} jenis="password" />
                <BidangTeks label="Ulangi kata sandi baru" nilai="" saatBerubah={() => undefined} jenis="password" />
            </>,
        );

        expect(screen.getByRole('button', { name: 'Tampilkan Kata sandi lama' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Tampilkan Kata sandi baru' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Tampilkan Ulangi kata sandi baru' })).toBeTruthy();
    });

    it('bidang bukan kata sandi tidak diberi tombol mata', () => {
        render(<BidangTeks label="Email" nilai="" saatBerubah={() => undefined} jenis="email" />);

        expect(screen.queryByRole('button')).toBeNull();
    });

    it('tombol mata ikut nonaktif saat bidangnya nonaktif', () => {
        render(<BidangTeks label="Kata sandi" nilai="x" saatBerubah={() => undefined} jenis="password" disabled />);

        expect(screen.getByRole('button', { name: 'Tampilkan Kata sandi' }).hasAttribute('disabled')).toBe(true);
    });
});
