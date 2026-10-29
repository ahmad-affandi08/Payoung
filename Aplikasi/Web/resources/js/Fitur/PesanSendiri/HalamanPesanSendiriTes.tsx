import { onlineManager } from '@tanstack/react-query';
import { act, cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanPesanSendiri, { type PropsPesanSendiri } from '@/Halaman/Publik/PesanSendiri';
import { RenderUji } from '@/Komponen/Katalog/TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const props: PropsPesanSendiri = {
    Aktif: true,
    Toko: { Nama: 'Kedai Kopi Senja Rasa Nusantara', NamaOutlet: 'Outlet Solo Baru' },
    Meja: { Nama: '7' },
    Token: 'TokenMejaTujuhAcak32KarakterAbcd',
    Slug: 'kedai-kopi-senja',
    Menu: {
        Kategori: [
            { Uuid: 'KAT-MINUM', Nama: 'Minuman' },
            { Uuid: 'KAT-MAKAN', Nama: 'Makanan' },
        ],
        Produk: [
            {
                Uuid: 'KOPI',
                UuidProdukSatuan: 'KOPI-PCS',
                Nama: 'Es Kopi Susu Gula Aren Ukuran Besar',
                UuidKategori: 'KAT-MINUM',
                Harga: '25000.00',
                UrlGambar: null,
                KelompokPilihan: [
                    {
                        Uuid: 'K-GULA',
                        Nama: 'Level Gula',
                        MinimalPilih: 1,
                        MaksimalPilih: 1,
                        Pilihan: [
                            { Uuid: 'P-NORMAL', Nama: 'Normal', Harga: '0.00' },
                            { Uuid: 'P-SEDIKIT', Nama: 'Sedikit gula', Harga: '0.00' },
                        ],
                    },
                ],
            },
            {
                Uuid: 'ESTEH',
                UuidProdukSatuan: null,
                Nama: 'Es Teh Manis Melati',
                UuidKategori: 'KAT-MINUM',
                Harga: '8000.00',
                UrlGambar: null,
                KelompokPilihan: [],
                NamaAtributVarian: 'Ukuran',
                Varian: [
                    { Uuid: 'ESTEH-REG', Nama: 'Reguler', Atribut: [], Harga: '8000.00', Tersedia: true },
                    { Uuid: 'ESTEH-JUMBO', Nama: 'Jumbo', Atribut: [], Harga: '12000.00', Tersedia: true },
                    { Uuid: 'ESTEH-RAKSASA', Nama: 'Raksasa', Atribut: [], Harga: null, Tersedia: false },
                ],
            },
            {
                Uuid: 'NASI',
                UuidProdukSatuan: 'NASI-PCS',
                Nama: 'Nasi Goreng Kampung Spesial Telur Mata Sapi',
                UuidKategori: 'KAT-MAKAN',
                Harga: '35000.00',
                UrlGambar: null,
                KelompokPilihan: [],
            },
        ],
    },
};

const alamat = '/kedai-kopi-senja/meja/TokenMejaTujuhAcak32KarakterAbcd';

type Panggilan = { url: string; metode: string; badan: unknown };

