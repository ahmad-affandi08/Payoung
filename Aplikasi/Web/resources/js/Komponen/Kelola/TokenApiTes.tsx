import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanTokenApi from '@/Halaman/Kelola/Pengaturan/Api';
import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import type { PropsHalamanTokenApi } from '@/Tipe/ApiPublik';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/* X7 Open API v1: halaman Token API menampilkan token sekali, membuat token bercakupan, dan menyembunyikan rahasia. */

const props: PropsHalamanTokenApi = {
    Token: [
        {
            Uuid: '01K5TOKEN0000000000000000A1',
            Nama: 'Aplikasi akuntansi',
            Prefiks: 'payoung_12_Ab3D',
            Cakupan: ['produk:baca', 'penjualan:baca'],
            Aktif: true,
            DibuatPada: '2026-10-05T03:00:00Z',
            TerakhirDipakaiPada: null,
            KedaluwarsaPada: null,
            DicabutPada: null,
        },
    ],
    OpsiCakupan: [
        { Nilai: 'produk:baca', Label: 'Baca produk, satuan, barcode & harga jual' },
        { Nilai: 'penjualan:baca', Label: 'Baca penjualan beserta baris & pembayaran' },
    ],
    TokenBaru: null,
    AlamatApi: 'https://dashboard.payoung.id/api/v1',
};

describe('X7 halaman Token API', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/pengaturan/api');
        kirimanForm.length = 0;
    });
    afterEach(cleanup);

    it('daftar token hanya menampilkan prefiks; token baru tampil sekali dengan tombol salin', () => {
        RenderUji(
            <HalamanTokenApi
                {...props}
                TokenBaru={{ Nama: 'Aplikasi akuntansi', Token: 'payoung_12_' + 'x'.repeat(40) }}
            />,
        );

        expect(screen.getAllByText('payoung_12_Ab3D…').length).toBeGreaterThan(0);
        expect(screen.getByText('payoung_12_' + 'x'.repeat(40))).toBeTruthy();
        expect(screen.getByRole('button', { name: /Salin token/ })).toBeTruthy();
        expect(screen.getByText(/tidak bisa ditampilkan lagi/)).toBeTruthy();
    });

    it('formulir buat token mengirim nama & akses terpilih', () => {
        RenderUji(<HalamanTokenApi {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Buat token' }));
        fireEvent.change(screen.getByLabelText(/Nama token/), { target: { value: 'Laporan BI' } });
        fireEvent.click(screen.getByLabelText('Baca penjualan beserta baris & pembayaran'));
        fireEvent.click(screen.getAllByRole('button', { name: 'Buat token' }).at(-1) as HTMLElement);

        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'post',
            url: '/kelola/pengaturan/api',
            data: { Nama: 'Laporan BI', Cakupan: ['penjualan:baca'] },
        });
    });
});
