import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import type { Meja } from '@/Komponen/Kelola/BagianMeja';
import DenahMeja, { HitungTitikKanvas, RapatkanKeKanvas } from '@/Komponen/Kelola/DenahMeja';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

function BuatMeja(nama: string, posisi: [number, number] | null, tambahan: Partial<Meja> = {}): Meja {
    return {
        Uuid: `UUID-${nama}`,
        Nama: nama,
        UuidArea: null,
        NamaArea: null,
        Kapasitas: 4,
        Bentuk: 'Persegi',
        Urutan: 0,
        PosisiX: posisi?.[0] ?? null,
        PosisiY: posisi?.[1] ?? null,
        Status: 'Aktif',
        ...tambahan,
    };
}

beforeEach(() => AturHalamanUji({}, '/kelola/outlet/O-1'));
afterEach(() => cleanup());

describe('POS-03 denah meja: hitungan', () => {
    it('merapatkan ke kelipatan 10 dan menjepit ke 0–1000', () => {
        expect(RapatkanKeKanvas(-40)).toBe(0);
        expect(RapatkanKeKanvas(1234)).toBe(1000);
        expect(RapatkanKeKanvas(444)).toBe(440);
        expect(RapatkanKeKanvas(446)).toBe(450);
    });

    it('titik kanvas dari pointer relatif ke kotak; kotak tanpa ukuran = tengah', () => {
        const kotak = { left: 100, top: 50, width: 400, height: 250 };

        expect(HitungTitikKanvas(300, 175, kotak)).toEqual({ x: 500, y: 500 });
        expect(HitungTitikKanvas(900, -20, kotak)).toEqual({ x: 1000, y: 0 });
        expect(HitungTitikKanvas(10, 10, { left: 0, top: 0, width: 0, height: 0 })).toEqual({ x: 500, y: 500 });
    });
});

describe('POS-03 denah meja: layar', () => {
    it('mode baca: meja tertata tampil, yang belum ditata di baris sendiri, tanpa tombol bila tanpa izin', () => {
        RenderUji(
            <DenahMeja
                alamatOutlet="/kelola/outlet/O-1"
                meja={[BuatMeja('1', [200, 300]), BuatMeja('2', null)]}
                bolehKelola={false}
            />,
        );

        expect((screen.getByRole('button', { name: /^Meja 1, 4 orang$/ }) as HTMLButtonElement).disabled).toBe(true);
        expect(screen.getByText('Belum ditata (1)')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Atur denah' })).toBeNull();
    });

    it('denah kosong: ajakan menata; meja diarsipkan tidak ikut', () => {
        RenderUji(
            <DenahMeja
                alamatOutlet="/kelola/outlet/O-1"
                meja={[BuatMeja('1', null), BuatMeja('9', [100, 100], { Status: 'Diarsipkan' })]}
                bolehKelola
            />,
        );

        expect(screen.getByText('Denah belum ditata')).toBeTruthy();
        expect(screen.queryByRole('button', { name: /Meja 9/ })).toBeNull();
    });

    it('atur: taruh meja dari baris belum ditata, geser dengan panah, Shift loncat jauh, Hapus mengembalikan, simpan mengirim semua meja aktif', () => {
        RenderUji(
            <DenahMeja
                alamatOutlet="/kelola/outlet/O-1"
                meja={[BuatMeja('1', [200, 300]), BuatMeja('2', null)]}
                bolehKelola
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Atur denah' }));
        fireEvent.click(screen.getByRole('button', { name: 'Taruh meja 2 di denah' }));

        const dua = screen.getByRole('button', { name: /^Meja 2, 4 orang/ });
        fireEvent.keyDown(dua, { key: 'ArrowRight' });
        fireEvent.keyDown(dua, { key: 'ArrowDown', shiftKey: true });

        const satu = screen.getByRole('button', { name: /^Meja 1, 4 orang/ });
        fireEvent.keyDown(satu, { key: 'ArrowLeft' });
        fireEvent.keyDown(satu, { key: 'ArrowUp' });

        fireEvent.click(screen.getByRole('button', { name: 'Simpan denah' }));

        expect(tiruanRouter.put).toHaveBeenCalledTimes(1);
        expect(tiruanRouter.put.mock.calls[0]?.[0]).toBe('/kelola/outlet/O-1/denah-meja');
        expect(tiruanRouter.put.mock.calls[0]?.[1]).toEqual({
            Meja: [
                { Uuid: 'UUID-1', PosisiX: 190, PosisiY: 290 },
                { Uuid: 'UUID-2', PosisiX: 510, PosisiY: 550 },
            ],
        });

        // Server menjawab: tombol kembali normal (tiruan router tidak memanggil onFinish sendiri).
        act(() => (tiruanRouter.put.mock.calls[0]?.[2] as { onFinish: () => void }).onFinish());

        // Hapus mengembalikan meja ke baris "Belum ditata" dan terkirim sebagai null.
        fireEvent.keyDown(screen.getByRole('button', { name: /^Meja 1, 4 orang/ }), { key: 'Delete' });
        expect(screen.getByText('Belum ditata (1)')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Simpan denah' }));
        expect(tiruanRouter.put.mock.calls[1]?.[1]).toMatchObject({
            Meja: [{ Uuid: 'UUID-1', PosisiX: null, PosisiY: null }, { Uuid: 'UUID-2' }],
        });
    });

    it('seret dengan pointer memakai kotak kanvas; batal mengembalikan letak semula', () => {
        RenderUji(<DenahMeja alamatOutlet="/kelola/outlet/O-1" meja={[BuatMeja('1', [200, 300])]} bolehKelola />);
        fireEvent.click(screen.getByRole('button', { name: 'Atur denah' }));

        const kanvas = screen.getByRole('group', { name: 'Kanvas denah meja' });
        kanvas.getBoundingClientRect = () => ({
            left: 0,
            top: 0,
            width: 1000,
            height: 625,
            right: 1000,
            bottom: 625,
            x: 0,
            y: 0,
            toJSON: () => ({}),
        });

        const meja = screen.getByRole('button', { name: /^Meja 1, 4 orang/ });
        fireEvent.pointerDown(meja, { pointerId: 1, clientX: 200, clientY: 187 });
        fireEvent.pointerMove(meja, { pointerId: 1, clientX: 700, clientY: 312 });
        fireEvent.pointerUp(meja, { pointerId: 1 });
        // Setelah dilepas, gerakan berikutnya tidak menyeret lagi.
        fireEvent.pointerMove(meja, { pointerId: 1, clientX: 10, clientY: 10 });

        expect(meja.style.left).toBe('70%');
        expect(meja.style.top).toBe('50%');

        fireEvent.click(screen.getByRole('button', { name: 'Batal' }));
        expect(screen.getByRole('button', { name: /^Meja 1, 4 orang/ }).style.left).toBe('20%');
        expect(tiruanRouter.put).not.toHaveBeenCalled();
    });
});
