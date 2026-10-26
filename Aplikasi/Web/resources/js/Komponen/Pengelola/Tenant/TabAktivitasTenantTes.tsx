import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import type { ReactElement, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { TabPerangkatTenant, TabTagihanTenant, TabTiketTenant } from '@/Komponen/Pengelola/Tenant/TabAktivitasTenant';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...sisa }: { href: string; children?: ReactNode }) => (
        <a href={href} {...sisa}>
            {children}
        </a>
    ),
    router: { get: vi.fn(), post: vi.fn(), visit: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: {}, url: '/tenant/T1' }),
}));

function Render(elemen: ReactElement) {
    return render(
        <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
            {elemen}
        </QueryClientProvider>,
    );
}

afterEach(cleanup);

describe('tab aktivitas tenant di tampilan 360°', () => {
    it('tagihan: ringkasan jumlah menunggu bayar dalam Rupiah, nomor tertaut ke tagihan, status bertulisan', () => {
        Render(
            <TabTagihanTenant
                tagihan={{
                    JumlahTerbuka: 1,
                    TotalTerbuka: '1110000.00',
                    Data: [
                        {
                            Uuid: 'TG1',
                            Nomor: 'INV/2026/000123',
                            Jenis: 'Aktivasi',
                            LabelJenis: 'Aktivasi langganan',
                            Status: 'Terbit',
                            LabelStatus: 'Belum dibayar',
                            Total: '1110000.00',
                            TerbitPada: '2026-10-20T03:00:00Z',
                            JatuhTempoPada: '2026-10-27T03:00:00Z',
                            DibayarPada: null,
                        },
                    ],
                }}
            />,
        );
        expect(screen.getByText(/1 tagihan menunggu pembayaran, total Rp 1.110.000/)).toBeTruthy();
        expect(screen.getAllByRole('link', { name: 'INV/2026/000123' })[0]?.getAttribute('href')).toBe('/tagihan/TG1');
        expect(screen.getAllByText('Belum dibayar').length).toBeGreaterThan(0);
    });

    it('tiket: penanda lewat SLA bertulisan dan tautan ke tiket, tanpa tiket diberi penjelasan', () => {
        Render(
            <TabTiketTenant
                tiket={{
                    JumlahTerbuka: 1,
                    JumlahLewatSla: 1,
                    Data: [
                        {
                            Uuid: 'TK1',
                            Nomor: 'TKT-0001',
                            Judul: 'Printer struk tidak mencetak',
                            Status: 'Baru',
                            LabelStatus: 'Baru',
                            Prioritas: 'Tinggi',
                            LewatSla: true,
                            DibuatPada: '2026-10-20T03:00:00Z',
                        },
                    ],
                }}
            />,
        );
        expect(screen.getByText(/1 tiket terbuka, 1 lewat batas SLA/)).toBeTruthy();
        expect(screen.getAllByText('Lewat SLA').length).toBeGreaterThan(0);
        expect(screen.getAllByRole('link', { name: 'TKT-0001' })[0]?.getAttribute('href')).toBe('/dukungan/tiket/TK1');
        cleanup();
        Render(<TabTiketTenant tiket={{ JumlahTerbuka: 0, JumlahLewatSla: 0, Data: [] }} />);
        expect(screen.getByText('Tidak ada tiket terbuka.')).toBeTruthy();
        expect(screen.getByText('Tenant ini belum pernah membuat tiket.')).toBeTruthy();
    });

    it('perangkat: platform dan versi, outbox, status Lama offline / Dicabut / Aktif selalu bertulisan', () => {
        const dasar = {
            Jenis: 'Kasir',
            Outlet: 'Outlet Utama',
            Platform: 'Android',
            VersiAplikasi: '1.0.0',
            DiaktifkanPada: '2026-10-01T03:00:00Z',
            TerakhirAktifPada: '2026-10-20T02:00:00Z',
            OutboxTertunda: 0,
            Dicabut: false,
            LamaOffline: false,
        };
        Render(
            <TabPerangkatTenant
                perangkat={{
                    JumlahAktif: 2,
                    JumlahOffline: 1,
                    TotalOutboxTertunda: 40,
                    Data: [
                        { ...dasar, Uuid: 'P1', Nama: 'Kasir Depan', Kode: 'KSR-1' },
                        {
                            ...dasar,
                            Uuid: 'P2',
                            Nama: 'Kasir Lama',
                            Kode: 'KSR-2',
                            OutboxTertunda: 40,
                            LamaOffline: true,
                        },
                        { ...dasar, Uuid: 'P3', Nama: 'Kasir Rusak', Kode: 'KSR-3', Dicabut: true },
                    ],
                }}
            />,
        );
        expect(
            screen.getByText(/2 perangkat aktif \| 1 lama offline \(lebih dari 7 hari\) \| 40 item outbox tertunda/),
        ).toBeTruthy();
        expect(screen.getAllByText('Android 1.0.0').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Lama offline').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Dicabut').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Aktif').length).toBeGreaterThan(0);
    });
});
