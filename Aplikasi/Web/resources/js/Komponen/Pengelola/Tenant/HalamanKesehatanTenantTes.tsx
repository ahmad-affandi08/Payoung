import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactElement, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import HalamanTugasTenant from '@/Halaman/Pengelola/Tenant/Tugas';
import PanelKesehatanTenant from '@/Komponen/Pengelola/Tenant/PanelKesehatanTenant';
import { BuatHasilTabel } from '@/Komponen/Persediaan/DataUjiPersediaan';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import { IzinPengelola, type PropsBersamaPengelola } from '@/Tipe/Pengelola';
import type { BarisTugasTenant, KesehatanTenant } from '@/Tipe/TenantPengelola';

/*
 * P-07 PGL-09: panel skor kesehatan di tampilan 360° dan halaman tugas otomatis. Status selalu bertulisan; tombol
 * menangani hanya untuk pemegang tenant.catatan.tulis.
 */

const uji = vi.hoisted(() => ({
    kiriman: [] as { url: string; data: unknown }[],
    halaman: { props: {} as Record<string, unknown>, url: '/tugas-tenant' },
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...sisa }: { href: string; children?: ReactNode }) => (
        <a href={href} {...sisa}>
            {children}
        </a>
    ),
    router: {
        get: vi.fn(),
        post: vi.fn((url: string, data: unknown) => uji.kiriman.push({ url, data })),
        visit: vi.fn(),
        reload: vi.fn(),
    },
    usePage: () => uji.halaman,
}));

function AturHalaman(izin: string[]) {
    const props: PropsBersamaPengelola = {
        NamaAplikasi: 'Kasir',
        Lingkungan: 'Staging',
        Kilat: null,
        Pengguna: { Uuid: 'P1', Nama: 'Dewi Dukungan', Email: 'dewi@contoh.id', KodePeran: [], Izin: izin },
        PeringatanSuperAdmin: false,
        PeringatanIntegrasi: [],
        PeringatanOperasional: [],
        errors: {},
    };
    uji.halaman.props = props;
    uji.kiriman.length = 0;
}

function Render(elemen: ReactElement) {
    return render(
        <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
            {elemen}
        </QueryClientProvider>,
    );
}

const tugas: BarisTugasTenant = {
    Uuid: '01JTUGAS000000000000000A1',
    UuidTenant: '01JTENANT00000000000000A1',
    NamaTenant: 'Kedai Kopi Senja',
    Slug: 'kopi-senja',
    Jenis: 'OnboardingMacet',
    LabelJenis: 'Bantuan onboarding',
    Ringkasan: 'Belum bertransaksi 3 hari setelah mendaftar. Hubungi untuk bantuan onboarding.',
    Status: 'Terbuka',
    DibuatPada: '2026-10-20T03:00:00Z',
    SelesaiPada: null,
    CaraSelesai: null,
    CatatanSelesai: null,
    Skor: 25,
    Kategori: 'PerluPerhatian',
    LabelKategori: 'Perlu perhatian',
};

const sebaran = { Tanggal: '2026-10-20', Sehat: 12, PerluPerhatian: 4, Berisiko: 2, TugasTerbuka: 1 };
const jenis = [
    { Nilai: 'OnboardingMacet', Label: 'Bantuan onboarding' },
    { Nilai: 'Berisiko', Label: 'Tenant berisiko' },
];

afterEach(cleanup);

