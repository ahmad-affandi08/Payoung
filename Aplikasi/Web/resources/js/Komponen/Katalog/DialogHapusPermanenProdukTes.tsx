import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { PratinjauHapusPermanen } from '@/Tipe/Katalog';

import DialogHapusPermanenProduk from './DialogHapusPermanenProduk';
import { AturHalamanUji, kirimanForm, RenderUji } from './TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('./TiruanInertia')).TiruanInertia);

const UUID = '01J9ZC5V7Q8R2T4W6Y8A0B2C4D';
const NAMA = 'Beras Premium Pulen 5 Kg';

function TiruFetch(isi: PratinjauHapusPermanen | number): void {
    vi.stubGlobal(
        'fetch',
        vi.fn(() =>
            Promise.resolve(
                typeof isi === 'number'
                    ? new Response('{}', { status: isi })
                    : new Response(JSON.stringify(isi), {
                          status: 200,
                          headers: { 'Content-Type': 'application/json' },
                      }),
            ),
        ),
    );
}

const bisaDihapus: PratinjauHapusPermanen = {
    Total: 1248,
    Tabel: 9,
    Penghalang: null,
    Rincian: [
        { Tabel: 'PenjualanDetail', Jumlah: 900 },
        { Tabel: 'MutasiStok', Jumlah: 300 },
        { Tabel: 'FakturPembelianDetail', Jumlah: 20 },
        { Tabel: 'StokAwalDetail', Jumlah: 10 },
        { Tabel: 'SaldoStok', Jumlah: 8 },
        { Tabel: 'ReturPenjualanDetail', Jumlah: 6 },
        { Tabel: 'TabelAsingBaru', Jumlah: 4 },
    ],
};

describe('D-218 dialog hapus permanen produk', () => {
    beforeEach(() => AturHalamanUji());
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('menampilkan peringatan, jumlah riwayat dengan nama yang mudah dipahami; tombol mati sampai nama diketik persis', async () => {
        TiruFetch(bisaDihapus);
        RenderUji(<DialogHapusPermanenProduk uuid={UUID} nama={NAMA} saatSelesai={vi.fn()} />);

        expect(await screen.findByText('1.248 baris riwayat di 9 tabel akan dihapus:')).toBeTruthy();
        expect(screen.getByText('900 baris penjualan')).toBeTruthy();
        expect(screen.getByText('300 mutasi stok')).toBeTruthy();
        expect(screen.getByText(/beserta seluruh riwayatnya/)).toBeTruthy();
        expect(screen.queryByText('4 TabelAsingBaru')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Lihat 1 lainnya' }));
        expect(screen.getByText('4 TabelAsingBaru')).toBeTruthy();

        const tombol = screen.getByRole<HTMLButtonElement>('button', { name: 'Hapus permanen' });
        expect(tombol.disabled).toBe(true);
        const isian = screen.getByLabelText(`Ketik nama produk "${NAMA}" untuk memastikan`);
        fireEvent.change(isian, { target: { value: 'beras premium' } });
        expect(tombol.disabled).toBe(true);
        fireEvent.change(isian, { target: { value: NAMA } });
        expect(tombol.disabled).toBe(false);
    });

    it('mengirim DELETE ke rute hapus-permanen dengan nama konfirmasi', async () => {
        TiruFetch(bisaDihapus);
        RenderUji(<DialogHapusPermanenProduk uuid={UUID} nama={NAMA} saatSelesai={vi.fn()} />);

        await screen.findByText('1.248 baris riwayat di 9 tabel akan dihapus:');
        fireEvent.change(screen.getByLabelText(`Ketik nama produk "${NAMA}" untuk memastikan`), {
            target: { value: NAMA },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Hapus permanen' }));

        await waitFor(() => expect(kirimanForm).toHaveLength(1));
        expect(kirimanForm[0]).toMatchObject({
            metode: 'delete',
            url: `/kelola/produk/${UUID}/hapus-permanen`,
            data: { KonfirmasiNama: NAMA },
        });
    });

    it('penghalang (stok masih ada / dipakai produk lain) ditampilkan dan tidak ada isian atau tombol hapus yang aktif', async () => {
        TiruFetch({
            Total: 12,
            Tabel: 3,
            Rincian: [{ Tabel: 'MutasiStok', Jumlah: 12 }],
            Penghalang: 'Produk ini masih punya stok. Kosongkan dulu lewat Penyesuaian stok (stok keluar).',
        });
        RenderUji(<DialogHapusPermanenProduk uuid={UUID} nama={NAMA} saatSelesai={vi.fn()} />);

        expect(await screen.findByText(/masih punya stok/)).toBeTruthy();
        expect(screen.queryByLabelText(`Ketik nama produk "${NAMA}" untuk memastikan`)).toBeNull();
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Hapus permanen' }).disabled).toBe(true);
    });

    it('produk tanpa riwayat dan galat memuat riwayat punya keadaan sendiri', async () => {
        TiruFetch({ Total: 0, Tabel: 0, Rincian: [], Penghalang: null });
        RenderUji(<DialogHapusPermanenProduk uuid={UUID} nama={NAMA} saatSelesai={vi.fn()} />);
        expect(await screen.findByText('Produk ini belum punya riwayat.')).toBeTruthy();

        cleanup();
        TiruFetch(500);
        RenderUji(<DialogHapusPermanenProduk uuid={UUID} nama={NAMA} saatSelesai={vi.fn()} />);
        expect(await screen.findByText('Riwayat produk tidak bisa dimuat. Tutup dialog lalu coba lagi.')).toBeTruthy();
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Hapus permanen' }).disabled).toBe(true);
    });
});
