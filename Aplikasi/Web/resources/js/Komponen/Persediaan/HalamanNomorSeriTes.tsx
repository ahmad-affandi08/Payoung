import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanNomorSeri, { BuatQueryNomorSeri } from '@/Halaman/Kelola/Persediaan/NomorSeri';
import { AmbilHrefEkspor } from '@/Pengujian/InteraksiRadix';
import { SiapkanKameraTiruan } from '@/Pengujian/KameraTiruan';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import type { PropsNomorSeri, UnitNomorSeri } from '@/Tipe/Persediaan';

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

const unitTerjual: UnitNomorSeri = {
    Uuid: '01K5SERI00000000000000TERJUAL',
    Nomor: 'IMEI-356938035643809',
    UuidProduk: '01K5PRD0000000000000PONSEL01',
    NamaProduk: 'Ponsel Android 8/256 GB Hitam',
    Sku: 'HP-8256',
    Status: 'Terjual',
    LabelStatus: 'Terjual',
    UuidGudang: null,
    NamaGudang: null,
    UuidPenjualan: '01K5JUAL0000000000000000001',
    NomorPenjualan: 'INV/SLB/261007/POS-001-0012',
    TanggalJual: '2026-10-07',
    UuidPelanggan: '01K5PLG0000000000000000001',
    NamaPelanggan: 'Budi Santoso',
    MasaGaransiBulan: 12,
    GaransiSampai: '2027-10-07',
    StatusGaransi: 'Aktif',
};

const unitTersedia: UnitNomorSeri = {
    ...unitTerjual,
    Uuid: '01K5SERI0000000000000TERSEDIA',
    Nomor: 'IMEI-356938035643810',
    Status: 'Tersedia',
    LabelStatus: 'Tersedia',
    UuidGudang: '01K5GDG0000000000000000001',
    NamaGudang: 'Toko',
    UuidPenjualan: null,
    NomorPenjualan: null,
    TanggalJual: null,
    UuidPelanggan: null,
    NamaPelanggan: null,
    MasaGaransiBulan: null,
    GaransiSampai: null,
    StatusGaransi: null,
};

const riwayat = [
    { Tanggal: '2026-09-24', Jenis: 'Stok awal', Arah: 'Masuk', NomorDokumen: 'SA-0001', NamaGudang: 'Toko' },
    {
        Tanggal: '2026-10-07',
        Jenis: 'Penjualan',
        Arah: 'Keluar',
        NomorDokumen: 'INV/SLB/261007/POS-001-0012',
        NamaGudang: 'Toko',
    },
] as const;

function Props(perubahan: Partial<PropsNomorSeri> = {}): PropsNomorSeri {
    return {
        Saring: { Cari: '', Status: '', Produk: '', Unit: '' },
        Produk: null,
        Hasil: [],
        TotalHasil: 0,
        Detail: null,
        BatasHasil: 50,
        OpsiStatus: [
            { Nilai: 'Tersedia', Label: 'Tersedia' },
            { Nilai: 'Terjual', Label: 'Terjual' },
        ],
        Izin: { Pelanggan: true, Penjualan: true, Produk: true },
        ...perubahan,
    };
}

