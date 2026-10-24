import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import StatusPesananOnline from '@/Halaman/Publik/StatusPesananOnline';
import HalamanTokoOnline from '@/Halaman/Publik/TokoOnline';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * F-17 (D-55): tampilan toko online modern. Pencarian & kategori menyaring menu di peramban (harga tetap dihitung
 * server), kartu produk menandai jumlah di keranjang, bilah keranjang mengambang di HP, dan halaman status
 * menampilkan perkembangan pesanan.
 */

const props = {
    Aktif: true,
    Slug: 'kopi-senja',
    Toko: { Nama: 'Kopi Senja', NamaOutlet: 'Kopi Senja Laweyan', Alamat: 'Jl. Slamet Riyadi 10' },
    Outlet: [{ Uuid: '01JOUTLET00000000000000001', Nama: 'Kopi Senja Laweyan' }],
    OutletDipilih: '01JOUTLET00000000000000001',
    Pemenuhan: { AmbilSendiri: true, Kirim: true },
    Pembayaran: { BayarSaatAmbil: true, Cod: true, QrisOnline: true },
    MinimalPesanan: '15000.00',
    PesanTutup: null,
    Menu: {
        Kategori: [
            { Uuid: 'K-KOPI', Nama: 'Kopi' },
            { Uuid: 'K-ROTI', Nama: 'Roti' },
        ],
        Produk: [
            {
                Uuid: 'P-1',
                Nama: 'Kopi Susu Gula Aren',
                Harga: '25000.00',
                UrlGambar: null,
                UuidKategori: 'K-KOPI',
                KelompokPilihan: [],
            },
            {
                Uuid: 'P-2',
                Nama: 'Roti Bakar Cokelat',
                Harga: '18000.00',
                UrlGambar: null,
                UuidKategori: 'K-ROTI',
                KelompokPilihan: [],
            },
        ],
    },
    Akun: { Aktif: false, Pelanggan: null, AlamatTerakhir: null },
};

