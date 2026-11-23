import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { PilihOpsi } from '@/Pengujian/InteraksiPilihan';
import type { StasiunDapurProduk } from '@/Tipe/Katalog';

import PanelStasiunDapur from './PanelStasiunDapur';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const opsi = [
    { Nilai: 'S-BAR', Label: 'Bar' },
    { Nilai: 'S-DAPUR', Label: 'Dapur' },
];

function Data(timpa: Partial<StasiunDapurProduk> = {}): StasiunDapurProduk {
    return { Opsi: opsi, UuidStasiun: null, NamaStasiunKategori: 'Bar', NamaStasiunDiarsipkan: null, ...timpa };
}

beforeEach(() => AturHalamanUji({}, '/kelola/produk/P-1'));
afterEach(() => cleanup());

describe('D-310 PanelStasiunDapur (stasiun dapur per menu)', () => {
    it('tanpa aturan khusus: menunjukkan stasiun kategori dan tombol simpan mati sampai ada perubahan', () => {
        render(<PanelStasiunDapur uuidProduk="P-1" data={Data()} bolehUbah />);

        expect(screen.getByRole('combobox', { name: /Dikirim ke stasiun/ }).textContent).toContain(
            'Ikut kategori (Bar)',
        );
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Simpan stasiun' }).disabled).toBe(true);
    });

    it('memilih stasiun lain lalu simpan: kirim UuidStasiun, memilih kembali ke kategori mengirim null', () => {
        render(<PanelStasiunDapur uuidProduk="P-1" data={Data()} bolehUbah />);

        PilihOpsi(screen.getByRole('combobox', { name: /Dikirim ke stasiun/ }), 'S-DAPUR');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan stasiun' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/produk/P-1/stasiun-dapur',
            { UuidStasiun: 'S-DAPUR' },
            expect.objectContaining({ preserveScroll: true }),
        );

        cleanup();
        render(<PanelStasiunDapur uuidProduk="P-1" data={Data({ UuidStasiun: 'S-DAPUR' })} bolehUbah />);
        PilihOpsi(screen.getByRole('combobox', { name: /Dikirim ke stasiun/ }), '');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan stasiun' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            '/kelola/produk/P-1/stasiun-dapur',
            { UuidStasiun: null },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('kategori tanpa stasiun: keterangan memakai stasiun bawaan. Stasiun diarsipkan tetap tampil bertanda', () => {
        render(
            <PanelStasiunDapur
                uuidProduk="P-1"
                data={Data({ UuidStasiun: 'S-LAMA', NamaStasiunKategori: null, NamaStasiunDiarsipkan: 'Pastry' })}
                bolehUbah
            />,
        );

        expect(screen.getByRole('combobox', { name: /Dikirim ke stasiun/ }).textContent).toContain(
            'Pastry (diarsipkan)',
        );
    });

    it('tanpa izin ubah: pilihan terkunci dan tidak ada tombol simpan', () => {
        render(<PanelStasiunDapur uuidProduk="P-1" data={Data({ UuidStasiun: 'S-BAR' })} bolehUbah={false} />);

        expect(screen.getByRole<HTMLButtonElement>('combobox', { name: /Dikirim ke stasiun/ }).disabled).toBe(true);
        expect(screen.queryByRole('button', { name: 'Simpan stasiun' })).toBeNull();
    });
});
