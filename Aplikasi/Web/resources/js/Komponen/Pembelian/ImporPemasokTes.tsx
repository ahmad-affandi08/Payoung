import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanImporPemasok from '@/Halaman/Kelola/Pembelian/Pemasok/Impor';
import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import type { HasilImporPemasok } from '@/Tipe/Pembelian';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const hasilPeriksa: HasilImporPemasok = {
    Terapkan: false,
    NamaBerkas: 'pemasok-lama.xlsx',
    JumlahBaris: 120,
    Baru: 100,
    SudahAda: 15,
    Bermasalah: 5,
    Masalah: [
        {
            Baris: 9,
            Nama: 'CV Sumber Pangan Makmur Sentosa',
            Pesan: 'Termin harus angka 0 sampai 365 hari atau "Tunai".',
        },
    ],
};

describe('Impor pemasok (PLT-10, F31)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/pembelian/pemasok/impor');
        window.history.replaceState({}, '', '/kelola/pembelian/pemasok/impor');
    });
    afterEach(() => cleanup());

    it('tanpa berkas: tombol periksa nonaktif; templat bisa diunduh; batas baris tampil', () => {
        RenderUji(<HalamanImporPemasok Hasil={null} MaksimalBaris={2000} />);
        expect(screen.getByRole('button', { name: 'Periksa berkas' }).hasAttribute('disabled')).toBe(true);
        expect(screen.getByRole('link', { name: 'Unduh templat' }).getAttribute('href')).toBe(
            '/kelola/pembelian/pemasok/impor/templat',
        );
        expect(screen.getByText(/2\.000 baris per berkas/)).toBeTruthy();
    });

    it('hasil periksa: ringkasan, belum disimpan, baris bermasalah tampil', () => {
        RenderUji(<HalamanImporPemasok Hasil={hasilPeriksa} MaksimalBaris={2000} />);
        expect(screen.getByText(/100 pemasok siap diimpor\. Belum ada yang disimpan\./)).toBeTruthy();
        expect(screen.getByText('120')).toBeTruthy();
        expect(screen.getByText('CV Sumber Pangan Makmur Sentosa')).toBeTruthy();
        expect(screen.getByText(/Termin harus angka/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: /Impor .* pemasok baru/ })).toBeNull();
    });

    it('pilih berkas lalu periksa: kirim POST ke alamat impor pemasok', () => {
        const { container } = RenderUji(<HalamanImporPemasok Hasil={null} MaksimalBaris={2000} />);
        const masukan = container.querySelector('input[type="file"]') as HTMLInputElement;
        const berkas = new File(['Nama\nCV Sumber Pangan'], 'pemasok.csv', { type: 'text/csv' });
        fireEvent.change(masukan, { target: { files: [berkas] } });
        fireEvent.click(screen.getByRole('button', { name: 'Periksa berkas' }));

        expect(kirimanForm.at(-1)).toMatchObject({ metode: 'post', url: '/kelola/pembelian/pemasok/impor' });
    });
});
