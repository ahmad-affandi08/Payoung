import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanImporPelanggan from '@/Halaman/Kelola/Pelanggan/Impor';
import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import type { HasilImporPelanggan } from '@/Tipe/Pelanggan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const hasilPeriksa: HasilImporPelanggan = {
    Terapkan: false,
    NamaBerkas: 'pelanggan-lama.xlsx',
    JumlahBaris: 1250,
    Baru: 1180,
    SudahAda: 60,
    Bermasalah: 10,
    Masalah: [{ Baris: 14, Nama: 'Siti Rahmawati Kusumawardhani', Pesan: 'Nomor HP tidak valid.' }],
};

describe('Impor pelanggan (F-16a, v3.36)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/pelanggan/impor');
        window.history.replaceState({}, '', '/kelola/pelanggan/impor');
    });
    afterEach(() => cleanup());

    it('tanpa berkas: tombol periksa nonaktif. Templat bisa diunduh', () => {
        RenderUji(<HalamanImporPelanggan Hasil={null} MaksimalBaris={5000} />);
        expect(screen.getByRole('button', { name: 'Periksa berkas' }).hasAttribute('disabled')).toBe(true);
        expect(screen.getByRole('link', { name: 'Unduh templat' }).getAttribute('href')).toBe(
            '/kelola/pelanggan/impor/templat',
        );
        expect(screen.getByText(/5\.000 baris per berkas/)).toBeTruthy();
    });

    it('hasil periksa: ringkasan, belum disimpan, baris bermasalah tampil', () => {
        RenderUji(<HalamanImporPelanggan Hasil={hasilPeriksa} MaksimalBaris={5000} />);
        expect(screen.getByText(/1\.180 pelanggan siap diimpor\. Belum ada yang disimpan\./)).toBeTruthy();
        expect(screen.getByText('1.250')).toBeTruthy();
        expect(screen.getAllByText('Nomor HP tidak valid.').length).toBeGreaterThan(0);
        // Berkas belum dipilih ulang di halaman ini: tombol impor belum muncul.
        expect(screen.queryByRole('button', { name: /Impor .* pelanggan baru/ })).toBeNull();
    });

    it('pilih berkas lalu periksa: kirim POST tanpa Terapkan', () => {
        const { container } = RenderUji(<HalamanImporPelanggan Hasil={null} MaksimalBaris={5000} />);
        const masukan = container.querySelector('input[type="file"]') as HTMLInputElement;
        const berkas = new File(['Nama,NoHp\nSiti,081211112222'], 'pelanggan.csv', { type: 'text/csv' });
        fireEvent.change(masukan, { target: { files: [berkas] } });
        fireEvent.click(screen.getByRole('button', { name: 'Periksa berkas' }));

        expect(kirimanForm.at(-1)).toMatchObject({ metode: 'post', url: '/kelola/pelanggan/impor' });
    });
});