describe('Kelola/Persediaan/NomorSeri (F-05h)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/persediaan/kartu-stok/nomor-seri');
        tiruanRouter.get.mockClear();
    });
    afterEach(() => cleanup());

    it('rumahnya tab Kartu stok: dua tab, tab nomor seri aktif', () => {
        RenderUji(<HalamanNomorSeri {...Props()} />);

        const nav = screen.getByRole('navigation', { name: 'Cara menelusuri riwayat stok' });

        expect(nav.querySelector('[aria-current="page"]')?.textContent).toBe('Per nomor seri / IMEI');
        expect(nav.querySelector('a[href="/kelola/persediaan/kartu-stok"]')?.textContent).toBe('Per produk');
    });

    it('awal: ajakan mencari; kirim pencarian ke server dengan query cari', () => {
        RenderUji(<HalamanNomorSeri {...Props()} />);
        expect(screen.getByText(/Masukkan nomor seri atau IMEI/)).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Nomor seri / IMEI atau nama produk'), {
            target: { value: ' 3569380 ' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cari' }));
        expect(tiruanRouter.get).toHaveBeenCalledWith(
            '/kelola/persediaan/kartu-stok/nomor-seri',
            { cari: '3569380' },
            expect.anything(),
        );
    });

    it('pindai kamera: nomor seri/IMEI yang terbaca langsung dicari (kotak terisi dan pencarian dikirim)', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);

        RenderUji(<HalamanNomorSeri {...Props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Pindai nomor seri atau IMEI dengan kamera' }));
        await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());
        tiruan.terbaca('356938035643809');

        await waitFor(() =>
            expect(tiruanRouter.get).toHaveBeenCalledWith(
                '/kelola/persediaan/kartu-stok/nomor-seri',
                { cari: '356938035643809' },
                expect.anything(),
            ),
        );
        expect((screen.getByLabelText('Nomor seri / IMEI atau nama produk') as HTMLInputElement).value).toBe(
            '356938035643809',
        );
        expect(tiruan.opsiTerakhir()).toMatchObject({ jenisKode: 'Seri' });
    });

    it('hasil: status, keterangan jual & garansi; total melebihi batas diberi catatan dan tautan ekspor', () => {
        RenderUji(
            <HalamanNomorSeri
                {...Props({
                    Saring: { Cari: '3569380', Status: '', Produk: '', Unit: '' },
                    Hasil: [unitTerjual, unitTersedia],
                    TotalHasil: 120,
                })}
            />,
        );

        expect(screen.getByRole('heading', { name: 'Hasil pencarian (120)' })).toBeTruthy();
        expect(screen.getByText(/Menampilkan 50 dari 120 nomor/)).toBeTruthy();
        expect(screen.getByText(/Terjual 7 \w+ 2026 \| INV\/SLB\/261007\/POS-001-0012/)).toBeTruthy();
        expect(AmbilHrefEkspor('csv', 'Ekspor')).toBe(
            '/kelola/persediaan/kartu-stok/nomor-seri/ekspor?cari=3569380&format=csv',
        );
    });

    it('detail unit terjual: produk, penjualan, pembeli, garansi, riwayat berurutan, tautan kartu stok', () => {
        RenderUji(
            <HalamanNomorSeri
                {...Props({
                    Saring: { Cari: 'IMEI-356938035643809', Status: '', Produk: '', Unit: unitTerjual.Uuid },
                    Hasil: [unitTerjual],
                    TotalHasil: 1,
                    Detail: { Unit: unitTerjual, Riwayat: [...riwayat] },
                })}
            />,
        );

        expect(screen.getByRole('region', { name: 'Riwayat IMEI-356938035643809' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Budi Santoso' }).getAttribute('href')).toBe(
            '/kelola/pelanggan/01K5PLG0000000000000000001',
        );
        expect(screen.getByRole('link', { name: 'INV/SLB/261007/POS-001-0012' }).getAttribute('href')).toBe(
            '/kelola/penjualan/01K5JUAL0000000000000000001',
        );
        expect(screen.getByText('Garansi 12 bulan')).toBeTruthy();
        // Label garansi tampil di baris hasil dan di rincian unit.
        expect(screen.getAllByText('Masih berlaku').length).toBeGreaterThanOrEqual(1);
        expect(screen.getByText('Stok awal (masuk stok)')).toBeTruthy();
        expect(screen.getByText('Penjualan (keluar stok)')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Lihat kartu stok produk ini' }).getAttribute('href')).toBe(
            '/kelola/persediaan/kartu-stok?produk=01K5PRD0000000000000PONSEL01',
        );
    });

    it('tanpa izin pelanggan/penjualan: pembeli tidak tampil dan nomor penjualan bukan tautan', () => {
        RenderUji(
            <HalamanNomorSeri
                {...Props({
                    Saring: { Cari: 'IMEI', Status: '', Produk: '', Unit: unitTerjual.Uuid },
                    Hasil: [unitTerjual],
                    TotalHasil: 1,
                    Izin: { Pelanggan: false, Penjualan: false, Produk: false },
                    Detail: {
                        Unit: { ...unitTerjual, NamaPelanggan: null, UuidPelanggan: null, UuidPenjualan: null },
                        Riwayat: [...riwayat],
                    },
                })}
            />,
        );

        expect(screen.getByText('Tanpa data pelanggan')).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Budi Santoso' })).toBeNull();
        expect(screen.queryByRole('link', { name: 'INV/SLB/261007/POS-001-0012' })).toBeNull();
        expect(screen.queryByRole('link', { name: 'Ponsel Android 8/256 GB Hitam' })).toBeNull();
    });

    it('unit tersedia: lokasi stok dan kartu stok menyertakan lokasi; tanpa hasil ada pesan kosong', () => {
        RenderUji(
            <HalamanNomorSeri
                {...Props({
                    Saring: { Cari: 'IMEI', Status: 'Tersedia', Produk: '', Unit: unitTersedia.Uuid },
                    Hasil: [unitTersedia],
                    TotalHasil: 1,
                    Detail: { Unit: unitTersedia, Riwayat: [riwayat[0]] },
                })}
            />,
        );

        expect(screen.getByText('Lokasi stok', { selector: 'dt' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Lihat kartu stok produk ini' }).getAttribute('href')).toBe(
            '/kelola/persediaan/kartu-stok?produk=01K5PRD0000000000000PONSEL01&gudang=01K5GDG0000000000000000001',
        );
        cleanup();

        RenderUji(<HalamanNomorSeri {...Props({ Saring: { Cari: 'XYZ', Status: '', Produk: '', Unit: '' } })} />);
        expect(screen.getByText('Nomor seri tidak ditemukan.')).toBeTruthy();
    });

    it('saring produk: penanda produk dan tautan melepas saringan; tab membawa produk', () => {
        RenderUji(
            <HalamanNomorSeri
                {...Props({
                    Saring: { Cari: '', Status: '', Produk: unitTerjual.UuidProduk, Unit: '' },
                    Produk: { Uuid: unitTerjual.UuidProduk, Nama: 'Ponsel Android 8/256 GB Hitam', Sku: 'HP-8256' },
                    Hasil: [unitTersedia],
                    TotalHasil: 1,
                })}
            />,
        );

        expect(screen.getByText('Ponsel Android 8/256 GB Hitam (HP-8256)')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Tampilkan semua produk' })).toBeTruthy();
        expect(
            screen
                .getByRole('navigation', { name: 'Cara menelusuri riwayat stok' })
                .querySelector('a[href^="/kelola/persediaan/kartu-stok?produk="]'),
        ).not.toBeNull();
    });

    it('BuatQueryNomorSeri hanya memuat saringan yang terisi', () => {
        expect(BuatQueryNomorSeri({ Cari: '', Status: '', Produk: '', Unit: '' })).toEqual({});
        expect(BuatQueryNomorSeri({ Cari: 'IMEI', Status: 'Tersedia', Produk: 'P', Unit: 'U' })).toEqual({
            cari: 'IMEI',
            status: 'Tersedia',
            produk: 'P',
            unit: 'U',
        });
    });
});
