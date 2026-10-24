import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, within } from '@testing-library/react';
import type { ReactElement, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import HalamanAnalitikPlatform from '@/Halaman/Pengelola/Analitik/Tampil';
import { PilihTab } from '@/Pengujian/InteraksiRadix';
import type { PropsAnalitikPlatform } from '@/Tipe/AnalitikPlatform';
import { IzinPengelola, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

/*
 * P-07 PGL-24 halaman analitik platform: funnel (tahap + per sektor), adopsi fitur per sektor, dan retensi kohort,
 * semuanya angka agregat dengan persen berformat Indonesia dan bulan yang belum tiba bertanda "-".
 */

const uji = vi.hoisted(() => ({
    halaman: { props: {} as Record<string, unknown>, url: '/analitik' },
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...sisa }: { href: string; children?: ReactNode }) => (
        <a href={href} {...sisa}>
            {children}
        </a>
    ),
    router: { get: vi.fn(), post: vi.fn(), visit: vi.fn(), reload: vi.fn() },
    usePage: () => uji.halaman,
}));

function AturHalaman() {
    const props: PropsBersamaPengelola = {
        NamaAplikasi: 'Kasir',
        Lingkungan: 'Staging',
        Kilat: null,
        Pengguna: {
            Uuid: 'P1',
            Nama: 'Ani Analis',
            Email: 'ani@contoh.id',
            KodePeran: [],
            Izin: [IzinPengelola.AnalitikLihat],
        },
        PeringatanSuperAdmin: false,
        PeringatanIntegrasi: [],
        PeringatanOperasional: [],
        errors: {},
    };
    uji.halaman.props = props;
}

function Render(elemen: ReactElement) {
    return render(
        <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
            {elemen}
        </QueryClientProvider>,
    );
}

const bulan = Array.from({ length: 12 }, (_, i) => `M+${String(i)}`);

function Props(ubah: Partial<PropsAnalitikPlatform> = {}): PropsAnalitikPlatform {
    return {
        Saring: { Dari: '', Sampai: '', Sektor: '' },
        PilihanSektor: [{ Nilai: 'FNB-CAF', Label: 'Kafe' }],
        Funnel: {
            Tahap: [
                {
                    Kunci: 'Daftar',
                    Label: 'Mendaftar',
                    Jumlah: 40,
                    PersenDaftar: '100.0',
                    PersenSebelumnya: null,
                    RataRataHari: null,
                },
                {
                    Kunci: 'Panduan',
                    Label: 'Panduan awal selesai',
                    Jumlah: 30,
                    PersenDaftar: '75.0',
                    PersenSebelumnya: '75.0',
                    RataRataHari: '1.5',
                },
                {
                    Kunci: 'Transaksi',
                    Label: 'Transaksi pertama',
                    Jumlah: 20,
                    PersenDaftar: '50.0',
                    PersenSebelumnya: '66.7',
                    RataRataHari: '2.7',
                },
                {
                    Kunci: 'Bayar',
                    Label: 'Langganan berbayar pertama',
                    Jumlah: 8,
                    PersenDaftar: '20.0',
                    PersenSebelumnya: '40.0',
                    RataRataHari: '12.0',
                },
            ],
            PerSektor: [
                {
                    Kunci: 'FNB-CAF',
                    Sektor: 'Kafe',
                    Daftar: 25,
                    Panduan: 20,
                    Transaksi: 15,
                    Bayar: 6,
                    PersenBayar: '24.0',
                },
                {
                    Kunci: '-',
                    Sektor: 'Belum memilih sektor',
                    Daftar: 15,
                    Panduan: 10,
                    Transaksi: 5,
                    Bayar: 2,
                    PersenBayar: '13.3',
                },
            ],
        },
        Adopsi: {
            Fitur: [
                { Kunci: 'Opname', Label: 'Stok opname' },
                { Kunci: 'Promo', Label: 'Promo' },
            ],
            Baris: [
                {
                    Kunci: '*',
                    Sektor: 'Semua sektor',
                    TenantAktif: 10,
                    Fitur: { Opname: { Jumlah: 4, Persen: '40.0' }, Promo: { Jumlah: 3, Persen: '30.0' } },
                },
                {
                    Kunci: 'FNB-CAF',
                    Sektor: 'Kafe',
                    TenantAktif: 6,
                    Fitur: { Opname: { Jumlah: 3, Persen: '50.0' }, Promo: { Jumlah: 3, Persen: '50.0' } },
                },
            ],
        },
        Retensi: {
            Bulan: bulan,
            Baris: [
                {
                    Kunci: '2026-09',
                    Label: 'September 2026',
                    Ukuran: 4,
                    Retensi: [
                        { Persen: '100.0', Aktif: 4 },
                        { Persen: '50.0', Aktif: 2 },
                        ...Array<null>(10).fill(null),
                    ],
                },
                {
                    Kunci: '2026-10',
                    Label: 'Oktober 2026',
                    Ukuran: 0,
                    Retensi: [{ Persen: '0.0', Aktif: 0 }, ...Array<null>(11).fill(null)],
                },
            ],
        },
        HariFitur: 30,
        ...ubah,
    };
}

