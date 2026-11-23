import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactElement } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarTagihan from '@/Halaman/Pengelola/Tagihan/Daftar';
import { BuatHasilTabel } from '@/Komponen/Katalog/DataUjiKatalog';
import type { PengaturanPenagihan } from '@/Komponen/Langganan/PengaturanPenagihanPengelola';

/*
 * P-08 konsol: halaman Tagihan langganan memisahkan kerja harian (tab Tagihan) dari pengaturan (tab Pengaturan, satu
 * panel berisi baris-baris seragam). Antrean verifikasi hanya tampil bila ada isinya.
 */

const uji = vi.hoisted(() => ({ props: {} as Record<string, unknown>, url: '/tagihan' }));

vi.mock('@inertiajs/react', async () => {
    const tiruan = (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia;

    return { ...tiruan, usePage: () => ({ props: uji.props, url: uji.url }) };
});

const pengaturan: PengaturanPenagihan = {
    Boleh: true,
    PlatformPkp: true,
    PkpDariKonsol: false,
    Rekening: [],
    RekeningDariKonsol: false,
    LamaLangganan: {
        MinimalBulan: 1,
        MaksimalBulan: 12,
        BatasTertinggiBulan: 36,
        MaksimalBulanManual: 36,
        TanggaDiskon: [{ DariBulan: 3, Persen: '7' }],
    },
    LamaLanggananDariKonsol: false,
    SaldoWhatsapp: { HargaPerPesan: '0.00', TopUpMinimal: '10000.00' },
};

const antrean = {
    Uuid: 'PB-1',
    DiunggahPada: '2026-11-23T03:00:00Z',
    NamaTenant: 'Toko Pengguji Payoung',
    UuidTagihan: 'TG-1',
    NomorTagihan: 'INV/2026/11/000009',
    TotalTagihan: '165389.00',
    NamaPaket: 'Pro',
    Jumlah: '165389.00',
    TanggalTransfer: '2026-11-23',
    BankPengirim: 'BCA',
    BankTujuan: 'BRI',
};

function Render(elemen: ReactElement) {
    return render(
        <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
            {elemen}
        </QueryClientProvider>,
    );
}

function PasangHalaman(timpa: { Antrean?: unknown[] } = {}) {
    return Render(
        <HalamanDaftarTagihan
            Antrean={(timpa.Antrean ?? []) as never}
            Tagihan={BuatHasilTabel([])}
            Ringkasan={{ MenungguVerifikasi: timpa.Antrean?.length ?? 0, BelumDibayar: 0 }}
            OpsiStatus={[]}
            BolehSetujuiRefund
            BatasRefund="1000000.00"
            PengaturanPenagihan={pengaturan}
        />,
    );
}

beforeEach(() => {
    uji.props = {
        NamaAplikasi: 'Payoung',
        Lingkungan: 'Staging',
        Kilat: null,
        Pengguna: { Uuid: 'P1', Nama: 'Fandi', Email: 'fandi@contoh.id', KodePeran: [], Izin: [] },
        PeringatanSuperAdmin: false,
        PeringatanIntegrasi: [],
        PeringatanOperasional: [],
        errors: {},
    };
});
afterEach(() => cleanup());

describe('konsol Tagihan langganan', () => {
    it('terbuka di tab Tagihan: ringkasan dan Semua tagihan tampil, pengaturan tidak', () => {
        PasangHalaman();

        expect(screen.getByRole('tab', { name: 'Tagihan', selected: true })).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Semua tagihan' })).toBeTruthy();
        expect(screen.getByText('bukti transfer menunggu verifikasi')).toBeTruthy();
        expect(screen.queryByText('Pengaturan penagihan')).toBeNull();
    });

    it('antrean verifikasi kosong tidak memakan tempat. Ada isinya, tabelnya tampil', () => {
        const { unmount: Lepas } = PasangHalaman();
        expect(screen.queryByRole('heading', { name: 'Menunggu verifikasi' })).toBeNull();
        expect(screen.queryByText(/Antrean kosong/)).toBeNull();
        Lepas();

        PasangHalaman({ Antrean: [antrean] });
        expect(screen.getByRole('heading', { name: 'Menunggu verifikasi' })).toBeTruthy();
        expect(screen.getAllByText('INV/2026/11/000009').length).toBeGreaterThan(0);
    });

    it('tab Pengaturan: satu panel berisi semua baris pengaturan dengan tombol Ubah di tiap baris', () => {
        PasangHalaman();
        fireEvent.mouseDown(screen.getByRole('tab', { name: 'Pengaturan' }), { button: 0 });
        fireEvent.click(screen.getByRole('tab', { name: 'Pengaturan' }));

        const panel = screen.getByRole('region', { name: 'Pengaturan penagihan' });
        for (const judul of [
            'Batas refund tanpa persetujuan kedua',
            'Status PKP platform',
            'Rekening tujuan transfer',
            'Lama langganan',
            'Saldo WhatsApp tenant',
        ]) {
            expect(within(panel).getByRole('heading', { name: judul })).toBeTruthy();
        }

        for (const tombol of [
            'Ubah batas',
            'Ubah status PKP',
            'Ubah rekening',
            'Ubah lama langganan',
            'Ubah harga dan minimal isi',
        ]) {
            expect(within(panel).getByRole('button', { name: tombol })).toBeTruthy();
        }

        // Nilai awal server ditandai di tiga baris yang belum pernah disimpan dari konsol (PKP, rekening, lama langganan).
        expect(within(panel).getAllByText('Nilai awal server')).toHaveLength(3);
        expect(
            within(panel).getByText(
                'Belum ada rekening tujuan. Tagihan manual belum bisa dibayar sampai rekening diisi.',
            ),
        ).toBeTruthy();
    });

    it('peran yang hanya boleh melihat tidak mendapat tombol Ubah', () => {
        Render(
            <HalamanDaftarTagihan
                Antrean={[]}
                Tagihan={BuatHasilTabel([])}
                Ringkasan={{ MenungguVerifikasi: 0, BelumDibayar: 0 }}
                OpsiStatus={[]}
                BatasRefund="1000000.00"
                PengaturanPenagihan={{ ...pengaturan, Boleh: false }}
            />,
        );
        fireEvent.mouseDown(screen.getByRole('tab', { name: 'Pengaturan' }), { button: 0 });
        fireEvent.click(screen.getByRole('tab', { name: 'Pengaturan' }));

        const panel = screen.getByRole('region', { name: 'Pengaturan penagihan' });
        expect(within(panel).queryAllByRole('button', { name: /^Ubah/ })).toHaveLength(0);
    });
});
