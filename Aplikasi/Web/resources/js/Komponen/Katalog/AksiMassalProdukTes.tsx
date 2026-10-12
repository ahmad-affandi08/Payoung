import { fireEvent, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AksiMassalProduk from '@/Komponen/Katalog/AksiMassalProduk';
import { RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { PilihOpsi } from '@/Pengujian/InteraksiPilihan';
import type { BarisProduk } from '@/Tipe/Katalog';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

function Baris(uuid: string, nama: string): BarisProduk {
    return {
        Uuid: uuid,
        Nama: nama,
        Sku: null,
        Jenis: 'Stok',
        LabelJenis: 'Barang stok',
        NamaKategori: null,
        Merek: null,
        HargaDasar: '35000.00',
        SimbolSatuan: 'pcs',
        JumlahVarian: 0,
        TampilDiPos: true,
        Favorit: false,
        DiubahPada: null,
        Status: 'Aktif',
        UrlGambarKecil: null,
    };
}

const Konteks = {
    terpilih: [
        Baris('01J9PRD0000000000000000001', 'Kopi Susu Gula Aren Literan'),
        Baris('01J9PRD0000000000000000002', 'Teh Melati Botol 1 Liter'),
    ],
    semuaHasil: false,
    total: 2,
    keadaan: { cari: '', urut: [], halaman: 1, perHalaman: 25, saring: {} },
    bersihkan: vi.fn(),
};

describe('Aksi massal produk (audit kemudahan pakai #19)', () => {
    beforeEach(() => {
        tiruanRouter.post.mockClear();
        tiruanRouter.get.mockClear();
    });

    it('arsipkan & sembunyikan dari kasir mengirim Uuid terpilih; pindah kategori butuh kategori', () => {
        RenderUji(
            <AksiMassalProduk
                konteks={Konteks}
                kategori={[{ Uuid: '01J9KTG0000000000000000001', Jalur: 'Minuman › Literan' }]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Arsipkan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/produk/massal',
            {
                Aksi: 'Arsipkan',
                Uuid: ['01J9PRD0000000000000000001', '01J9PRD0000000000000000002'],
                UuidKategori: null,
            },
            expect.anything(),
        );

        expect((screen.getByRole('button', { name: 'Pindahkan' }) as HTMLButtonElement).disabled).toBe(true);
        PilihOpsi(screen.getByRole('combobox', { name: 'Kategori tujuan' }), '01J9KTG0000000000000000001');
        fireEvent.click(screen.getByRole('button', { name: 'Pindahkan' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            '/kelola/produk/massal',
            expect.objectContaining({ Aksi: 'Kategori', UuidKategori: '01J9KTG0000000000000000001' }),
            expect.anything(),
        );
    });

    it('PRD-10: Cetak label membuka halaman label dengan produk terpilih; tanpa izin kelola hanya tombol itu yang tampil', () => {
        const { container, unmount: Bongkar } = RenderUji(<AksiMassalProduk konteks={Konteks} kategori={[]} />);

        fireEvent.click(within(container).getByRole('button', { name: 'Cetak label' }));
        expect(tiruanRouter.get).toHaveBeenLastCalledWith('/kelola/produk/label', {
            Produk: ['01J9PRD0000000000000000001', '01J9PRD0000000000000000002'],
        });
        Bongkar();

        const { container: terbatas } = RenderUji(
            <AksiMassalProduk konteks={Konteks} kategori={[]} bolehKelola={false} />,
        );
        const dalam = within(terbatas);
        expect(dalam.getByRole('button', { name: 'Cetak label' })).toBeTruthy();
        expect(dalam.queryByRole('button', { name: 'Arsipkan' })).toBeNull();
        expect(dalam.queryByRole('button', { name: 'Tandai favorit' })).toBeNull();
        expect(dalam.queryByRole('combobox', { name: 'Kategori tujuan' })).toBeNull();
    });

    it('K-16: Tandai favorit & Lepas favorit mengirim aksi Favorit/HapusFavorit dengan Uuid terpilih', () => {
        const { container } = RenderUji(<AksiMassalProduk konteks={Konteks} kategori={[]} />);
        const dalam = within(container);
        const uuid = ['01J9PRD0000000000000000001', '01J9PRD0000000000000000002'];

        fireEvent.click(dalam.getByRole('button', { name: 'Tandai favorit' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            '/kelola/produk/massal',
            { Aksi: 'Favorit', Uuid: uuid, UuidKategori: null },
            expect.anything(),
        );

        fireEvent.click(dalam.getByRole('button', { name: 'Lepas favorit' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            '/kelola/produk/massal',
            { Aksi: 'HapusFavorit', Uuid: uuid, UuidKategori: null },
            expect.anything(),
        );
    });

    it('Ubah harga hanya tampil bila berizin, dan mengirim mode, nilai, dan pembulatan', () => {
        const { unmount: Bongkar } = RenderUji(<AksiMassalProduk konteks={Konteks} kategori={[]} />);
        expect(screen.queryByRole('button', { name: 'Ubah harga…' })).toBeNull();
        Bongkar();

        RenderUji(<AksiMassalProduk konteks={Konteks} kategori={[]} bolehUbahHarga />);
        fireEvent.click(screen.getByRole('button', { name: 'Ubah harga…' }));

        const dialog = screen.getByRole('dialog');
        expect(dialog.textContent).toContain('Ubah harga 2 produk');
        expect((screen.getByRole('button', { name: 'Ubah harga' }) as HTMLButtonElement).disabled).toBe(true);

        fireEvent.change(screen.getByLabelText(/Besar perubahan/), { target: { value: '10' } });
        PilihOpsi(screen.getByRole('combobox', { name: /Pembulatan harga baru/ }), '500');
        fireEvent.click(screen.getByRole('button', { name: 'Ubah harga' }));

        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            '/kelola/produk/harga-massal',
            {
                Mode: 'NaikPersen',
                Nilai: '10',
                Pembulatan: 500,
                Uuid: ['01J9PRD0000000000000000001', '01J9PRD0000000000000000002'],
            },
            expect.anything(),
        );
    });
});
