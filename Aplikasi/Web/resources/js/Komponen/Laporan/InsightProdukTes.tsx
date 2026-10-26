import { cleanup, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanLaporanPenjualan from '@/Halaman/Kelola/Laporan/Penjualan';
import HalamanLaporanStok from '@/Halaman/Kelola/Laporan/Stok';
import { AmbilHrefEkspor } from '@/Pengujian/InteraksiRadix';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { PilihOpsi } from '@/Pengujian/InteraksiPilihan';
import type { AngkaPenjualan, PropsLaporanPenjualan, PropsLaporanStok } from '@/Tipe/Laporan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const total: AngkaPenjualan = {
    Kotor: '300000.00',
    Diskon: '0.00',
    Retur: '0.00',
    Bersih: '300000.00',
    Pajak: '0.00',
    BiayaLayanan: '0.00',
    Hpp: '120000.00',
    LabaKotor: '180000.00',
    JumlahTransaksi: 10,
    JumlahRetur: 0,
    RataRataKeranjang: '30000.00',
    JumlahBarang: null,
};

function PropsPenjualan(tab: 'abc' | 'menu', isi: PropsLaporanPenjualan['Isi']): PropsLaporanPenjualan {
    return {
        Saring: { Tab: tab, Dari: '2026-10-01', Sampai: '2026-10-07', Outlet: '', Kasir: '', Kanal: '' },
        Peringatan: null,
        MaksHari: 92,
        OpsiOutlet: [],
        OpsiKasir: [],
        OpsiKanal: [],
        Total: total,
        Isi: isi,
    };
}

afterEach(cleanup);

describe('X6 insight produk di laporan penjualan', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/laporan/penjualan'));

    it('tab analisis ABC: ringkasan per kelas, kelas berteks, porsi persen Indonesia, ekspor sesuai tab', () => {
        RenderUji(
            <HalamanLaporanPenjualan
                {...PropsPenjualan('abc', {
                    Baris: [
                        {
                            IdProduk: 1,
                            NamaProduk: 'Kopi Susu Gula Aren',
                            Qty: '20.0000',
                            Bersih: '240000.00',
                            Porsi: '80.00',
                            PorsiKumulatif: '80.00',
                            Kelas: 'A',
                        },
                        {
                            IdProduk: 2,
                            NamaProduk: 'Roti Bakar Cokelat Keju',
                            Qty: '4.0000',
                            Bersih: '60000.00',
                            Porsi: '20.00',
                            PorsiKumulatif: '100.00',
                            Kelas: 'B',
                        },
                    ],
                    Ringkasan: {
                        A: { Jumlah: 1, Bersih: '240000.00' },
                        B: { Jumlah: 1, Bersih: '60000.00' },
                        C: { Jumlah: 0, Bersih: '0.00' },
                    },
                })}
            />,
        );

        const nav = screen.getByRole('navigation', { name: 'Jenis laporan penjualan' });
        expect(within(nav).getByRole('link', { name: 'Analisis ABC' }).getAttribute('aria-current')).toBe('page');
        expect(screen.getByText('Kelas A | 1 produk')).toBeTruthy();
        expect(screen.getByText('Kelas C | 0 produk')).toBeTruthy();
        expect(screen.getByRole('table', { name: 'Analisis ABC produk' })).toBeTruthy();
        expect(screen.getAllByText('Kelas B').length).toBeGreaterThan(0);
        expect(screen.getAllByText('80,00%').length).toBeGreaterThan(0);
        expect(AmbilHrefEkspor('csv', 'Ekspor')).toBe(
            '/kelola/laporan/penjualan/ekspor?dari=2026-10-01&sampai=2026-10-07&tab=abc&format=csv',
        );
    });

    it('tab menu engineering: label kelas Indonesia, saran per kelas, ambang dari server', () => {
        RenderUji(
            <HalamanLaporanPenjualan
                {...PropsPenjualan('menu', {
                    Baris: [
                        {
                            IdProduk: 1,
                            NamaProduk: 'Kopi Susu Gula Aren',
                            Qty: '20.0000',
                            Bersih: '240000.00',
                            Hpp: '80000.00',
                            MarginPerUnit: '8000.00',
                            HargaRata: '12000.00',
                            SaranHarga: null,
                            PorsiQty: '83.33',
                            Populer: true,
                            MarginTinggi: true,
                            Kelas: 'Star',
                        },
                        {
                            IdProduk: 2,
                            NamaProduk: 'Roti Bakar Cokelat Keju',
                            Qty: '4.0000',
                            Bersih: '60000.00',
                            Hpp: '40000.00',
                            MarginPerUnit: '5000.00',
                            HargaRata: '15000.00',
                            SaranHarga: null,
                            PorsiQty: '16.67',
                            Populer: false,
                            MarginTinggi: false,
                            Kelas: 'Dog',
                        },
                        {
                            IdProduk: 3,
                            NamaProduk: 'Es Teh Manis',
                            Qty: '10.0000',
                            Bersih: '80000.00',
                            Hpp: '60000.00',
                            MarginPerUnit: '2000.00',
                            HargaRata: '8000.00',
                            SaranHarga: '9000.00',
                            PorsiQty: '41.67',
                            Populer: true,
                            MarginTinggi: false,
                            Kelas: 'Plowhorse',
                        },
                    ],
                    BatasPorsiQty: '35.00',
                    RataRataMargin: '7500.00',
                })}
            />,
        );

        expect(screen.getByRole('table', { name: 'Menu engineering produk' })).toBeTruthy();
        expect(screen.getAllByText('Bintang').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Kurang laku, untung tipis').length).toBeGreaterThan(0);
        expect(screen.getByText(/minimal 35,00%/)).toBeTruthy();
        expect(screen.getByText(/Rp 7\.500/)).toBeTruthy();
        expect(screen.getByText(/Kandidat dihapus atau dirombak/)).toBeTruthy();
        expect(screen.getByText('Rp 9.000 (dari Rp 8.000)')).toBeTruthy();
    });
});

