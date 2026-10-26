import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import TabelUbahVarian from '@/Komponen/Katalog/TabelUbahVarian';
import { RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { UbahNilai } from '@/Pengujian/InteraksiPilihan';
import { SiapkanKameraTiruan } from '@/Pengujian/KameraTiruan';
import type { BarisVarian } from '@/Tipe/Katalog';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const kamera = vi.hoisted(() => ({
    mulai: vi.fn(),
    daftar: vi.fn(),
}));

vi.mock('@/Pustaka/PemindaiKamera', async (impor) => ({
    ...(await impor<typeof import('@/Pustaka/PemindaiKamera')>()),
    MulaiPemindaian: kamera.mulai,
    DaftarKamera: kamera.daftar,
}));

const Varian = (uuid: string, nama: string, barcode: string | null): BarisVarian => ({
    Uuid: uuid,
    Nama: nama,
    Sku: null,
    Atribut: [],
    HargaDasar: '75000.00',
    Barcode: barcode,
    Status: 'Aktif',
});

describe('Tabel ubah varian (audit kemudahan pakai #18)', () => {
    afterEach(() => cleanup());

    it('isi harga sama untuk semua lalu simpan hanya baris yang berubah. Barcode yang sudah ada terkunci', () => {
        RenderUji(
            <TabelUbahVarian
                uuidProduk="01J9PRD0000000000000000000"
                bolehUbahHarga
                varian={[
                    Varian('01J9PRD0000000000000000001', 'Kaos S', '8991234500011'),
                    Varian('01J9PRD0000000000000000002', 'Kaos M', null),
                ]}
            />,
        );
        expect((screen.getByLabelText('Barcode Kaos S') as HTMLInputElement).disabled).toBe(true);
        UbahNilai(screen.getByLabelText('Harga untuk semua varian'), '79000');
        fireEvent.click(screen.getByRole('button', { name: 'Isi sama untuk semua' }));
        UbahNilai(screen.getByLabelText('Barcode Kaos M'), '8991234500028');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan perubahan varian' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/produk/01J9PRD0000000000000000000/varian',
            {
                Baris: [
                    { Uuid: '01J9PRD0000000000000000001', Harga: '79000', Barcode: null },
                    { Uuid: '01J9PRD0000000000000000002', Harga: '79000', Barcode: '8991234500028' },
                ],
            },
            expect.anything(),
        );
    });

    describe('pindai kamera per baris', () => {
        beforeEach(() => {
            kamera.mulai.mockReset();
            kamera.daftar.mockReset();
        });
        afterEach(() => cleanup());

        it('barcode dari kamera mengisi baris yang masih kosong. Baris yang sudah punya barcode tidak punya tombol kamera', async () => {
            const tiruan = SiapkanKameraTiruan(kamera);

            RenderUji(
                <TabelUbahVarian
                    uuidProduk="01J9PRD0000000000000000000"
                    bolehUbahHarga
                    varian={[
                        Varian('01J9PRD0000000000000000001', 'Kaos S', '8991234500011'),
                        Varian('01J9PRD0000000000000000002', 'Kaos M', null),
                    ]}
                />,
            );

            expect(screen.queryByRole('button', { name: 'Pindai barcode Kaos S' })).toBeNull();
            fireEvent.click(screen.getByRole('button', { name: 'Pindai barcode Kaos M' }));
            await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());
            tiruan.terbaca('8991234500028');

            await waitFor(() =>
                expect((screen.getByLabelText('Barcode Kaos M') as HTMLInputElement).value).toBe('8991234500028'),
            );
            fireEvent.click(screen.getByRole('button', { name: 'Simpan perubahan varian' }));
            expect(tiruanRouter.put).toHaveBeenCalledWith(
                '/kelola/produk/01J9PRD0000000000000000000/varian',
                { Baris: [{ Uuid: '01J9PRD0000000000000000002', Harga: null, Barcode: '8991234500028' }] },
                expect.anything(),
            );
        });
    });
});
