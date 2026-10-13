import { cleanup, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanLaporanStok, { BuatUrlDrafProduksi } from '@/Halaman/Kelola/Laporan/Stok';
import HalamanFormOrderProduksi from '@/Halaman/Kelola/Persediaan/Produksi/Form';
import { AturHalamanUji, RenderUji, TambahPropsHalamanUji } from '@/Komponen/Katalog/TiruanInertia';
import { GudangUtama } from '@/Komponen/Persediaan/DataUjiPersediaan';
import type { BarisRencanaProduksi, PropsLaporanStok } from '@/Tipe/Laporan';
import type { AwalOrderProduksi } from '@/Tipe/Produksi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * §9.11 Bakery: tab Rencana produksi di laporan stok dan formulir order produksi yang terisi dari baris rencana.
 */

const roti: BarisRencanaProduksi = {
    Kunci: 'p1-g1',
    UuidProduk: '01J9PRODUK000000000000001',
    NamaProduk: 'Roti Tawar Gandum Utuh',
    Sku: 'RTI-01',
    SimbolSatuan: 'pcs',
    UuidGudang: '01J9GUDANG000000000000001',
    NamaGudang: 'Dapur',
    NamaOutlet: 'Bakery Rencana Klaten',
    Sampel: 4,
    RataHariSama: '6.0000',
    PreOrder: '9.0000',
    Kebutuhan: '9.0000',
    Saldo: '1.0000',
    Rekomendasi: '8.0000',
};

const cukup: BarisRencanaProduksi = {
    ...roti,
    Kunci: 'p2-g1',
    UuidProduk: '01J9PRODUK000000000000002',
    NamaProduk: 'Roti Sobek Cokelat',
    RataHariSama: '3.0000',
    PreOrder: '0.0000',
    Kebutuhan: '3.0000',
    Saldo: '12.0000',
    Rekomendasi: '0.0000',
};

const props: PropsLaporanStok = {
    Saring: { Tab: 'rencana', Tanggal: '2026-10-16', Gudang: '', Hari: 14 },
    OpsiGudang: [{ Nilai: 'g1', Label: 'Dapur (Bakery Rencana Klaten)' }],
    Nilai: null,
    Kritis: null,
    Kedaluwarsa: null,
    Restock: null,
    Perputaran: null,
    Rencana: { Target: '2026-10-16', Minggu: 4, Baris: [roti, cukup] },
};

beforeEach(() => AturHalamanUji({}, '/kelola/laporan/stok'));
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('tab Rencana produksi di laporan stok', () => {
    it('menampilkan jumlah produksi, kebutuhan, saldo, dan "Cukup" bila saldo sudah memenuhi', () => {
        RenderUji(<HalamanLaporanStok {...props} />);

        expect(screen.getByRole('heading', { name: /Rencana produksi .* \(2\)/ })).toBeTruthy();
        expect(screen.getAllByText('Roti Tawar Gandum Utuh').length).toBeGreaterThan(0);
        expect(screen.getAllByText('8 pcs').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Cukup').length).toBeGreaterThan(0);
        expect(screen.getByText(/pre-order biasanya sudah ikut di riwayat penjualan/)).toBeTruthy();
    });

    it('tombol Buat draf produksi hanya ada untuk yang perlu produksi dan membawa produk, lokasi, jumlah, tanggal', () => {
        RenderUji(<HalamanLaporanStok {...props} />);

        const tautan = screen.getAllByRole('link', { name: 'Buat draf produksi' });
        expect(tautan).toHaveLength(1);
        expect(tautan[0]?.getAttribute('href')).toBe(BuatUrlDrafProduksi(roti, '2026-10-16'));
        expect(BuatUrlDrafProduksi(roti, '2026-10-16')).toBe(
            '/kelola/persediaan/produksi/buat?produk=01J9PRODUK000000000000001&gudang=01J9GUDANG000000000000001&jumlah=8&tanggal=2026-10-16',
        );
    });

    it('jumlah pecahan dikirim tanpa nol di belakang; tanpa izin kelola persediaan tombol tidak tampil', () => {
        expect(BuatUrlDrafProduksi({ ...roti, Rekomendasi: '4.2500' }, '2026-10-16')).toContain('jumlah=4.25');
        expect(BuatUrlDrafProduksi({ ...roti, Rekomendasi: '10.0000' }, '2026-10-16')).toContain('jumlah=10&');

        TambahPropsHalamanUji({ Akses: { Pemilik: false, Izin: ['persediaan.lihat'] } });
        RenderUji(<HalamanLaporanStok {...props} />);
        expect(screen.queryByRole('link', { name: 'Buat draf produksi' })).toBeNull();
        expect(screen.getAllByText('8 pcs').length).toBeGreaterThan(0);
    });
});

describe('formulir order produksi terisi dari rencana', () => {
    const awal: AwalOrderProduksi = {
        UuidGudang: GudangUtama.Uuid,
        Tanggal: '2026-10-16',
        JumlahHasil: '8',
        Produk: {
            Uuid: roti.UuidProduk,
            Nama: roti.NamaProduk,
            Sku: roti.Sku,
            SimbolSatuan: 'pcs',
            BolehDesimal: false,
            Pelacakan: 'Tidak',
        },
    };

    it('produk, jumlah, dan tanggal terisi dan bahan dimuat dari resep otomatis sekali', async () => {
        const AmbilUji = vi.fn().mockResolvedValue({
            ok: true,
            json: () =>
                Promise.resolve({
                    VersiResep: 2,
                    Bahan: [
                        {
                            UuidProduk: '01J9BAHAN0000000000000001',
                            NamaProduk: 'Tepung Terigu Protein Tinggi',
                            Sku: null,
                            SimbolSatuan: 'kg',
                            BolehDesimal: true,
                            JumlahStandar: '4',
                            Jumlah: '4',
                        },
                    ],
                }),
        });
        vi.stubGlobal('fetch', AmbilUji);

        RenderUji(
            <HalamanFormOrderProduksi
                Mode="Buat"
                Order={null}
                Awal={awal}
                OpsiGudang={[GudangUtama]}
                HariIni="2026-10-14"
                MaksBahan={100}
                WajibKedaluwarsaBatch
            />,
        );

        expect(screen.getAllByText('Roti Tawar Gandum Utuh').length).toBeGreaterThan(0);
        await waitFor(() => expect(screen.getAllByText('Tepung Terigu Protein Tinggi').length).toBeGreaterThan(0));
        expect(AmbilUji).toHaveBeenCalledTimes(1);
        expect(String(AmbilUji.mock.calls[0]?.[0])).toBe(
            `/kelola/persediaan/produksi/resep?produk=${roti.UuidProduk}&jumlah=8`,
        );
    });

    it('tanpa isian awal formulir kosong dan resep tidak dimuat sendiri', () => {
        const AmbilUji = vi.fn();
        vi.stubGlobal('fetch', AmbilUji);

        RenderUji(
            <HalamanFormOrderProduksi
                Mode="Buat"
                Order={null}
                Awal={null}
                OpsiGudang={[GudangUtama]}
                HariIni="2026-10-14"
                MaksBahan={100}
                WajibKedaluwarsaBatch
            />,
        );

        expect(AmbilUji).not.toHaveBeenCalled();
        expect(screen.queryByText('Roti Tawar Gandum Utuh')).toBeNull();
    });
});
