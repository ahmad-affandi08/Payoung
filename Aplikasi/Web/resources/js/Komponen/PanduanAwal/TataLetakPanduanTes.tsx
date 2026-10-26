import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { BuatProgresContoh, BuatPropsBersamaContoh } from './DataUjiPanduan';
import TataLetakPanduan from './TataLetakPanduan';

type OpsiKunjungan = { onSuccess?: () => void };

const tiruan = vi.hoisted(() => ({ kirim: vi.fn<(alamat: string, data: object, opsi?: OpsiKunjungan) => void>() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...sisa }: { href: string; children: ReactNode }) => (
        <a href={href} {...sisa}>
            {children}
        </a>
    ),
    router: { post: tiruan.kirim },
    usePage: () => ({ props: BuatPropsBersamaContoh(), url: '/kelola/panduan-awal/pajak' }),
}));

describe('TataLetakPanduan: lewati & lanjutkan kapan saja (F-01)', () => {
    afterEach(() => {
        cleanup();
        tiruan.kirim.mockReset();
    });

    it('judul halaman = judul langkah; Kembali menuju langkah sebelumnya; Lewati dulu mem-POST slug langkah', () => {
        render(
            <TataLetakPanduan progres={BuatProgresContoh({ ProfilUsaha: 'Selesai' })} langkah="Pajak">
                isi langkah
            </TataLetakPanduan>,
        );

        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Pajak');
        expect(screen.getByRole('link', { name: 'Kembali ke Jenis usaha & template' }).getAttribute('href')).toBe(
            '/kelola/panduan-awal/sektor',
        );

        fireEvent.click(screen.getByRole('button', { name: 'Lewati dulu' }));

        expect(tiruan.kirim).toHaveBeenCalledWith('/kelola/panduan-awal/langkah/pajak/lewati', {}, expect.any(Object));
        expect(screen.queryByRole('button', { name: 'Lanjutkan' })).toBeNull();
    });

    it('D-24 panduan wajib: tanpa sidebar & tanpa tautan Beranda. Langkah wajib tanpa "Lewati", Perangkat "Nanti saja"', () => {
        const progres = { ...BuatProgresContoh({ ProfilUsaha: 'Selesai' }), Wajib: true };
        const { unmount: Lepas } = render(
            <TataLetakPanduan progres={progres} langkah="Pajak">
                isi
            </TataLetakPanduan>,
        );

        expect(screen.queryByRole('link', { name: 'Ke Beranda' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Keluar' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Lewati dulu' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Nanti saja' })).toBeNull();
        expect(screen.getByText(/Perangkat kasir boleh diatur nanti/)).toBeTruthy();
        Lepas();

        render(
            <TataLetakPanduan progres={progres} langkah="Perangkat" lanjut="selesaikan">
                isi
            </TataLetakPanduan>,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Nanti saja' }));
        expect(tiruan.kirim).toHaveBeenCalledWith(
            '/kelola/panduan-awal/langkah/perangkat/lewati',
            {},
            expect.any(Object),
        );
    });

    it('tenant lama (tidak wajib): tautan Ke Beranda tersedia', () => {
        render(
            <TataLetakPanduan progres={BuatProgresContoh()} langkah="Pajak">
                isi
            </TataLetakPanduan>,
        );

        expect(screen.getByRole('link', { name: 'Ke Beranda' }).getAttribute('href')).toBe('/kelola');
    });

    it('langkah pertama kembali ke ringkasan. Langkah yang sudah selesai tidak menawarkan "Lewati dulu"', () => {
        render(
            <TataLetakPanduan progres={BuatProgresContoh({ ProfilUsaha: 'Selesai' })} langkah="ProfilUsaha">
                isi
            </TataLetakPanduan>,
        );

        expect(screen.getByRole('link', { name: 'Kembali ke ringkasan' }).getAttribute('href')).toBe(
            '/kelola/panduan-awal',
        );
        expect(screen.queryByRole('button', { name: 'Lewati dulu' })).toBeNull();
        expect(screen.getByRole('link', { name: 'Ke langkah berikutnya' }).getAttribute('href')).toBe(
            '/kelola/panduan-awal/sektor',
        );
    });

    it('Lanjutkan pada langkah Produk menandai langkah selesai', () => {
        render(
            <TataLetakPanduan progres={BuatProgresContoh()} langkah="Produk" lanjut="tandai-selesai">
                isi
            </TataLetakPanduan>,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Lanjutkan' }));

        expect(tiruan.kirim).toHaveBeenCalledWith(
            '/kelola/panduan-awal/langkah/produk/selesai',
            {},
            expect.any(Object),
        );
    });

    it('Selesaikan panduan di langkah terakhir: tandai Perangkat selesai lalu selesaikan panduan', () => {
        render(
            <TataLetakPanduan progres={BuatProgresContoh()} langkah="Perangkat" lanjut="selesaikan">
                isi
            </TataLetakPanduan>,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Selesaikan panduan' }));

        expect(tiruan.kirim).toHaveBeenCalledTimes(1);
        expect(tiruan.kirim.mock.calls[0]?.[0]).toBe('/kelola/panduan-awal/langkah/perangkat/selesai');

        tiruan.kirim.mock.calls[0]?.[2]?.onSuccess?.();

        expect(tiruan.kirim).toHaveBeenLastCalledWith('/kelola/panduan-awal/selesai', {}, expect.any(Object));
    });
});
