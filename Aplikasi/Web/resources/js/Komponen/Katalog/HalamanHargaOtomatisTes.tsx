import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import type { PropsHargaOtomatis } from '@/Tipe/Katalog';

import HalamanHargaOtomatis from '@/Halaman/Kelola/Produk/HargaOtomatis';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-266 halaman Harga otomatis: saklar utama, batas aman, aturan untung, usulan yang menunggu keputusan, dan
 * "Terapkan sekarang".
 */
const Awal: PropsHargaOtomatis = {
    Pengaturan: { Aktif: false, BatasPerubahanPersen: '20.00' },
    Aturan: [],
    Usulan: [],
    OpsiKategori: [{ Uuid: '01J9KTG0000000000000000001', Nama: 'Minuman › Literan' }],
    OpsiCakupan: [
        { Nilai: 'Semua', Label: 'Semua produk' },
        { Nilai: 'Kategori', Label: 'Satu kategori' },
        { Nilai: 'Produk', Label: 'Satu produk' },
    ],
    OpsiArah: [
        { Nilai: 'NaikTurun', Label: 'Naik dan turun mengikuti harga beli' },
        { Nilai: 'HanyaNaik', Label: 'Hanya naik, tidak pernah turun sendiri' },
    ],
    OpsiPembulatan: [0, 100, 500, 1000],
    Izin: { Ubah: true },
};

const AturanSemua: PropsHargaOtomatis['Aturan'][number] = {
    Uuid: '01J9ATR0000000000000000001',
    Cakupan: 'Semua',
    Sasaran: 'Semua produk berstok',
    UuidKategori: null,
    Produk: null,
    PersenUntung: '20.0000',
    Pembulatan: 500,
    Arah: 'NaikTurun',
    Aktif: true,
};

const Usulan: PropsHargaOtomatis['Usulan'][number] = {
    Uuid: '01J9USL0000000000000000001',
    Produk: { Uuid: '01J9PRD0000000000000000001', Nama: 'Gula Pasir Lokal 1 kg', Sku: 'GLP' },
    Modal: '100000.00',
    PersenUntung: '20.0000',
    HargaLama: '12000.00',
    HargaBaru: '120000.00',
    PerubahanPersen: '900.00',
    DibuatPada: '2026-11-11T09:00:00+07:00',
};

function Pasang(props: Partial<PropsHargaOtomatis> = {}) {
    AturHalamanUji({}, '/kelola/produk/harga-otomatis');
    RenderUji(<HalamanHargaOtomatis {...Awal} {...props} />);
}

describe('Halaman Harga otomatis', () => {
    beforeEach(() => {
        tiruanRouter.post.mockClear();
        tiruanRouter.put.mockClear();
        tiruanRouter.delete.mockClear();
    });

    afterEach(() => cleanup());

    it('keadaan awal: saklar mati, batas aman 20, belum ada aturan dan usulan', () => {
        Pasang();

        expect(
            screen.getByRole('checkbox', { name: 'Harga jual mengikuti harga beli' }).getAttribute('data-state'),
        ).toBe('unchecked');
        expect(
            screen.getByRole<HTMLInputElement>('textbox', { name: /Perubahan terbesar yang diterapkan sendiri/ }).value,
        ).toBe('20');
        expect(screen.getByText(/Belum ada aturan/)).toBeTruthy();
        // Tanpa usulan, bagian keputusan tidak ditampilkan sama sekali.
        expect(screen.queryByRole('region', { name: 'Usulan menunggu keputusan' })).toBeNull();
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Simpan pengaturan' }).disabled).toBe(true);
    });

    it('menyalakan saklar dan menyimpan mengirim Aktif dan batas aman', () => {
        Pasang();
        fireEvent.click(screen.getByRole('checkbox', { name: 'Harga jual mengikuti harga beli' }));
        fireEvent.change(screen.getByRole('textbox', { name: /Perubahan terbesar yang diterapkan sendiri/ }), {
            target: { value: '30' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan' }));

        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/produk/harga-otomatis/pengaturan',
            { Aktif: true, BatasPerubahanPersen: '30' },
            expect.anything(),
        );
    });

    it('aturan tampil dengan untung, pembulatan, dan arah; Hapus meminta konfirmasi lalu mengirim DELETE', () => {
        Pasang({ Aturan: [AturanSemua] });

        expect(screen.getByText('Semua produk berstok')).toBeTruthy();
        expect(screen.getByText('20%')).toBeTruthy();
        expect(screen.getByText('Dibulatkan ke Rp 500 terdekat')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Hapus' }));
        expect(tiruanRouter.delete).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Hapus aturan' }));
        expect(tiruanRouter.delete.mock.calls[0]?.[0]).toBe(
            '/kelola/produk/harga-otomatis/aturan/01J9ATR0000000000000000001',
        );
    });

    it('tambah aturan untuk semua produk: mengirim cakupan, untung, pembulatan, dan arah', () => {
        Pasang();
        fireEvent.click(screen.getByRole('button', { name: 'Tambah aturan' }));
        const dialog = screen.getByRole('dialog');

        fireEvent.change(within(dialog).getByRole('textbox', { name: /Untung dari modal/ }), {
            target: { value: '25' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan aturan' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/produk/harga-otomatis/aturan',
            {
                Cakupan: 'Semua',
                UuidKategori: null,
                UuidProduk: null,
                PersenUntung: '25',
                Pembulatan: 500,
                Arah: 'NaikTurun',
                Aktif: true,
            },
            expect.anything(),
        );
    });

    it('usulan: menampilkan harga sekarang, harga usulan, dan persen; Terapkan dan Tolak mengirim POST ke usulan itu', () => {
        Pasang({ Usulan: [Usulan] });

        expect(screen.getByText('Gula Pasir Lokal 1 kg')).toBeTruthy();
        expect(screen.getByText('Rp 12.000')).toBeTruthy();
        expect(screen.getByText('Rp 120.000')).toBeTruthy();
        expect(screen.getByText('+900%')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Terapkan' }));
        expect(tiruanRouter.post.mock.calls[0]?.[0]).toBe(
            '/kelola/produk/harga-otomatis/usulan/01J9USL0000000000000000001/terapkan',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Tolak' }));
        expect(tiruanRouter.post.mock.calls[1]?.[0]).toBe(
            '/kelola/produk/harga-otomatis/usulan/01J9USL0000000000000000001/tolak',
        );
    });

    it('Terapkan sekarang hanya muncul bila ada aturan dan meminta konfirmasi dulu', () => {
        Pasang();
        expect(screen.queryByRole('button', { name: 'Terapkan sekarang' })).toBeNull();
        cleanup();

        Pasang({ Aturan: [AturanSemua] });
        fireEvent.click(screen.getByRole('button', { name: 'Terapkan sekarang' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        const dialog = screen.getByRole('alertdialog');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Terapkan sekarang' }));

        expect(tiruanRouter.post.mock.calls[0]?.[0]).toBe('/kelola/produk/harga-otomatis/jalankan');
    });

    it('tanpa izin ubah harga: semua tombol ubah disembunyikan dan isian dimatikan', () => {
        Pasang({ Izin: { Ubah: false }, Aturan: [AturanSemua], Usulan: [Usulan] });

        expect(screen.queryByRole('button', { name: 'Tambah aturan' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Simpan pengaturan' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Terapkan' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Hapus' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Terapkan sekarang' })).toBeNull();
    });
});