function PasangFetch(
    status: { Status: string; AlasanTolak: string | null } = { Status: 'MenungguKonfirmasi', AlasanTolak: null },
    qrisLunas = false,
) {
    const panggilan: Panggilan[] = [];
    const FetchTiruan = vi.fn((url: string, opsi: RequestInit) => {
        const badan = opsi.body ? (JSON.parse(opsi.body as string) as Record<string, unknown>) : null;
        panggilan.push({ url, metode: opsi.method ?? 'GET', badan });
        let isi: unknown = {};
        let kode = 200;

        if (url.endsWith('/hitung')) {
            const baris = (badan?.Baris ?? []) as { UuidProduk: string; Jumlah: number }[];
            const total = baris.map((b) => (b.UuidProduk === 'NASI' ? 35000 : 25000) * b.Jumlah);
            isi = {
                Baris: baris.map((b, i) => ({
                    UuidProduk: b.UuidProduk,
                    NamaProduk: b.UuidProduk,
                    Jumlah: `${String(b.Jumlah)}.0000`,
                    HargaSatuan: '0.00',
                    HargaPilihan: '0.00',
                    Total: `${String(total[i])}.00`,
                })),
                Subtotal: `${String(total.reduce((a, b) => a + b, 0))}.00`,
                // Perkiraan dari server: biaya layanan 5% + PBJT 10% (angka tiruan tetap).
                Diskon: '0.00',
                BiayaLayanan: '3500.00',
                Pajak: [{ Kode: 'PbjtMakananMinuman', Nama: 'PBJT Makanan & Minuman', Tarif: '10', Jumlah: '7350.00' }],
                PajakTermasukHarga: '0.00',
                Pembulatan: '0.00',
                Total: '80850.00',
                Catatan: 'Perkiraan. Total akhir mengikuti tagihan di kasir (promo, pembulatan, metode bayar).',
            };
        } else if (url.endsWith('/menu')) {
            isi = { Kategori: props.Menu.Kategori, Produk: props.Menu.Produk.filter((p) => p.Uuid !== 'NASI') };
        } else if (url.endsWith('/pesan-qris')) {
            kode = 201;
            isi = {
                KodeAkses: 'KODEAKSES1234567',
                Nomor: 'ME/SLO1/260926-0001',
                Status: 'MenungguPembayaran',
                LabelStatus: 'Menunggu pembayaran',
                PerluBayar: true,
                SudahDibayar: false,
                Total: '80850.00',
            };
        } else if (url.endsWith('/bayar')) {
            kode = 201;
            isi = {
                Jumlah: '80850.00',
                Status: 'Menunggu',
                SudahDibayar: false,
                KedaluwarsaPada: '2026-09-26T10:15:00Z',
                Qr: '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
                UrlBayar: null,
            };
        } else if (url.endsWith('/status')) {
            isi = {
                KodeAkses: 'KODEAKSES1234567',
                Nomor: 'ME/SLO1/260926-0001',
                Status: qrisLunas ? 'MenungguKonfirmasi' : 'MenungguPembayaran',
                LabelStatus: qrisLunas ? 'Sudah dibayar, menunggu kasir menerima pesanan' : 'Menunggu pembayaran',
                PerluBayar: !qrisLunas,
                SudahDibayar: qrisLunas,
                Total: '80850.00',
            };
        } else if (url.endsWith('/pesan')) {
            kode = 201;
            isi = { Uuid: badan?.Uuid, Nomor: 'QR/SLO1/260926-0001', Status: 'MenungguKonfirmasi' };
        } else if (url.includes('/pesanan/')) {
            isi = {
                Uuid: url.split('/').pop(),
                Nomor: 'QR/SLO1/260926-0001',
                Status: status.Status,
                Baris: [
                    {
                        Uuid: 'B1',
                        NamaProduk: 'Nasi Goreng Kampung Spesial Telur Mata Sapi',
                        Jumlah: '2.0000',
                        Pilihan: [],
                        Catatan: null,
                    },
                ],
                Subtotal: '70000.00',
                AlasanTolak: status.AlasanTolak,
            };
        }

        return Promise.resolve({ ok: kode < 400, status: kode, json: () => Promise.resolve(isi) });
    });
    vi.stubGlobal('fetch', FetchTiruan);

    return panggilan;
}

const NAMA_TOKO_UJI = 'Kedai Kopi Senja Rasa Nusantara';

