import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import UnduhAplikasi from './UnduhAplikasi';

const QR = '<svg xmlns="http://www.w3.org/2000/svg"><rect /></svg>';

const unduhan = [
    {
        Platform: 'Android',
        Label: 'Android',
        Versi: '1.0.0',
        Url: 'https://play.google.com/store/apps/details?id=id.payoung.kasir',
        QrSvg: QR,
    },
    {
        Platform: 'Windows',
        Label: 'Windows',
        Versi: '1.0.0',
        Url: 'https://unduh.payoung.id/Payoung-Kasir-Pemasang-1.0.0.exe',
        QrSvg: null,
    },
];

/*
 * v7.35 (F-02b langkah 5, P-10): halaman Perangkat meminta kode aktivasi, padahal aplikasinya belum tentu terpasang.
 * Komponen ini satu-satunya jalan ke toko aplikasi dari back-office, jadi tautan, versi, dan QR-nya dijaga.
 */

afterEach(cleanup);

describe('UnduhAplikasi (F-02b, P-10)', () => {
    it('menampilkan satu tombol unduh per platform yang punya tautan', () => {
        render(<UnduhAplikasi Unduhan={unduhan} />);

        const android = screen.getByRole('link', { name: 'Unduh untuk Android' });
        expect(android.getAttribute('href')).toBe('https://play.google.com/store/apps/details?id=id.payoung.kasir');
        expect(android.getAttribute('target')).toBe('_blank');
        expect(android.getAttribute('rel')).toBe('noopener noreferrer');

        expect(screen.getByRole('link', { name: 'Unduh untuk Windows' }).getAttribute('href')).toBe(
            'https://unduh.payoung.id/Payoung-Kasir-Pemasang-1.0.0.exe',
        );
    });

    it('menampilkan versi terbaru dan QR untuk dipindai dari komputer', () => {
        render(<UnduhAplikasi Unduhan={unduhan} />);

        expect(screen.getByText('Versi terbaru 1.0.0')).toBeTruthy();
        const qr = screen.getByAltText('QR unduh aplikasi kasir untuk Android') as HTMLImageElement;
        expect(qr.getAttribute('src')).toContain('data:image/svg+xml');
        expect(screen.getByText('Pindai dengan HP')).toBeTruthy();
    });

    it('tidak merender apa pun bila server belum punya tautan', () => {
        const { container } = render(<UnduhAplikasi Unduhan={[]} />);

        expect(container.innerHTML).toBe('');
    });

    it('tidak menampilkan QR bila tidak ada platform yang membawanya', () => {
        render(<UnduhAplikasi Unduhan={unduhan.map((baris) => ({ ...baris, QrSvg: null }))} />);

        expect(screen.queryByText('Pindai dengan HP')).toBeNull();
    });
});
