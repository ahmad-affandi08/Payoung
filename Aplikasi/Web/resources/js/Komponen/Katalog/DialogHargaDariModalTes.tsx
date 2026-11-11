import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import DialogHargaDariModal from '@/Komponen/Katalog/DialogHargaDariModal';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import type { PratinjauHargaDariModal } from '@/Tipe/Katalog';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-266 "Harga dari harga beli": isi untung dan pembulatan, lihat contoh dari server (tidak menyimpan), baru terapkan.
 * Mengubah isian membatalkan contoh supaya yang diterapkan selalu persis yang terlihat.
 */
const Uuid = ['01J9PRD0000000000000000001', '01J9PRD0000000000000000002', '01J9PRD0000000000000000003'];

const Pratinjau: PratinjauHargaDariModal = {
    Baris: [
        {
            Uuid: Uuid[0] ?? '',
            Nama: 'Gula Pasir Lokal 1 kg',
            Sku: 'GLP',
            Status: 'Berubah',
            HargaLama: '11000.00',
            Modal: '10000.00',
            HargaBaru: '12000.00',
            PerubahanPersen: '9.09',
        },
        {
            Uuid: Uuid[1] ?? '',
            Nama: 'Minyak Goreng 2 liter',
            Sku: null,
            Status: 'TanpaHargaBeli',
            HargaLama: null,
            Modal: null,
            HargaBaru: null,
            PerubahanPersen: null,
        },
        {
            Uuid: Uuid[2] ?? '',
            Nama: 'Beras Pandan Wangi 5 kg',
            Sku: null,
            Status: 'Sama',
            HargaLama: '70000.00',
            Modal: '58000.00',
            HargaBaru: '70000.00',
            PerubahanPersen: '0.00',
        },
    ],
    Ringkasan: { Berubah: 1, Sama: 1, TanpaHargaBeli: 1, TanpaHarga: 0, DilewatiJenis: 0 },
};

function Pasang(saatTutup = vi.fn(), saatSelesai = vi.fn()) {
    AturHalamanUji({}, '/kelola/produk');
    RenderUji(<DialogHargaDariModal uuid={Uuid} saatTutup={saatTutup} saatSelesai={saatSelesai} />);

    return { saatTutup, saatSelesai };
}

describe('Dialog Harga dari harga beli', () => {
    let FetchTiruan: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        tiruanRouter.post.mockClear();
        FetchTiruan = vi
            .fn()
            .mockResolvedValue({ ok: true, status: 200, json: () => Promise.resolve({ Data: Pratinjau }) });
        vi.stubGlobal('fetch', FetchTiruan);
    });

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('menampilkan jumlah produk di judul dan bawaan untung 20% dibulatkan ke Rp 500', () => {
        Pasang();

        expect(screen.getByText('Harga dari harga beli, 3 produk')).toBeTruthy();
        expect(screen.getByRole<HTMLInputElement>('textbox', { name: /Untung dari modal/ }).value).toBe('20');
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Lihat contoh' }).disabled).toBe(false);
    });

    it('lihat contoh: mengirim untung dan pembulatan ke server, menampilkan harga lama ke baru dan alasan yang dilewati', async () => {
        Pasang();
        fireEvent.click(screen.getByRole('button', { name: 'Lihat contoh' }));

        expect(await screen.findByText('1 produk akan berubah harganya.')).toBeTruthy();
        expect(screen.getByText('Gula Pasir Lokal 1 kg')).toBeTruthy();
        expect(screen.getByText('Rp 12.000')).toBeTruthy();
        expect(screen.getByText(/\+9,09%/)).toBeTruthy();
        expect(screen.getByText('Belum pernah dibeli')).toBeTruthy();
        expect(screen.getByText('Harganya sudah pas')).toBeTruthy();

        const [alamat, opsi] = FetchTiruan.mock.calls[0] as [string, { body: string }];
        expect(alamat).toBe('/kelola/produk/harga-dari-modal/pratinjau');
        expect(JSON.parse(opsi.body)).toEqual({ Uuid, PersenUntung: '20', Pembulatan: 500 });
        // Menyimpan baru terjadi saat "Terapkan harga baru".
        expect(tiruanRouter.post).not.toHaveBeenCalled();
    });

    it('terapkan: mengirim isian yang sama lewat Inertia dan menutup dialog bila berhasil', async () => {
        const { saatSelesai: Selesai } = Pasang();
        fireEvent.click(screen.getByRole('button', { name: 'Lihat contoh' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Terapkan harga baru' }));

        const [alamat, data, opsi] = tiruanRouter.post.mock.calls[0] as [
            string,
            Record<string, unknown>,
            { onSuccess: () => void },
        ];
        expect(alamat).toBe('/kelola/produk/harga-dari-modal');
        expect(data).toEqual({ Uuid, PersenUntung: '20', Pembulatan: 500 });
        opsi.onSuccess();
        expect(Selesai).toHaveBeenCalled();
    });

    it('mengubah untung membatalkan contoh, jadi tombol kembali "Lihat contoh"', async () => {
        Pasang();
        fireEvent.click(screen.getByRole('button', { name: 'Lihat contoh' }));
        await screen.findByRole('button', { name: 'Terapkan harga baru' });

        fireEvent.change(screen.getByRole('textbox', { name: /Untung dari modal/ }), { target: { value: '25' } });

        expect(screen.queryByRole('button', { name: 'Terapkan harga baru' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Lihat contoh' })).toBeTruthy();
    });

    it('tidak ada produk yang berubah: Terapkan dimatikan', async () => {
        FetchTiruan.mockResolvedValue({
            ok: true,
            status: 200,
            json: () =>
                Promise.resolve({
                    Data: {
                        Baris: [],
                        Ringkasan: { Berubah: 0, Sama: 3, TanpaHargaBeli: 0, TanpaHarga: 0, DilewatiJenis: 0 },
                    },
                }),
        });
        Pasang();
        fireEvent.click(screen.getByRole('button', { name: 'Lihat contoh' }));

        expect(await screen.findByText('Tidak ada produk yang berubah harganya.')).toBeTruthy();
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Terapkan harga baru' }).disabled).toBe(true);
    });

    it('galat dari server tampil dan contoh tidak ada', async () => {
        FetchTiruan.mockResolvedValue({
            ok: false,
            status: 422,
            json: () =>
                Promise.resolve({
                    errors: { PersenUntung: ['Isi untung berupa angka, misal 20 atau 7,5 (persen dari modal).'] },
                }),
        });
        Pasang();
        fireEvent.click(screen.getByRole('button', { name: 'Lihat contoh' }));

        expect(await screen.findByText(/Isi untung berupa angka/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Terapkan harga baru' })).toBeNull();
    });
});
