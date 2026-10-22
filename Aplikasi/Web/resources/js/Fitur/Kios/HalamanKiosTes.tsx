import { act, cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import HalamanAntrianKios from '@/Halaman/Publik/AntrianKios';
import HalamanKios, { type PropsKios } from '@/Halaman/Publik/Kios';
import { RenderUji } from '@/Komponen/Katalog/TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const alamat = '/kedai-kopi-senja/kios/TokenKiosAcak32KarakterAbcdefghij';

const menu = {
    Kategori: [{ Uuid: 'KAT-MAKAN', Nama: 'Makanan' }],
    Produk: [
        {
            Uuid: 'NASI',
            UuidProdukSatuan: 'NASI-PCS',
            Nama: 'Nasi Goreng Kampung Spesial',
            UuidKategori: 'KAT-MAKAN',
            Harga: '35000.00',
            UrlGambar: null,
            KelompokPilihan: [],
        },
    ],
};

const props: PropsKios = {
    Aktif: true,
    Slug: 'kedai-kopi-senja',
    Token: 'TokenKiosAcak32KarakterAbcdefghij',
    Toko: { Nama: 'Kedai Kopi Senja' },
    Menu: menu,
    Pembayaran: { BayarDiKasir: true, Qris: false },
    DetikDiam: 90,
};

type Panggilan = { url: string; metode: string; badan: unknown };

function PasangFetch() {
    const panggilan: Panggilan[] = [];
    vi.stubGlobal(
        'fetch',
        vi.fn((url: string, opsi: RequestInit) => {
            const badan = opsi.body ? (JSON.parse(opsi.body as string) as Record<string, unknown>) : null;
            panggilan.push({ url, metode: opsi.method ?? 'GET', badan });
            let isi: unknown = {};
            let kode = 200;

            if (url.includes('/menu')) {
                isi = menu;
            } else if (url.endsWith('/hitung')) {
                isi = {
                    Baris: [],
                    Subtotal: '70000.00',
                    Diskon: '0.00',
                    BiayaLayanan: '0.00',
                    Pajak: [],
                    PajakTermasukHarga: '0.00',
                    Pembulatan: '0.00',
                    Total: '70000.00',
                };
            } else if (url.endsWith('/pesan')) {
                kode = 201;
                isi = {
                    KodeAkses: 'ABCDEFGH12345678',
                    Nomor: 'KI/SLO1/260926-001',
                    NomorAntrian: 'K007',
                    JenisSantap: 'Makan di sini',
                    Status: 'MenungguKonfirmasi',
                    LabelStatus: 'Silakan bayar di kasir',
                    PerluBayar: false,
                    SudahDibayar: false,
                    BayarDiKasir: true,
                    Total: '70000.00',
                };
            } else if (url.includes('/pesanan/')) {
                isi = {
                    KodeAkses: 'ABCDEFGH12345678',
                    Nomor: 'KI/SLO1/260926-001',
                    NomorAntrian: 'K007',
                    JenisSantap: 'Makan di sini',
                    Status: 'MenungguKonfirmasi',
                    LabelStatus: 'Silakan bayar di kasir',
                    PerluBayar: false,
                    SudahDibayar: false,
                    BayarDiKasir: true,
                    Total: '70000.00',
                };
            }

            return Promise.resolve({ ok: kode < 400, status: kode, json: () => Promise.resolve(isi) });
        }),
    );

    return panggilan;
}

describe('Kios pesan sendiri di layar sentuh (F-17 bagian 4)', () => {
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('tautan tidak dikenal dan kios mati menampilkan pesan, bukan menu', () => {
        RenderUji(<HalamanKios {...props} Toko={null} />);
        expect(screen.getByRole('heading', { name: 'Tautan kios tidak berlaku' })).toBeTruthy();
        cleanup();

        RenderUji(<HalamanKios {...props} Aktif={false} />);
        expect(screen.getByRole('heading', { name: 'Kios sedang tidak menerima pesanan' })).toBeTruthy();
        expect(screen.queryByText('Nasi Goreng Kampung Spesial')).toBeNull();
    });

    it('layar sambutan lalu pilihan makan di sini atau bawa pulang sebelum menu', () => {
        PasangFetch();
        RenderUji(<HalamanKios {...props} />);

        expect(screen.getByText('Sentuh untuk memesan')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: /Sentuh untuk memesan/ }));
        expect(screen.getByRole('heading', { name: 'Makan di sini atau bawa pulang?' })).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: /Bawa pulang/ }));
        expect(screen.getByRole('heading', { name: 'Bawa pulang' })).toBeTruthy();
        expect(screen.getByText('Rp 35.000')).toBeTruthy();
        // Produk tanpa foto tetap punya penanda: inisial dua kata pertama (`Nasi Goreng …` → `NG`).
        expect(screen.getByText('NG')).toBeTruthy();
    });

    it('memesan bayar di kasir: total dari server, kiriman memuat jenis santap & Uuid ULID, lalu nomor antrian', async () => {
        const panggilan = PasangFetch();
        RenderUji(<HalamanKios {...props} />);

        fireEvent.click(screen.getByRole('button', { name: /Sentuh untuk memesan/ }));
        fireEvent.click(screen.getByRole('button', { name: /Makan di sini/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Tambah Nasi Goreng Kampung Spesial' }));
        fireEvent.click(screen.getByRole('button', { name: 'Tambah Nasi Goreng Kampung Spesial' }));

        await waitFor(() => expect(screen.getAllByText('Rp 70.000').length).toBeGreaterThan(0));
        expect(panggilan.filter((p) => p.url === `${alamat}/hitung`).at(-1)?.badan).toEqual({
            JenisSantap: 'MakanDiTempat',
            Baris: [{ UuidProduk: 'NASI', Jumlah: 2, Pilihan: [] }],
        });

        await waitFor(() =>
            expect(screen.getAllByRole('button', { name: 'Lanjut ke pembayaran' })[0]?.hasAttribute('disabled')).toBe(
                false,
            ),
        );
        fireEvent.click(screen.getAllByRole('button', { name: 'Lanjut ke pembayaran' })[0] as HTMLElement);
        expect(screen.getByRole('heading', { name: 'Periksa pesanan Anda' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Bayar sekarang dengan QRIS' })).toBeNull();

        await act(async () => {
            fireEvent.click(screen.getAllByRole('button', { name: 'Bayar di kasir' })[0] as HTMLElement);
        });

        await waitFor(() => expect(screen.getByText('K007')).toBeTruthy());
        const kirim = panggilan.find((p) => p.url === `${alamat}/pesan`)?.badan as Record<string, unknown>;
        expect(kirim.JenisSantap).toBe('MakanDiTempat');
        expect(kirim.MetodePembayaran).toBe('BayarSaatAmbil');
        expect(String(kirim.Uuid)).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
        // Tanpa data pribadi sama sekali.
        expect(Object.keys(kirim).sort()).toEqual(['Baris', 'JenisSantap', 'MetodePembayaran', 'Uuid']);
        expect(screen.getByText(/bayar\sRp\s70\.000/)).toBeTruthy();
    });

    it('tombol QRIS hanya ada bila toko mengaktifkan QRIS', async () => {
        PasangFetch();
        RenderUji(<HalamanKios {...props} Pembayaran={{ BayarDiKasir: true, Qris: true }} />);

        fireEvent.click(screen.getByRole('button', { name: /Sentuh untuk memesan/ }));
        fireEvent.click(screen.getByRole('button', { name: /Makan di sini/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Tambah Nasi Goreng Kampung Spesial' }));
        await waitFor(() =>
            expect(screen.getAllByRole('button', { name: 'Lanjut ke pembayaran' })[0]?.hasAttribute('disabled')).toBe(
                false,
            ),
        );
        fireEvent.click(screen.getAllByRole('button', { name: 'Lanjut ke pembayaran' })[0] as HTMLElement);

        expect(screen.getByRole('button', { name: 'Bayar sekarang dengan QRIS' })).toBeTruthy();
    });
});

describe('Layar antrian kios', () => {
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('menampilkan nomor disiapkan dan siap diambil di dua kolom terpisah', () => {
        PasangFetch();
        RenderUji(
            <HalamanAntrianKios
                Aktif
                Slug="kedai-kopi-senja"
                Token="TokenKiosAcak32KarakterAbcdefghij"
                Toko={{ Nama: 'Kedai Kopi Senja' }}
                Antrian={{ Disiapkan: ['K002', 'K004'], Siap: ['K001'] }}
            />,
        );

        const disiapkan = screen.getByRole('region', { name: 'Sedang disiapkan' });
        const siap = screen.getByRole('region', { name: 'Siap diambil' });
        expect(disiapkan.textContent).toContain('K002');
        expect(disiapkan.textContent).toContain('K004');
        expect(siap.textContent).toContain('K001');
        expect(siap.textContent).not.toContain('K002');
    });

    it('layar tidak aktif memberi pesan, bukan daftar', () => {
        RenderUji(
            <HalamanAntrianKios Aktif={false} Slug="x" Token="y" Toko={null} Antrian={{ Disiapkan: [], Siap: [] }} />,
        );

        expect(screen.getByText('Layar antrian tidak aktif.')).toBeTruthy();
    });
});
