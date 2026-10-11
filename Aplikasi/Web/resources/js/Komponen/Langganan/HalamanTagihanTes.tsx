import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanTagihanLangganan from '@/Halaman/Kelola/Langganan/Tagihan';
import { AturHalamanUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import type { TagihanLangganan } from '@/Tipe/TagihanLangganan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const tagihan: TagihanLangganan = {
    Uuid: 'TG-1',
    Nomor: 'INV-2026-0001',
    Jenis: 'Aktivasi',
    LabelJenis: 'Aktivasi',
    Status: 'Terbit',
    LabelStatus: 'Menunggu pembayaran',
    KodePaket: 'PRO',
    NamaPaket: 'Pro',
    Siklus: 'Bulanan',
    JumlahBulan: 1,
    Subtotal: '200000.00',
    SubtotalPaket: '200000.00',
    RincianAddon: [],
    NaikPaket: null,
    KodeKupon: null,
    Diskon: '0.00',
    TarifPpn: '12.000000',
    PengaliDppPembilang: 11,
    PengaliDppPenyebut: 12,
    DasarPengenaanPajak: '183333.00',
    JumlahPpn: '22000.00',
    Total: '222000.00',
    TerbitPada: '2026-09-20T02:00:00Z',
    JatuhTempoPada: '2026-09-27T02:00:00Z',
    DibayarPada: null,
    DibatalkanPada: null,
    PeriodeMulai: null,
    PeriodeSelesai: null,
};

function RenderTagihan(tambahan: Partial<PropsRender> = {}) {
    return render(
        <HalamanTagihanLangganan
            Tagihan={tambahan.Tagihan ?? tagihan}
            Pembayaran={[]}
            BolehBayarOnline={tambahan.BolehBayarOnline ?? false}
            BolehBatalkan={tambahan.BolehBatalkan ?? true}
        />,
    );
}

type PropsRender = {
    BolehBayarOnline: boolean;
    BolehBatalkan: boolean;
    Tagihan: TagihanLangganan;
};

describe('Langganan/Tagihan (P-08): bayar online & konfirmasi batal', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/langganan/tagihan/TG-1'));
    afterEach(() => cleanup());

    it('batalkan tagihan hanya setelah dikonfirmasi', () => {
        RenderTagihan();

        fireEvent.click(screen.getByRole('button', { name: 'Batalkan tagihan' }));
        expect(screen.getByRole('alertdialog', { name: 'Batalkan tagihan INV-2026-0001?' })).toBeTruthy();
        expect(tiruanRouter.post).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Jangan batalkan' }));
        expect(screen.queryByRole('alertdialog')).toBeNull();
        expect(tiruanRouter.post).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Batalkan tagihan' }));
        fireEvent.click(screen.getByRole('button', { name: 'Batalkan tagihan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/langganan/tagihan/TG-1/batalkan',
            {},
            expect.anything(),
        );
    });

    it('D-98: tagihan naik paket menjelaskan paket asal, paket baru, dan prorata harinya', () => {
        RenderTagihan({
            Tagihan: {
                ...tagihan,
                Jenis: 'NaikPaket',
                LabelJenis: 'Naik paket',
                NamaPaket: 'Bisnis',
                SubtotalPaket: '66667.00',
                Subtotal: '66667.00',
                NaikPaket: { NamaPaketAsal: 'Pro', HariDitagih: 10, HariPeriode: 30 },
            },
        });

        expect(screen.getByText(/Naik paket Pro ke Bisnis/)).toBeTruthy();
        expect(screen.getByText(/prorata 10 dari 30 hari periode berjalan/)).toBeTruthy();
    });

    it('tanpa izin batalkan: tidak ada tombol batalkan', () => {
        RenderTagihan({ BolehBatalkan: false });

        expect(screen.queryByRole('button', { name: 'Batalkan tagihan' })).toBeNull();
    });

    it('BR-P08.11: tombol bayar online hanya muncul saat gerbang billing DOKU aktif (BolehBayarOnline)', () => {
        RenderTagihan({ BolehBayarOnline: true });

        expect(screen.getByRole('button', { name: 'Bayar online' })).toBeTruthy();
    });

    it('BR-P08.11: gerbang belum dikonfigurasi atau tagihan tidak terbuka = tidak ada tombol bayar online', () => {
        RenderTagihan({ BolehBayarOnline: false });

        expect(screen.queryByRole('button', { name: 'Bayar online' })).toBeNull();
    });

    it('BR-P08.11: Bayar online meminta transaksi ke server lalu mengarahkan peramban ke halaman bayar DOKU', async () => {
        const Alihkan = vi.fn();
        vi.stubGlobal('location', { ...window.location, assign: Alihkan });
        const Ambil = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ UrlBayar: 'https://sandbox.doku.com/checkout/link/abc' }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }),
        );
        vi.stubGlobal('fetch', Ambil);
        RenderTagihan({ BolehBayarOnline: true });

        fireEvent.click(screen.getByRole('button', { name: 'Bayar online' }));

        await waitFor(() => expect(Alihkan).toHaveBeenCalledWith('https://sandbox.doku.com/checkout/link/abc'));
        expect(Ambil).toHaveBeenCalledWith(
            '/kelola/langganan/tagihan/TG-1/bayar-online',
            expect.objectContaining({ method: 'POST' }),
        );
        vi.unstubAllGlobals();
    });

    it('BR-P08.11: gerbang menolak = pesan galat tampil dan tombol bisa dicoba lagi (tanpa pengalihan)', async () => {
        const Alihkan = vi.fn();
        vi.stubGlobal('location', { ...window.location, assign: Alihkan });
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                new Response(
                    JSON.stringify({ Galat: { Kode: 'GerbangMenolak', Pesan: 'Gerbang menolak permintaan.' } }),
                    {
                        status: 422,
                        headers: { 'Content-Type': 'application/json' },
                    },
                ),
            ),
        );
        RenderTagihan({ BolehBayarOnline: true });

        fireEvent.click(screen.getByRole('button', { name: 'Bayar online' }));

        expect(await screen.findByText('Gerbang menolak permintaan.')).toBeTruthy();
        expect(Alihkan).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Bayar online' }).hasAttribute('disabled')).toBe(false);
        vi.unstubAllGlobals();
    });
});