beforeEach(() => {
    AturHalamanUji({}, '/kopi-senja');
    vi.stubGlobal(
        'fetch',
        vi.fn(() =>
            Promise.resolve(new Response('{}', { status: 422, headers: { 'Content-Type': 'application/json' } })),
        ),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('tampilan toko online', () => {
    it('kepala toko menampilkan status buka, cara menerima, QRIS, dan minimal belanja', () => {
        RenderUji(<HalamanTokoOnline {...props} />);

        expect(screen.getByText('Terima pesanan')).toBeTruthy();
        expect(screen.getByText('Bayar QRIS')).toBeTruthy();
        expect(screen.getByText('Min. Rp 15.000')).toBeTruthy();
        expect(screen.getByText(/Jl\. Slamet Riyadi 10/)).toBeTruthy();
    });

    it('kategori dan pencarian memfilter menu; hasil kosong memberi petunjuk', () => {
        RenderUji(<HalamanTokoOnline {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Roti' }));
        expect(screen.queryByText('Kopi Susu Gula Aren')).toBeNull();
        expect(screen.getByText('Roti Bakar Cokelat')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Semua' }));
        fireEvent.change(screen.getByPlaceholderText('Cari menu'), { target: { value: 'gula aren' } });
        expect(screen.getByText('Kopi Susu Gula Aren')).toBeTruthy();
        expect(screen.queryByText('Roti Bakar Cokelat')).toBeNull();

        fireEvent.change(screen.getByPlaceholderText('Cari menu'), { target: { value: 'martabak' } });
        expect(screen.getByText(/Tidak ada menu yang cocok/)).toBeTruthy();
    });

    it('desktop: keranjang tampil sebagai kolom di samping menu; kartu menandai jumlah dan punya pengatur jumlah', () => {
        RenderUji(<HalamanTokoOnline {...props} />);

        const keranjang = screen.getByRole('complementary', { name: 'Keranjang' });
        expect(within(keranjang).getByText('Keranjang masih kosong')).toBeTruthy();
        expect(screen.queryByText(/Lihat keranjang/)).toBeNull();

        fireEvent.click(screen.getAllByRole('button', { name: 'Tambah' })[0] as HTMLElement);
        expect(screen.getByText('1 di keranjang')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Tambah satu Kopi Susu Gula Aren' }));
        expect(screen.getByText('2 di keranjang')).toBeTruthy();
        expect(within(keranjang).getByRole('list', { name: 'Isi keranjang' })).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Kurangi Kopi Susu Gula Aren' }));
        fireEvent.click(screen.getByRole('button', { name: 'Kurangi Kopi Susu Gula Aren' }));
        expect(screen.queryByText(/di keranjang/)).toBeNull();
        expect(within(keranjang).getByText('Keranjang masih kosong')).toBeTruthy();
    });

    it('keranjang dua langkah: lanjut ke data pesanan lalu kembali; kirim pesanan menunggu persetujuan data', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    new Response(
                        JSON.stringify({
                            Subtotal: '25000.00',
                            Diskon: '0.00',
                            BiayaLayanan: '0.00',
                            Pajak: [],
                            Ongkir: '0.00',
                            DiskonOngkir: '0.00',
                            Total: '25000.00',
                            Zona: null,
                            Voucher: null,
                        }),
                        { status: 200, headers: { 'Content-Type': 'application/json' } },
                    ),
                ),
            ),
        );
        RenderUji(<HalamanTokoOnline {...props} />);
        fireEvent.click(screen.getAllByRole('button', { name: 'Tambah' })[0] as HTMLElement);

        const lanjut = screen.getByRole('button', { name: 'Lanjut ke data pesanan' });
        expect(lanjut.hasAttribute('disabled')).toBe(true);
        await waitFor(() => expect(lanjut.hasAttribute('disabled')).toBe(false));
        fireEvent.click(lanjut);

        expect(screen.getByRole('form', { name: 'Data pesanan' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Kirim pesanan' }).hasAttribute('disabled')).toBe(true);
        fireEvent.click(screen.getByRole('checkbox'));
        expect(screen.getByRole('button', { name: 'Kirim pesanan' }).hasAttribute('disabled')).toBe(false);

        fireEvent.click(screen.getByRole('button', { name: 'Kembali ke keranjang' }));
        expect(screen.getByRole('button', { name: 'Lanjut ke data pesanan' })).toBeTruthy();
    });

    it('HP: keranjang tidak di dasar halaman; bilah bawah membuka laci keranjang', () => {
        vi.stubGlobal('matchMedia', (kueri: string) => ({
            matches: true,
            media: kueri,
            onchange: null,
            addEventListener: () => undefined,
            removeEventListener: () => undefined,
            addListener: () => undefined,
            removeListener: () => undefined,
            dispatchEvent: () => false,
        }));
        RenderUji(<HalamanTokoOnline {...props} />);

        expect(screen.queryByRole('complementary', { name: 'Keranjang' })).toBeNull();
        expect(screen.queryByText(/Lihat keranjang/)).toBeNull();

        fireEvent.click(screen.getAllByRole('button', { name: 'Tambah' })[0] as HTMLElement);
        const bilah = screen.getByRole('button', { name: /Lihat keranjang/ });
        fireEvent.click(bilah);

        const laci = screen.getByRole('dialog', { name: 'Keranjang' });
        expect(within(laci).getByRole('list', { name: 'Isi keranjang' })).toBeTruthy();
        expect(within(laci).getByRole('button', { name: 'Lanjut ke data pesanan' })).toBeTruthy();
    });

    it('produk berpilihan membuka lembar opsi, memvalidasi aturan pilihan, lalu masuk keranjang', () => {
        const berpilihan = {
            ...props,
            Menu: {
                Kategori: [],
                Produk: [
                    {
                        Uuid: 'P-3',
                        Nama: 'Es Kopi Kampus',
                        Harga: '22000.00',
                        UrlGambar: null,
                        UuidKategori: null,
                        KelompokPilihan: [
                            {
                                Uuid: 'G-1',
                                Nama: 'Tingkat manis',
                                MinimalPilih: 1,
                                MaksimalPilih: 1,
                                Pilihan: [
                                    { Uuid: 'X-1', Nama: 'Normal', Harga: '0.00' },
                                    { Uuid: 'X-2', Nama: 'Extra shot', Harga: '5000.00' },
                                ],
                            },
                        ],
                    },
                ],
            },
        };
        RenderUji(<HalamanTokoOnline {...berpilihan} />);

        expect(screen.queryByRole('button', { name: 'Tambah' })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Pilih opsi' }));

        const lembar = screen.getByRole('dialog', { name: 'Es Kopi Kampus' });
        fireEvent.click(within(lembar).getByRole('button', { name: 'Tambah ke keranjang' }));
        expect(within(lembar).getByText(/Pilihan Tingkat manis belum sesuai/)).toBeTruthy();

        fireEvent.click(within(lembar).getByLabelText(/Extra shot/));
        fireEvent.click(within(lembar).getByRole('button', { name: 'Tambah ke keranjang' }));

        expect(screen.queryByRole('dialog', { name: 'Es Kopi Kampus' })).toBeNull();
        expect(screen.getByText('1 di keranjang')).toBeTruthy();
        expect(within(screen.getByRole('complementary', { name: 'Keranjang' })).getByText('Extra shot')).toBeTruthy();
    });

    it('toko tutup: pesan tutup tampil dan tanpa pencarian menu', () => {
        RenderUji(<HalamanTokoOnline {...props} Aktif={false} PesanTutup="Buka lagi besok pukul 08.00." />);

        expect(screen.getByText('Buka lagi besok pukul 08.00.')).toBeTruthy();
        expect(screen.getByText('Sedang tutup')).toBeTruthy();
        expect(screen.queryByPlaceholderText('Cari menu')).toBeNull();
    });
});

describe('status pesanan online', () => {
    const BuatPesanan = (status: string, label: string) => ({
        Nomor: 'ON/SOLO/261005-0001',
        NamaPelanggan: 'Sinta',
        JenisPemenuhan: 'Ambil sendiri',
        Status: status,
        LabelStatus: label,
        Total: '43000.00',
        Ongkir: '0.00',
        DibuatPada: null,
        MetodePembayaran: 'Bayar saat ambil',
        PerluBayar: false,
        SudahDibayar: false,
        JumlahDibayar: null,
        Baris: [{ Nama: 'Kopi Susu Gula Aren', Jumlah: '1', Total: '25000.00' }],
        Pengiriman: null,
    });

    it('menandai langkah yang sedang berjalan dan yang sudah lewat', () => {
        RenderUji(
            <StatusPesananOnline
                Ditemukan
                Slug="kopi-senja"
                KodeAkses="abc"
                Toko={{ Nama: 'Kopi Senja' }}
                Pesanan={BuatPesanan('Diproses', 'Sedang diproses')}
            />,
        );

        const langkah = screen.getByRole('list', { name: 'Perkembangan pesanan' });
        expect(langkah.querySelector('[aria-current="step"]')?.textContent).toContain('Diproses');
        expect(langkah.querySelectorAll('svg').length).toBe(2);
    });

    it('pesanan ditolak: tampil sebagai pemberitahuan gagal, bukan perkembangan', () => {
        RenderUji(
            <StatusPesananOnline
                Ditemukan
                Slug="kopi-senja"
                KodeAkses="abc"
                Toko={{ Nama: 'Kopi Senja' }}
                Pesanan={BuatPesanan('Ditolak', 'Ditolak toko')}
            />,
        );

        expect(screen.queryByRole('list', { name: 'Perkembangan pesanan' })).toBeNull();
        expect(screen.getAllByText('Ditolak toko').length).toBeGreaterThan(0);
    });
});
