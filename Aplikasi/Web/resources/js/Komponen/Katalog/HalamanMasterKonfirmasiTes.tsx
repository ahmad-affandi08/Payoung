import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarDaftarHarga from '@/Halaman/Kelola/DaftarHarga/Daftar';
import HalamanDaftarKategori from '@/Halaman/Kelola/Kategori/Daftar';
import HalamanDaftarKelompokPilihan from '@/Halaman/Kelola/KelompokPilihan/Daftar';
import HalamanDaftarSatuan from '@/Halaman/Kelola/Satuan/Daftar';
import HalamanDaftarStasiunDapur from '@/Halaman/Kelola/StasiunDapur/Daftar';

import { BuatHasilTabel, IzinPenuh } from './DataUjiKatalog';
import { AturHalamanUji, RenderUji, tiruanRouter } from './TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('./TiruanInertia')).TiruanInertia);

/** Buka menu aksi baris TabelData (Radix, keyboard) lalu pilih satu item. */
function PilihAksi(nama: string, item: string): void {
    fireEvent.keyDown(screen.getByRole('button', { name: `Aksi ${nama}` }), { key: 'Enter' });
    fireEvent.click(screen.getByRole('menuitem', { name: item }));
}

describe('Master katalog: formulir di dialog, hapus/nonaktifkan lewat konfirmasi (F-03)', () => {
    beforeEach(() => AturHalamanUji());
    afterEach(() => cleanup());

    it('kategori: formulir tambah tampil di dialog dan Batal menutupnya', () => {
        RenderUji(<HalamanDaftarKategori Kategori={[]} OpsiStasiunDapur={[]} Izin={IzinPenuh} />);

        expect(screen.queryByRole('dialog')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Tambah kategori' }));
        expect(screen.getByRole('dialog', { name: 'Tambah kategori' })).toBeTruthy();
        expect(screen.getByRole('form', { name: 'Tambah kategori' })).toBeTruthy();
        // Tanpa stasiun dapur (misal toko retail): bidang stasiun tidak tampil.
        expect(screen.queryByRole('combobox', { name: 'Stasiun dapur' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Batal' }));
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('F-10a kategori: stasiun dapur tampil di tabel & form ubah, termasuk stasiun yang diarsipkan', () => {
        RenderUji(
            <HalamanDaftarKategori
                Kategori={[
                    {
                        Uuid: 'K1',
                        Nama: 'Kopi',
                        Jalur: 'Kopi',
                        Kedalaman: 1,
                        UuidInduk: null,
                        JumlahProduk: 3,
                        Urutan: 0,
                        UuidStasiunDapur: 'S-LAMA',
                        NamaStasiunDapur: 'Bar Lantai 2',
                    },
                ]}
                OpsiStasiunDapur={[{ Nilai: 'S-DAPUR', Label: 'Dapur' }]}
                Izin={IzinPenuh}
            />,
        );

        expect(screen.getByText('Bar Lantai 2')).toBeTruthy();
        PilihAksi('Kopi', 'Ubah kategori');
        expect(screen.getByRole('form', { name: 'Ubah kategori Kopi' })).toBeTruthy();
        expect(screen.getByRole('combobox', { name: 'Stasiun dapur' }).textContent).toContain(
            'Bar Lantai 2 (diarsipkan)',
        );
    });

    it('kategori: router.delete baru dipanggil setelah konfirmasi', () => {
        RenderUji(
            <HalamanDaftarKategori
                Kategori={[
                    {
                        Uuid: 'K9',
                        Nama: 'Camilan',
                        Jalur: 'Camilan',
                        Kedalaman: 1,
                        UuidInduk: null,
                        JumlahProduk: 0,
                        Urutan: 0,
                        UuidStasiunDapur: null,
                        NamaStasiunDapur: null,
                    },
                ]}
                OpsiStasiunDapur={[]}
                Izin={IzinPenuh}
            />,
        );

        PilihAksi('Camilan', 'Hapus kategori');
        expect(screen.getByRole('alertdialog', { name: 'Hapus kategori Camilan?' })).toBeTruthy();
        expect(tiruanRouter.delete).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Hapus kategori' }));
        expect(tiruanRouter.delete).toHaveBeenCalledWith(
            '/kelola/kategori/K9',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('F-10a stasiun dapur: stasiun bawaan disebut, arsipkan lewat aksi baris, tambah membuka formulir', () => {
        RenderUji(
            <HalamanDaftarStasiunDapur
                Stasiun={[
                    { Uuid: 'S1', Nama: 'Bar', Urutan: 0, Status: 'Aktif', Salinan: false },
                    { Uuid: 'S2', Nama: 'Dapur', Urutan: 1, Status: 'Aktif', Salinan: false },
                ]}
                Izin={IzinPenuh}
            />,
        );

        expect(screen.getByText(/Kategori tanpa stasiun masuk ke Bar/)).toBeTruthy();
        PilihAksi('stasiun Dapur', 'Arsipkan');
        expect(tiruanRouter.post).toHaveBeenCalledWith('/kelola/stasiun-dapur/S2/arsipkan', {}, expect.anything());
        fireEvent.click(screen.getByRole('button', { name: 'Tambah stasiun' }));
        expect(screen.getByRole('heading', { name: 'Tambah stasiun dapur' })).toBeTruthy();
    });

    it('satuan: batal di konfirmasi tidak menghapus', () => {
        RenderUji(
            <HalamanDaftarSatuan
                Satuan={[
                    {
                        Uuid: 'S2',
                        Nama: 'Porsi',
                        Simbol: 'porsi',
                        BolehDesimal: false,
                        KodeStandar: null,
                        JumlahProduk: 0,
                    },
                ]}
                Izin={IzinPenuh}
            />,
        );

        PilihAksi('Porsi', 'Hapus satuan');
        fireEvent.click(screen.getByRole('button', { name: 'Batal' }));
        expect(screen.queryByRole('alertdialog')).toBeNull();
        expect(tiruanRouter.delete).not.toHaveBeenCalled();
    });

    it('kelompok pilihan: konfirmasi menyebut jumlah produk, lalu menghapus', () => {
        RenderUji(
            <HalamanDaftarKelompokPilihan
                KelompokPilihan={[
                    {
                        Uuid: 'KP-GULA',
                        Nama: 'Level gula',
                        MinimalPilih: '1',
                        MaksimalPilih: '1',
                        Urutan: '0',
                        Wajib: true,
                        JumlahProduk: 6,
                        Pilihan: [
                            {
                                Uuid: 'PL-N',
                                Nama: 'Normal',
                                Harga: '0.00',
                                Aktif: true,
                                UuidProdukBahan: null,
                                Jumlah: '',
                                NamaProdukBahan: null,
                                SimbolSatuanBahan: null,
                            },
                        ],
                    },
                ]}
                Izin={IzinPenuh}
            />,
        );

        PilihAksi('Level gula', 'Hapus kelompok');
        expect(screen.getByText('Kelompok ini dilepas dari 6 produk. Transaksi lama tidak berubah.')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Ya, hapus kelompok' }));
        expect(tiruanRouter.delete).toHaveBeenCalledWith(
            '/kelola/kelompok-pilihan/KP-GULA',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('daftar harga: nonaktifkan lewat konfirmasi, aktifkan langsung', () => {
        window.history.replaceState({}, '', '/kelola/daftar-harga');
        RenderUji(
            <HalamanDaftarDaftarHarga
                DaftarHarga={BuatHasilTabel([
                    {
                        Uuid: 'DH-1',
                        Nama: 'Harga GoFood',
                        Aktif: true,
                        NamaOutlet: null,
                        Kanal: 'Online',
                        LabelKanal: 'Online',
                        TierPelanggan: null,
                        MulaiPada: null,
                        SelesaiPada: null,
                        Prioritas: 10,
                        JumlahProduk: 3,
                    },
                    {
                        Uuid: 'DH-2',
                        Nama: 'Harga grosir',
                        Aktif: false,
                        NamaOutlet: null,
                        Kanal: null,
                        LabelKanal: null,
                        TierPelanggan: 'GROSIR',
                        MulaiPada: null,
                        SelesaiPada: null,
                        Prioritas: 0,
                        JumlahProduk: 0,
                    },
                ])}
                Outlet={[]}
                Kanal={[]}
                ZonaWaktu="WIB"
                Izin={IzinPenuh}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aktifkan Harga grosir' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/daftar-harga/DH-2/aktifkan',
            {},
            { preserveScroll: true },
        );

        fireEvent.click(screen.getByRole('button', { name: 'Nonaktifkan Harga GoFood' }));
        expect(tiruanRouter.post).toHaveBeenCalledTimes(1);
        fireEvent.click(screen.getByRole('button', { name: 'Nonaktifkan daftar' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            '/kelola/daftar-harga/DH-1/nonaktifkan',
            {},
            { preserveScroll: true },
        );
    });
});