afterEach(cleanup);

describe('halaman analitik platform (PGL-24)', () => {
    it('funnel: angka ringkas, persen berkoma Indonesia, tahap pertama tanpa persen sebelumnya, tabel per sektor', () => {
        AturHalaman();
        Render(<HalamanAnalitikPlatform {...Props()} />);

        expect(screen.getByText('Waktu ke berbayar')).toBeTruthy();
        expect(screen.getAllByText('12,0 hari').length).toBeGreaterThan(0);
        const tahap = screen.getByRole('table', { name: 'Tahap funnel' });
        const baris = within(tahap).getAllByRole('row');
        expect(within(baris[1] as HTMLElement).getByText('100,0%')).toBeTruthy();
        expect(within(baris[1] as HTMLElement).getAllByText('-').length).toBeGreaterThan(0);
        expect(within(tahap).getByText('66,7%')).toBeTruthy();

        const sektor = screen.getByRole('table', { name: 'Funnel per sektor' });
        expect(within(sektor).getByText('Belum memilih sektor')).toBeTruthy();
        expect(within(sektor).getByText('13,3%')).toBeTruthy();
    });

    it('adopsi fitur: persen dengan jumlah tenant dalam kurung, baris Semua sektor', () => {
        AturHalaman();
        Render(<HalamanAnalitikPlatform {...Props()} />);
        PilihTab(screen.getByRole('tab', { name: 'Adopsi fitur' }));

        const tabel = screen.getByRole('table', { name: 'Adopsi fitur per sektor' });
        expect(within(tabel).getByText('Semua sektor')).toBeTruthy();
        expect(within(tabel).getByText('40,0% (4)')).toBeTruthy();
        expect(within(tabel).getAllByText('50,0% (3)').length).toBe(2);
        expect(screen.getByText(/30 hari terakhir/)).toBeTruthy();
    });

    it('retensi kohort: M+0 sampai M+11, bulan belum tiba dan kohort kosong bertanda "-"', () => {
        AturHalaman();
        Render(<HalamanAnalitikPlatform {...Props()} />);
        PilihTab(screen.getByRole('tab', { name: 'Retensi kohort' }));

        const tabel = screen.getByRole('table', { name: 'Retensi kohort bulanan' });
        expect(within(tabel).getByRole('columnheader', { name: /M\+11/ })).toBeTruthy();
        const baris = within(tabel).getAllByRole('row');
        const september = within(baris[1] as HTMLElement);
        expect(september.getByText('September 2026')).toBeTruthy();
        expect(september.getByText('100,0%')).toBeTruthy();
        expect(september.getByText('50,0%')).toBeTruthy();
        // Kohort tanpa tenant tidak menampilkan 0,0% yang menyesatkan.
        expect(within(baris[2] as HTMLElement).queryByText('0,0%')).toBeNull();
    });

    it('tanpa data: funnel nol dan keadaan kosong tampil, tidak ada masalah', () => {
        AturHalaman();
        Render(
            <HalamanAnalitikPlatform
                {...Props({
                    Funnel: {
                        Tahap: [
                            {
                                Kunci: 'Daftar',
                                Label: 'Mendaftar',
                                Jumlah: 0,
                                PersenDaftar: '0.0',
                                PersenSebelumnya: null,
                                RataRataHari: null,
                            },
                        ],
                        PerSektor: [],
                    },
                })}
            />,
        );

        expect(screen.getByText('Belum ada tenant pada periode ini.')).toBeTruthy();
    });
});