describe('X6 saran restock di laporan stok', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/laporan/stok'));

    const props: PropsLaporanStok = {
        Saring: { Tab: 'restock', Tanggal: '2026-10-07', Gudang: '', Hari: 14 },
        OpsiGudang: [{ Nilai: 'g1', Label: 'Toko (Toko Kelontong Berkah Solo)' }],
        Nilai: null,
        Kritis: null,
        Kedaluwarsa: null,
        Perputaran: null,
        Rencana: null,
        Restock: {
            HariDasar: 28,
            HariCakupan: 14,
            Musim: { Jenis: 'Lebaran', SelisihHari: 355, Lebaran: '2027-03-10' },
            Baris: [
                {
                    Kunci: 'p1-g1',
                    UuidProduk: 'p1',
                    NamaProduk: 'Gula Pasir Lokal 1 kg',
                    Sku: 'GLP-1KG',
                    SimbolSatuan: 'pcs',
                    UuidGudang: 'g1',
                    NamaGudang: 'Toko',
                    NamaOutlet: 'Toko Kelontong Berkah Solo',
                    Pakai: '56.0000',
                    RataPerHari: '2.0000',
                    FaktorMusim: '1.50',
                    RataPerkiraan: '3.0000',
                    Saldo: '6.0000',
                    HariHabis: 3,
                    SaranBeli: '22.0000',
                },
                {
                    Kunci: 'p2-g1',
                    UuidProduk: 'p2',
                    NamaProduk: 'Minyak Goreng 2 Liter',
                    Sku: null,
                    SimbolSatuan: 'pcs',
                    UuidGudang: 'g1',
                    NamaGudang: 'Toko',
                    NamaOutlet: 'Toko Kelontong Berkah Solo',
                    Pakai: '28.0000',
                    RataPerHari: '1.0000',
                    FaktorMusim: '1.00',
                    RataPerkiraan: '1.0000',
                    Saldo: '0.0000',
                    HariHabis: 0,
                    SaranBeli: '14.0000',
                },
            ],
        },
    };

    it('menampilkan perkiraan habis & saran beli, pilihan cakupan hari memuat ulang dengan filter', () => {
        RenderUji(<HalamanLaporanStok {...props} />);

        expect(screen.getByRole('heading', { name: 'Saran restock (2)' })).toBeTruthy();
        expect(screen.getAllByText('3 hari lagi').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Sudah habis').length).toBeGreaterThan(0);
        expect(screen.getAllByText('22 pcs').length).toBeGreaterThan(0);
        expect(screen.getByText(/28 hari terakhir/)).toBeTruthy();
        expect(screen.getByText(/dekat Ramadan & Lebaran \(10 /)).toBeTruthy();
        expect(screen.getAllByText('×1,50').length).toBeGreaterThan(0);
        expect(AmbilHrefEkspor('csv', 'Ekspor')).toBe(
            '/kelola/laporan/stok/ekspor?tanggal=2026-10-07&hari=14&tab=restock&format=csv',
        );

        PilihOpsi(screen.getByRole('combobox', { name: /Stok cukup untuk/ }), '30');
        expect(tiruanRouter.get).toHaveBeenCalledWith(
            '/kelola/laporan/stok',
            { tanggal: '2026-10-07', hari: '30', tab: 'restock' },
            { preserveScroll: true, preserveState: true },
        );
    });

    it('keadaan kosong saat belum ada pemakaian', () => {
        RenderUji(
            <HalamanLaporanStok
                {...props}
                Restock={{
                    HariDasar: 28,
                    HariCakupan: 14,
                    Musim: { Jenis: 'TahunLalu', SelisihHari: 364, Lebaran: null },
                    Baris: [],
                }}
            />,
        );
        expect(screen.getByText('Belum ada pemakaian stok dalam 28 hari terakhir.')).toBeTruthy();
    });

    it('slow & fast moving: ringkasan kelas, nilai tertahan, kolom kelas. Pilihan rentang memuat ulang dengan filter', () => {
        RenderUji(
            <HalamanLaporanStok
                {...props}
                Saring={{ Tab: 'perputaran', Tanggal: '2026-10-07', Gudang: '', Hari: 30 }}
                Restock={null}
                Perputaran={{
                    Rentang: 30,
                    Ringkasan: {
                        Cepat: { Jumlah: 1, Nilai: '60000.00' },
                        Normal: { Jumlah: 0, Nilai: '0.00' },
                        Lambat: { Jumlah: 1, Nilai: '250000.00' },
                        Mati: { Jumlah: 1, Nilai: '90000.00' },
                    },
                    NilaiTertahan: '340000.00',
                    Baris: [
                        {
                            Kunci: 'p1-g1',
                            UuidProduk: 'p1',
                            NamaProduk: 'Gula Pasir Lokal 1 kg',
                            Sku: 'GLP-1KG',
                            SimbolSatuan: 'pcs',
                            UuidGudang: 'g1',
                            NamaGudang: 'Toko',
                            NamaOutlet: 'Toko Kelontong Berkah Solo',
                            Pakai: '90.0000',
                            RataPerHari: '3.0000',
                            Saldo: '10.0000',
                            Nilai: '60000.00',
                            Perputaran: '9.0000',
                            HariCover: 3,
                            Kelas: 'Cepat',
                        },
                        {
                            Kunci: 'p3-g1',
                            UuidProduk: 'p3',
                            NamaProduk: 'Sabun Cuci Piring 5 Liter',
                            Sku: null,
                            SimbolSatuan: 'pcs',
                            UuidGudang: 'g1',
                            NamaGudang: 'Toko',
                            NamaOutlet: 'Toko Kelontong Berkah Solo',
                            Pakai: '0.0000',
                            RataPerHari: '0.0000',
                            Saldo: '12.0000',
                            Nilai: '90000.00',
                            Perputaran: '0.0000',
                            HariCover: null,
                            Kelas: 'Mati',
                        },
                    ],
                }}
            />,
        );

        expect(screen.getByRole('heading', { name: 'Slow & fast moving (2)' })).toBeTruthy();
        expect(screen.getAllByText('Cepat laku').length).toBeGreaterThan(1);
        expect(screen.getAllByText('Tidak bergerak').length).toBeGreaterThan(0);
        expect(screen.getByText('Rp 340.000')).toBeTruthy();
        expect(screen.getByText('3 hari')).toBeTruthy();
        expect(AmbilHrefEkspor('csv', 'Ekspor')).toBe(
            '/kelola/laporan/stok/ekspor?tanggal=2026-10-07&hari=30&tab=perputaran&format=csv',
        );

        PilihOpsi(screen.getByRole('combobox', { name: /Rentang pemakaian/ }), '90');
        expect(tiruanRouter.get).toHaveBeenCalledWith(
            '/kelola/laporan/stok',
            { tanggal: '2026-10-07', hari: '90', tab: 'perputaran' },
            { preserveScroll: true, preserveState: true },
        );
    });
});
