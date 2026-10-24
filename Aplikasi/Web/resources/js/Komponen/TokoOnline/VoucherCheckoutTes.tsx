import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanTokoOnline from '@/Halaman/Publik/TokoOnline';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * v3.46: kode voucher di checkout toko online. Halaman tidak menghitung potongannya sendiri; kode dikirim ke
 * `/{slug}/keranjang/hitung` dan yang tampil adalah hasil server. Kode yang ditolak dilepas dan keranjang dihitung
 * ulang tanpa voucher, supaya pembeli tetap bisa memesan.
 */

const props = {
    Aktif: true,
    Slug: 'kopi-senja',
    Toko: { Nama: 'Kopi Senja', NamaOutlet: 'Kopi Senja Laweyan', Alamat: null },
    Outlet: [{ Uuid: '01JOUTLET00000000000000001', Nama: 'Kopi Senja Laweyan' }],
    OutletDipilih: '01JOUTLET00000000000000001',
    Pemenuhan: { AmbilSendiri: true, Kirim: false },
    Pembayaran: { BayarSaatAmbil: true, Cod: false, QrisOnline: false },
    MinimalPesanan: '0.00',
    PesanTutup: null,
    Menu: {
        Kategori: [],
        Produk: [
            {
                Uuid: '01JPRODUK0000000000000001',
                Nama: 'Kopi Susu Gula Aren',
                Harga: '25000.00',
                UrlGambar: null,
                UuidKategori: null,
                KelompokPilihan: [],
            },
        ],
    },
    Akun: { Aktif: false, Pelanggan: null, AlamatTerakhir: null },
};

function Hasil(diskon: string, voucher: { Kode: string; NamaPromo: string } | null) {
    return {
        Subtotal: '25000.00',
        Diskon: diskon,
        BiayaLayanan: '0.00',
        Pajak: [],
        Ongkir: '0.00',
        DiskonOngkir: '0.00',
        Total: (25000 - Number(diskon)).toFixed(2),
        Zona: null,
        Voucher: voucher,
    };
}

function Json(isi: unknown, status = 200): Response {
    return new Response(JSON.stringify(isi), { status, headers: { 'Content-Type': 'application/json' } });
}

function KodeDikirim(panggilan: unknown[] | undefined): unknown {
    const badan = (panggilan?.[1] as { body: string }).body;
    return (JSON.parse(badan) as { KodeVoucher: string | null }).KodeVoucher;
}

beforeEach(() => AturHalamanUji({}, '/kopi-senja'));
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('voucher di checkout toko online', () => {
    it('kode dikirim ke server dalam huruf besar, potongan & nama promo tampil dari hasil server, lalu bisa dilepas', async () => {
        const TiruanFetch = vi.fn((_url: string, init: { body: string }) => {
            const kode = (JSON.parse(init.body) as { KodeVoucher: string | null }).KodeVoucher;
            return Promise.resolve(
                Json(
                    kode ? Hasil('5000.00', { Kode: kode, NamaPromo: 'Voucher hemat Rp 5.000' }) : Hasil('0.00', null),
                ),
            );
        });
        vi.stubGlobal('fetch', TiruanFetch);
        RenderUji(<HalamanTokoOnline {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Tambah' }));
        await waitFor(() => expect(TiruanFetch).toHaveBeenCalledTimes(1));
        expect(KodeDikirim(TiruanFetch.mock.calls[0])).toBeNull();

        fireEvent.change(screen.getByLabelText(/Kode voucher/), { target: { value: ' hemat5k ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Pakai' }));
        expect(await screen.findByText('Voucher HEMAT5K dipakai: Voucher hemat Rp 5.000')).toBeTruthy();
        expect(KodeDikirim(TiruanFetch.mock.calls[1])).toBe('HEMAT5K');
        expect(screen.getByText('Diskon promo')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Lepas' }));
        await waitFor(() => expect(screen.queryByText(/Voucher HEMAT5K dipakai/)).toBeNull());
        expect(KodeDikirim(TiruanFetch.mock.calls[2])).toBeNull();
        expect((screen.getByLabelText(/Kode voucher/) as HTMLInputElement).value).toBe('');
    });

    it('kode ditolak server: pesan masalah di bidang voucher dan keranjang dihitung ulang tanpa voucher', async () => {
        const TiruanFetch = vi.fn((_url: string, init: { body: string }) => {
            const kode = (JSON.parse(init.body) as { KodeVoucher: string | null }).KodeVoucher;
            return Promise.resolve(
                kode
                    ? Json({ Galat: { Kode: 'VoucherTidakDikenal', Pesan: 'Kode voucher tidak dikenal.' } }, 422)
                    : Json(Hasil('0.00', null)),
            );
        });
        vi.stubGlobal('fetch', TiruanFetch);
        RenderUji(<HalamanTokoOnline {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Tambah' }));
        await waitFor(() => expect(TiruanFetch).toHaveBeenCalledTimes(1));
        fireEvent.change(screen.getByLabelText(/Kode voucher/), { target: { value: 'SALAH' } });
        fireEvent.click(screen.getByRole('button', { name: 'Pakai' }));

        expect(await screen.findByText('Kode voucher tidak dikenal.')).toBeTruthy();
        await waitFor(() => expect(TiruanFetch).toHaveBeenCalledTimes(3));
        expect(KodeDikirim(TiruanFetch.mock.calls[2])).toBeNull();
        expect(screen.getByRole('button', { name: 'Pakai' })).toBeTruthy();
    });
});