describe('halaman tugas tenant (PGL-09)', () => {
    it('menampilkan sebaran kategori, tugas dengan status bertulisan, dan tautan ke tenant', () => {
        AturHalaman([IzinPengelola.TenantLihat]);
        Render(<HalamanTugasTenant Tugas={BuatHasilTabel([tugas])} Sebaran={sebaran} PilihanJenis={jenis} />);

        const ringkasan = screen.getByRole('region', { name: 'Sebaran kesehatan tenant' });
        expect(within(ringkasan).getByText('12')).toBeTruthy();
        expect(within(ringkasan).getByText('Berisiko')).toBeTruthy();
        expect(screen.getAllByRole('link', { name: 'Kedai Kopi Senja' })[0]?.getAttribute('href')).toBe(
            '/tenant/01JTENANT00000000000000A1',
        );
        expect(screen.getAllByText('Terbuka').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Perlu perhatian | 25').length).toBeGreaterThan(0);
        expect(screen.queryByRole('button', { name: /Aksi tugas/ })).toBeNull();
    });

    it('tanda sudah dihubungi: catatan wajib dikirim ke alamat selesai tugas', () => {
        AturHalaman([IzinPengelola.TenantLihat, IzinPengelola.TenantCatatanTulis]);
        Render(<HalamanTugasTenant Tugas={BuatHasilTabel([tugas])} Sebaran={sebaran} PilihanJenis={jenis} />);

        BukaMenu(screen.getAllByRole('button', { name: /Aksi Bantuan onboarding Kedai Kopi Senja/ })[0] as HTMLElement);
        fireEvent.click(screen.getByRole('menuitem', { name: 'Tandai sudah dihubungi' }));
        fireEvent.change(screen.getByLabelText(/Catatan/), { target: { value: 'Dihubungi lewat WhatsApp.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Tandai sudah dihubungi' }));
        expect(uji.kiriman.at(-1)).toMatchObject({
            url: '/tugas-tenant/01JTUGAS000000000000000A1/selesai',
            data: { Catatan: 'Dihubungi lewat WhatsApp.' },
        });
    });

    it('tugas yang sudah selesai tanpa aksi; belum pernah dihitung diberi penjelasan', () => {
        AturHalaman([IzinPengelola.TenantLihat, IzinPengelola.TenantCatatanTulis]);
        Render(
            <HalamanTugasTenant
                Tugas={BuatHasilTabel([
                    { ...tugas, Status: 'Selesai', CaraSelesai: 'Manual', CatatanSelesai: 'Sudah dibantu.' },
                ])}
                Sebaran={{ ...sebaran, Tanggal: null }}
                PilihanJenis={jenis}
            />,
        );
        expect(screen.getByText('Sudah dihubungi')).toBeTruthy();
        expect(screen.getByText('Sudah dibantu.')).toBeTruthy();
        expect(screen.getByText(/Skor belum pernah dihitung/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: /Aksi Bantuan onboarding/ })).toBeNull();
    });
});

describe('panel skor kesehatan di tampilan 360°', () => {
    const kesehatan: KesehatanTenant = {
        Terbaru: {
            Tanggal: '2026-10-20',
            Skor: 15,
            Kategori: 'Berisiko',
            LabelKategori: 'Berisiko',
            Faktor: [
                { Kunci: 'HariAktif', Nilai: 0, Poin: 0, Maks: 40 },
                { Kunci: 'PerangkatLamaOffline', Nilai: 2, Poin: 4, Maks: 10 },
                { Kunci: 'Tertunggak', Nilai: true, Poin: 0, Maks: 10 },
            ],
        },
        Riwayat: [
            { Tanggal: '2026-10-20', Skor: 15, Kategori: 'Berisiko' },
            { Tanggal: '2026-10-19', Skor: 20, Kategori: 'Berisiko' },
        ],
        Tugas: [
            {
                Uuid: 'T1',
                Jenis: 'Berisiko',
                LabelJenis: 'Tenant berisiko',
                Ringkasan: 'Skor kesehatan 15 (berisiko).',
                DibuatPada: '2026-10-20T03:00:00Z',
            },
        ],
    };

    it('memuat skor, kategori bertulisan, rincian faktor dalam kalimat, riwayat, dan tugas terbuka', () => {
        render(<PanelKesehatanTenant kesehatan={kesehatan} />);
        expect(screen.getByText('15')).toBeTruthy();
        expect(screen.getByText('Berisiko')).toBeTruthy();
        const rincian = screen.getByRole('list', { name: 'Rincian skor' });
        expect(within(rincian).getByText('0 dari 14 hari terakhir ada transaksi')).toBeTruthy();
        expect(within(rincian).getByText('2 perangkat offline lebih dari 7 hari')).toBeTruthy();
        expect(within(rincian).getByText('Langganan tertunggak')).toBeTruthy();
        expect(screen.getByText(/Riwayat \(terbaru dulu\): 15, 20/)).toBeTruthy();
        expect(screen.getByText('Skor kesehatan 15 (berisiko).')).toBeTruthy();
    });

    it('belum dinilai: penjelasan tanpa angka', () => {
        render(<PanelKesehatanTenant kesehatan={{ Terbaru: null, Riwayat: [], Tugas: [] }} />);
        expect(screen.getByText(/Belum dinilai/)).toBeTruthy();
    });
});
