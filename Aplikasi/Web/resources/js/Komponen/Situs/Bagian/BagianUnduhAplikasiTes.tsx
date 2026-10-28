import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import type { DataSitus } from '@/Tipe/Situs';

import { BagianUnduhAplikasi } from './BagianLain';

let situs: DataSitus;

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { Situs: situs }, url: '/' }),
}));

function BuatSitus(tautan: DataSitus['TautanUnduh']): DataSitus {
    return { TautanUnduh: tautan } as unknown as DataSitus;
}

const bagian = { Jenis: 'UnduhAplikasi', Label: null, Judul: 'Unduh aplikasi Payoung', Subjudul: null } as const;

afterEach(cleanup);

describe('v7.36: blok Unduh aplikasi memakai badge resmi Google Play', () => {
    it('Android memakai badge resmi Indonesia dengan tautan toko', () => {
        situs = BuatSitus({ Android: 'https://play.google.com/store/apps/details?id=id.payoung.kasir' });
        render(<BagianUnduhAplikasi bagian={bagian} latar="permukaan" />);

        const tautan = screen.getByRole('link', { name: 'Dapatkan aplikasi Payoung di Google Play' });
        expect(tautan.getAttribute('href')).toBe('https://play.google.com/store/apps/details?id=id.payoung.kasir');
        expect(tautan.getAttribute('target')).toBe('_blank');
        expect(tautan.getAttribute('rel')).toBe('noopener noreferrer');
        expect(screen.getByAltText('Dapatkan di Google Play')).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Unduh di Google Play' })).toBeNull();
    });

    it('platform lain tetap tombol teks, tanpa badge', () => {
        situs = BuatSitus({
            Android: 'https://play.google.com/store/apps/details?id=id.payoung.kasir',
            Ios: 'https://apps.apple.com/id/app/payoung-kasir/id000000000',
            Windows: 'https://unduh.payoung.id/Payoung-Kasir-Pemasang-1.0.0.exe',
        });
        render(<BagianUnduhAplikasi bagian={bagian} latar="permukaan" />);

        expect(screen.getAllByAltText('Dapatkan di Google Play')).toHaveLength(1);
        expect(screen.getByRole('link', { name: 'Unduh di App Store' }).getAttribute('href')).toBe(
            'https://apps.apple.com/id/app/payoung-kasir/id000000000',
        );
        expect(screen.getByRole('link', { name: 'Unduh untuk Windows' }).getAttribute('href')).toBe(
            'https://unduh.payoung.id/Payoung-Kasir-Pemasang-1.0.0.exe',
        );
        expect(screen.getByText('Android 8 ke atas')).toBeTruthy();
    });

    it('tanpa tautan: kalimat bantuan, bukan tombol kosong', () => {
        situs = BuatSitus({});
        render(<BagianUnduhAplikasi bagian={bagian} latar="permukaan" />);

        expect(screen.queryByAltText('Dapatkan di Google Play')).toBeNull();
        expect(screen.getByText(/Tautan unduhan segera tersedia/)).toBeTruthy();
    });
});