describe('Halaman publik pesan sendiri QR meja (F-17)', () => {
    beforeEach(() => window.localStorage.clear());
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('menampilkan menu per kategori dengan harga Rupiah dari server', () => {
        PasangFetch();
        RenderUji(<HalamanPesanSendiri {...props} />);

        expect(screen.getByRole('heading', { name: 'Meja 7' })).toBeTruthy();
        expect(screen.getByText('Rp 25.000')).toBeTruthy();
        // Produk tanpa foto tetap punya penanda: inisial dua kata pertama (`Es Kopi …` → `EK`).
        expect(screen.getByText('EK')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Makanan' }));
        expect(screen.queryByText('Es Kopi Susu Gula Aren Ukuran Besar')).toBeNull();
        expect(screen.getByText('Nasi Goreng Kampung Spesial Telur Mata Sapi')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Makanan' }).getAttribute('aria-pressed')).toBe('true');
    });

    it('kepala meja (D-239): logo tenant, nama meja berukuran terbesar, dan cara bayar. Tanpa logo jadi inisial', () => {
        PasangFetch();
        const dengan = {
            ...props,
            Toko: { Nama: NAMA_TOKO_UJI, NamaOutlet: 'Solo Baru', UrlLogo: '/kopi-senja/logo?outlet=O1' },
        };
        const { unmount: Lepas } = RenderUji(<HalamanPesanSendiri {...dengan} />);

        expect(screen.getByRole('img', { name: `Logo ${NAMA_TOKO_UJI}` }).getAttribute('src')).toBe(
            '/kopi-senja/logo?outlet=O1',
        );
        expect(screen.getByRole('heading', { name: 'Meja 7' }).className).toContain('text-sorotan-besar-hp');
        expect(screen.getByText('Pesan dari HP')).toBeTruthy();
        expect(screen.getByText('Bayar di kasir')).toBeTruthy();
        expect(screen.queryByText('Bayar QRIS')).toBeNull();
        Lepas();

        RenderUji(<HalamanPesanSendiri {...props} Pembayaran={{ Qris: true }} />);
        expect(screen.queryByRole('img', { name: /Logo/ })).toBeNull();
        expect(screen.getByText('Bayar QRIS')).toBeTruthy();
    });

    it('kartu menu menandai jumlah di keranjang dan menyebut jumlah pilihan menu', () => {
        PasangFetch();
        RenderUji(<HalamanPesanSendiri {...props} />);

        expect(screen.getByText(`${String(props.Menu.Produk.length)} pilihan`)).toBeTruthy();
        expect(screen.queryByText(/di keranjang$/)).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Tambah Nasi Goreng Kampung Spesial Telur Mata Sapi' }));
        expect(screen.getByText('1 di keranjang')).toBeTruthy();
    });

    it('QR tidak dikenal, pesan sendiri belum aktif, dan menu kosong', () => {
        RenderUji(<HalamanPesanSendiri {...props} Meja={null} Toko={null} />);
        expect(screen.getByRole('heading', { name: 'QR meja tidak dikenal' })).toBeTruthy();
        cleanup();

        RenderUji(<HalamanPesanSendiri {...props} Aktif={false} Menu={{ Kategori: [], Produk: [] }} />);
        expect(screen.getByText('Pesan sendiri belum aktif')).toBeTruthy();
        cleanup();

        RenderUji(<HalamanPesanSendiri {...props} Menu={{ Kategori: [], Produk: [] }} />);
        expect(screen.getByText('Menu belum tersedia')).toBeTruthy();
    });

    it('menu berpilihan wajib: lembar pilihan menolak sebelum Level Gula dipilih', () => {
        PasangFetch();
        RenderUji(<HalamanPesanSendiri {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Tambah Es Kopi Susu Gula Aren Ukuran Besar' }));
        fireEvent.click(screen.getByRole('button', { name: 'Tambah ke keranjang' }));
        expect(screen.getByText('Pilih Level Gula dulu.')).toBeTruthy();

        fireEvent.click(screen.getByLabelText('Sedikit gula'));
        fireEvent.click(screen.getByRole('button', { name: 'Tambah ke keranjang' }));
        expect(screen.getByRole('button', { name: /Lihat keranjang \| 1 item/ })).toBeTruthy();
    });

    it('keranjang: subtotal dari server, kirim pesanan dengan Uuid ULID, lalu status menunggu konfirmasi', async () => {
        const panggilan = PasangFetch();
        RenderUji(<HalamanPesanSendiri {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Tambah Nasi Goreng Kampung Spesial Telur Mata Sapi' }));
        fireEvent.click(screen.getByRole('button', { name: 'Tambah Nasi Goreng Kampung Spesial Telur Mata Sapi' }));
        // v2.06: bilah keranjang menampilkan perkiraan total dari server (subtotal Rp 70.000 + layanan + PBJT).
        await waitFor(() => expect(screen.getByText('Rp 80.850')).toBeTruthy());
        expect(panggilan.filter((p) => p.url === `${alamat}/hitung`).map((p) => p.badan)).toEqual([
            {
                Baris: [{ UuidProduk: 'NASI', Jumlah: 2, Pilihan: [] }],
            },
        ]);
        expect(JSON.parse(window.localStorage.getItem(`pesan-sendiri:${props.Token}:keranjang`) ?? '[]')).toHaveLength(
            1,
        );

        fireEvent.click(screen.getByRole('button', { name: /Lihat keranjang \| 2 item/ }));
        fireEvent.change(screen.getByLabelText('Nama pemesan (opsional)'), { target: { value: 'Bu Ratna' } });
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Kirim pesanan' }));
            await Promise.resolve();
        });

        await waitFor(() =>
            expect(screen.getByText('Menunggu konfirmasi staf. Halaman ini diperbarui otomatis.')).toBeTruthy(),
        );
        const kiriman = panggilan.find((p) => p.url === `${alamat}/pesan`)?.badan as Record<string, unknown>;
        expect(kiriman.Uuid).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
        expect(kiriman.NamaPemesan).toBe('Bu Ratna');
        expect(kiriman).not.toHaveProperty('Subtotal');
        expect((kiriman.Baris as Record<string, unknown>[])[0]).toMatchObject({
            UuidProduk: 'NASI',
            Jumlah: 2,
            Pilihan: [],
            Catatan: null,
        });
        expect(JSON.parse(window.localStorage.getItem(`pesan-sendiri:${props.Token}:keranjang`) ?? '[]')).toEqual([]);
    });

    it('status ditolak menampilkan alasan. Offline menampilkan peringatan', async () => {
        window.localStorage.setItem(
            `pesan-sendiri:${props.Token}:pesanan`,
            JSON.stringify(['01J8Z0A1B2C3D4E5F6G7H8J9K0']),
        );
        PasangFetch({ Status: 'Ditolak', AlasanTolak: 'Nasi goreng sedang habis' });
        RenderUji(<HalamanPesanSendiri {...props} />);

        await waitFor(() => expect(screen.getByText('Ditolak')).toBeTruthy());
        fireEvent.click(screen.getByRole('button', { name: 'Lihat status' }));
        await waitFor(() => expect(screen.getByText('Alasan: Nasi goreng sedang habis')).toBeTruthy());
        expect(screen.getByRole('button', { name: 'Pesan lagi' })).toBeTruthy();

        act(() => {
            window.dispatchEvent(new Event('offline'));
        });
        expect(screen.getByText(/Tidak ada koneksi internet/)).toBeTruthy();
    });

    it('v2.06 varian wajib dipilih (varian tanpa harga nonaktif). Keranjang menampilkan nama varian dan perkiraan total dari server', async () => {
        // Test sebelumnya memicu event offline; status online TanStack Query bersifat global, jadi dipulihkan.
        onlineManager.setOnline(true);
        const panggilan = PasangFetch();
        RenderUji(<HalamanPesanSendiri {...props} />);

        expect(screen.getByText('Mulai Rp 8.000')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Tambah Es Teh Manis Melati' }));
        expect((screen.getByRole('radio', { name: /Raksasa/ }) as HTMLInputElement).disabled).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'Tambah ke keranjang' }));
        expect(screen.getByText('Pilih ukuran dulu.')).toBeTruthy();

        fireEvent.click(screen.getByRole('radio', { name: /Jumbo/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Tambah ke keranjang' }));
        await waitFor(() => expect(screen.getByText('Rp 80.850')).toBeTruthy());
        await waitFor(() =>
            expect(panggilan.filter((p) => p.url === `${alamat}/hitung`).map((p) => p.badan)).toEqual([
                { Baris: [{ UuidProduk: 'ESTEH', UuidVarian: 'ESTEH-JUMBO', Jumlah: 1, Pilihan: [] }] },
            ]),
        );
        await waitFor(() => expect(screen.getByText('Rp 80.850')).toBeTruthy());

        fireEvent.click(screen.getByRole('button', { name: /Lihat keranjang \| 1 item/ }));
        expect(screen.getByText('Es Teh Manis Melati — Jumbo')).toBeTruthy();
        expect(screen.getByText('Biaya layanan')).toBeTruthy();
        expect(screen.getByText('PBJT Makanan & Minuman 10%')).toBeTruthy();
        expect(screen.getByText('Perkiraan total')).toBeTruthy();

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Kirim pesanan' }));
            await Promise.resolve();
        });
        const kiriman = panggilan.find((p) => p.url === `${alamat}/pesan`)?.badan as Record<string, unknown>;
        expect((kiriman.Baris as Record<string, unknown>[])[0]).toMatchObject({
            UuidProduk: 'ESTEH',
            UuidVarian: 'ESTEH-JUMBO',
        });
    });
    it('SLS-04: tanpa QRIS tombol bayar sekarang tidak ada, dengan QRIS ada dan membuka layar QR sampai lunas', async () => {
        const panggilan = PasangFetch({ Status: 'MenungguKonfirmasi', AlasanTolak: null }, true);
        RenderUji(<HalamanPesanSendiri {...props} Pembayaran={{ Qris: true }} />);

        fireEvent.click(screen.getByRole('button', { name: 'Tambah Nasi Goreng Kampung Spesial Telur Mata Sapi' }));
        fireEvent.click(screen.getByRole('button', { name: /Lihat keranjang \| 1 item/ }));
        expect(screen.getByRole('button', { name: 'Kirim pesanan, bayar di kasir' })).toBeTruthy();
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Bayar sekarang dengan QRIS' }));
            await Promise.resolve();
        });

        await waitFor(() => expect(screen.getByText('Pembayaran diterima')).toBeTruthy());
        const kiriman = panggilan.find((p) => p.url === `${alamat}/pesan-qris`)?.badan as Record<string, unknown>;
        expect(kiriman.Uuid).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
        expect(
            panggilan.some((p) => p.url === `${alamat}/pesan-qris/KODEAKSES1234567/bayar` && p.metode === 'POST'),
        ).toBe(true);
        expect(panggilan.some((p) => p.url === `${alamat}/pesan`)).toBe(false);
        expect(JSON.parse(window.localStorage.getItem(`pesan-sendiri:${props.Token}:keranjang`) ?? '[]')).toEqual([]);
        fireEvent.click(screen.getByRole('button', { name: 'Pesan lagi' }));
        expect(screen.getByRole('heading', { name: 'Meja 7' })).toBeTruthy();
    });

    it('SLS-04: menunggu pembayaran menampilkan QR dan jumlah', async () => {
        PasangFetch({ Status: 'MenungguKonfirmasi', AlasanTolak: null }, false);
        RenderUji(<HalamanPesanSendiri {...props} Pembayaran={{ Qris: true }} />);

        fireEvent.click(screen.getByRole('button', { name: 'Tambah Nasi Goreng Kampung Spesial Telur Mata Sapi' }));
        fireEvent.click(screen.getByRole('button', { name: /Lihat keranjang \| 1 item/ }));
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Bayar sekarang dengan QRIS' }));
            await Promise.resolve();
        });

        await waitFor(() => expect(screen.getByAltText('Kode QRIS untuk pembayaran')).toBeTruthy());
        expect(screen.getByText('Rp 80.850')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Kembali ke menu' })).toBeTruthy();
    });

    it('tanpa QRIS: tombol QRIS tidak tampil dan label kirim tetap "Kirim pesanan"', () => {
        PasangFetch();
        RenderUji(<HalamanPesanSendiri {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Tambah Nasi Goreng Kampung Spesial Telur Mata Sapi' }));
        fireEvent.click(screen.getByRole('button', { name: /Lihat keranjang \| 1 item/ }));

        expect(screen.queryByRole('button', { name: 'Bayar sekarang dengan QRIS' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Kirim pesanan' })).toBeTruthy();
    });

    it('BR-17.2: menu disegarkan berkala. Produk yang ditandai habis hilang dari daftar', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        PasangFetch();
        RenderUji(<HalamanPesanSendiri {...props} />);
        expect(screen.getByText('Nasi Goreng Kampung Spesial Telur Mata Sapi')).toBeTruthy();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(31000);
        });

        await waitFor(() => expect(screen.queryByText('Nasi Goreng Kampung Spesial Telur Mata Sapi')).toBeNull());
        vi.useRealTimers();
    });
});
