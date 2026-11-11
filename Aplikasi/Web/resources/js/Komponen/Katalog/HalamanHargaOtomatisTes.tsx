import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanHargaOtomatis from '@/Halaman/Kelola/Produk/HargaOtomatis';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { PilihOpsi } from '@/Pengujian/InteraksiPilihan';
import type { PropsHargaOtomatis } from '@/Tipe/Katalog';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-266 halaman Harga otomatis: satu formulir (saklar, untung, pembulatan, arah, batas persetujuan) dengan satu tombol
 * Simpan. Untung khusus dan harga yang menunggu persetujuan hanya tampil bila relevan.
 */
const Awal: PropsHargaOtomatis = {
    Pengaturan: { Aktif: false, BatasPerubahanPersen: '20.00' },
    Umum: { PersenUntung: '20.0000', Pembulatan: 500, Arah: 'NaikTurun' },
    Pengecualian: [],
    Usulan: [],
    OpsiKategori: [{ Uuid: '01J9KTG0000000000000000001', Nama: 'Minuman › Literan' }],
    OpsiCakupan: [],
    OpsiArah: [],
    OpsiPembulatan: [0, 100, 500, 1000],
    Izin: { Ubah: true },
};

const Khusus: PropsHargaOtomatis['Pengecualian'][number] = {
    Uuid: '01J9ATR0000000000000000001',
    Cakupan: 'Kategori',
    Sasaran: 'Minuman › Literan',
    UuidKategori: '01J9KTG0000000000000000001',
    Produk: null,
    PersenUntung: '30.0000',
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

const Menyala: Partial<PropsHargaOtomatis> = { Pengaturan: { Aktif: true, BatasPerubahanPersen: '20.00' } };

function Pasang(props: Partial<PropsHargaOtomatis> = {}) {
    AturHalamanUji({}, '/kelola/produk/harga-otomatis');
    RenderUji(<HalamanHargaOtomatis {...Awal} {...props} />);
}

function Isian(nama: RegExp) {
    return screen.getByRole<HTMLInputElement>('textbox', { name: nama });
}

describe('Halaman Harga otomatis', () => {
    beforeEach(() => {
        tiruanRouter.post.mockClear();
        tiruanRouter.put.mockClear();
        tiruanRouter.delete.mockClear();
    });

    afterEach(() => cleanup());

    it('awalnya mati: isian terkunci, Simpan mati, dan bagian lain tidak tampil', () => {
        Pasang();

        expect(
            screen.getByRole('checkbox', { name: 'Harga jual mengikuti harga beli' }).getAttribute('data-state'),
        ).toBe('unchecked');
        expect(Isian(/Untung dari harga beli/).disabled).toBe(true);
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Simpan' }).disabled).toBe(true);
        expect(screen.queryByText('Untung khusus')).toBeNull();
        expect(screen.queryByText('Menunggu persetujuan Anda')).toBeNull();
    });

    it('menyalakan lalu Simpan mengirim saklar, untung, pembulatan, arah, dan batas dalam satu permintaan', () => {
        Pasang();
        fireEvent.click(screen.getByRole('checkbox', { name: 'Harga jual mengikuti harga beli' }));
        fireEvent.change(Isian(/Untung dari harga beli/), { target: { value: '25' } });
        fireEvent.change(Isian(/Minta persetujuan kalau harga berubah lebih dari/), { target: { value: '30' } });
        PilihOpsi(screen.getByRole('combobox', { name: 'Kalau harga beli turun' }), 'HanyaNaik');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan' }));

        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/produk/harga-otomatis/pengaturan',
            { Aktif: true, BatasPerubahanPersen: '30', PersenUntung: '25', Pembulatan: 500, Arah: 'HanyaNaik' },
            expect.anything(),
        );
    });

    it('menyala: bagian Untung khusus tampil, kosong berarti semua produk memakai untung utama', () => {
        Pasang(Menyala);

        expect(screen.getByText('Untung khusus')).toBeTruthy();
        expect(screen.getByText(/Semua produk memakai untung di atas/)).toBeTruthy();
        expect(Isian(/Untung dari harga beli/).value).toBe('20');
    });

    it('untung khusus tampil sebagai daftar sederhana, Hapus meminta konfirmasi lalu mengirim DELETE', () => {
        Pasang({ ...Menyala, Pengecualian: [Khusus] });

        expect(screen.getByText('Minuman › Literan')).toBeTruthy();
        expect(screen.getByText('Untung 30%')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Hapus' }));
        expect(tiruanRouter.delete).not.toHaveBeenCalled();
        fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Hapus' }));
        expect(tiruanRouter.delete.mock.calls[0]?.[0]).toBe(
            '/kelola/produk/harga-otomatis/aturan/01J9ATR0000000000000000001',
        );
    });

    it('tambah untung khusus untuk satu kategori: hanya menanyakan kategori dan untung, pembulatan mengikuti pengaturan utama', () => {
        Pasang(Menyala);
        fireEvent.click(screen.getByRole('button', { name: 'Tambah untung khusus' }));
        const dialog = screen.getByRole('dialog');

        expect(within(dialog).queryByText('Pembulatan harga')).toBeNull();
        PilihOpsi(within(dialog).getByRole('combobox', { name: 'Kategori' }), '01J9KTG0000000000000000001');
        fireEvent.change(within(dialog).getByRole('textbox', { name: /Untung dari harga beli/ }), {
            target: { value: '35' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/produk/harga-otomatis/aturan',
            {
                Cakupan: 'Kategori',
                UuidKategori: '01J9KTG0000000000000000001',
                UuidProduk: null,
                PersenUntung: '35',
                Pembulatan: 500,
                Arah: 'NaikTurun',
                Aktif: true,
            },
            expect.anything(),
        );
    });

    it('harga yang menunggu persetujuan: tampil lama menjadi baru, Setujui dan Tolak mengirim POST', () => {
        Pasang({ ...Menyala, Usulan: [Usulan] });

        expect(screen.getByText('Menunggu persetujuan Anda')).toBeTruthy();
        expect(screen.getByText('Gula Pasir Lokal 1 kg')).toBeTruthy();
        expect(screen.getByText(/Rp 12\.000 menjadi Rp 120\.000 \(\+900%\)/)).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Setujui' }));
        expect(tiruanRouter.post.mock.calls[0]?.[0]).toBe(
            '/kelola/produk/harga-otomatis/usulan/01J9USL0000000000000000001/terapkan',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Tolak' }));
        expect(tiruanRouter.post.mock.calls[1]?.[0]).toBe(
            '/kelola/produk/harga-otomatis/usulan/01J9USL0000000000000000001/tolak',
        );
    });

    it('Hitung ulang semua harga hanya tampil saat menyala dan belum ada perubahan, lalu meminta konfirmasi', () => {
        Pasang();
        expect(screen.queryByRole('button', { name: 'Hitung ulang semua harga sekarang' })).toBeNull();
        cleanup();

        Pasang(Menyala);
        fireEvent.click(screen.getByRole('button', { name: 'Hitung ulang semua harga sekarang' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Hitung ulang' }));

        expect(tiruanRouter.post.mock.calls[0]?.[0]).toBe('/kelola/produk/harga-otomatis/jalankan');
    });

    it('tanpa izin ubah harga: semua tombol disembunyikan dan isian terkunci', () => {
        Pasang({ ...Menyala, Izin: { Ubah: false }, Pengecualian: [Khusus], Usulan: [Usulan] });

        for (const nama of [
            'Simpan',
            'Tambah untung khusus',
            'Setujui',
            'Tolak',
            'Hapus',
            'Hitung ulang semua harga sekarang',
        ]) {
            expect(screen.queryByRole('button', { name: nama })).toBeNull();
        }

        expect(Isian(/Untung dari harga beli/).disabled).toBe(true);
    });
});
