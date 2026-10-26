import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import Beranda from '@/Halaman/Pengelola/Beranda';
import type { DasborBisnisPengelola, PropsBerandaPengelola, PropsBersamaPengelola } from '@/Tipe/Pengelola';

let propsHalaman: PropsBersamaPengelola;

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...sisa }: { href: string; children: ReactNode }) => (
        <a href={href} {...sisa}>
            {children}
        </a>
    ),
    router: { post: vi.fn() },
    usePage: () => ({ props: propsHalaman, url: '/' }),
}));

function Props(izin: string[]): PropsBersamaPengelola {
    return {
        NamaAplikasi: 'Payoung',
        Lingkungan: 'Produksi',
        Kilat: null,
        Pengguna: { Uuid: '01J', Nama: 'Dewi Anggraini', Email: 'dewi@contoh.id', KodePeran: [], Izin: izin },
        PeringatanSuperAdmin: false,
        PeringatanIntegrasi: [],
        PeringatanOperasional: [],
        errors: {},
    };
}

const Penuh: PropsBerandaPengelola['Ringkasan'] = {
    Tenant: {
        Total: 42,
        Baru7Hari: 5,
        Trial: 12,
        Berbayar: 28,
        Tertunggak: 2,
        Ditangguhkan: 1,
        Berisiko: 3,
        TugasTerbuka: 4,
        PenghapusanDataMenunggu: 0,
    },
    Tagihan: { JatuhTempo: 3, NilaiJatuhTempo: '1450000.00', MenungguVerifikasi: 2, Terbit: 7, LunasBulanIni: 19 },
    Dukungan: { Terbuka: 9, Baru: 2, LewatSla: 1 },
    Operasional: { PenjadwalSehat: true, AntreanSehat: true, BackupSehat: false, AlertAktif: 1, InsidenAktif: 0 },
    Perhatian: [
        {
            Judul: '1 tiket melewati batas SLA',
            Keterangan: 'Tangani lebih dulu agar tidak makin terlambat.',
            Tautan: '/dukungan/tiket',
            Tingkat: 'bahaya',
        },
        {
            Judul: '3 tagihan lewat jatuh tempo',
            Keterangan: 'Total Rp 1.450.000 belum dibayar.',
            Tautan: '/tagihan',
            Tingkat: 'peringatan',
        },
    ],
};

const Bisnis: DasborBisnisPengelola = {
    Bisnis: {
        Mrr: '48200000.00',
        Arr: '578400000.00',
        PelangganBerbayar: 126,
        PerubahanMrrPersen: '8.4',
        Churn: {
            PersenChurn: '2.10',
            PelangganBerhenti: 3,
            PelangganBaru: 11,
            MrrBaru: '2100000.00',
            MrrBerhenti: '600000.00',
        },
        MrrPerPaket: [
            { Kunci: '1', Nama: 'Pro', Pelanggan: 80, Mrr: '24800000.00' },
            { Kunci: '2', Nama: 'Bisnis', Pelanggan: 30, Mrr: '16100000.00' },
        ],
        Tren: Array.from({ length: 12 }, (_, i) => ({
            Bulan: `2026-${String(i + 1).padStart(2, '0')}`,
            Mrr: String((i + 1) * 4_000_000) + '.00',
            Pelanggan: 10 + i,
        })),
        LewatTempo: { Jumlah: 3, Total: '1450000.00' },
    },
    TenantPerSektor: [
        { Nama: 'Retail & minimarket', Jumlah: 48 },
        { Nama: 'F&B', Jumlah: 41 },
    ],
    TenantRisiko: [
        { Uuid: '01JABC', Nama: 'Toko Sembako Rukun Makmur', Paket: 'Pro', StatusLangganan: 'Aktif', Skor: 18 },
    ],
    Konversi: { Kohort: 37, Berbayar: 14, Persen: '37.8', UjiCobaBerjalan: 9 },
    Aktivitas: [{ Waktu: '2026-10-25T02:12:00Z', Teks: 'Tenant baru: Kedai Rasa Nusantara', Tautan: '/tenant/01JXYZ' }],
};

describe('Beranda konsol', () => {
    afterEach(() => {
        cleanup();
        window.localStorage.clear();
    });

    it('D-207 pusat komando: MRR dan tren, konversi, churn, sektor, tenant berisiko, dan aktivitas tampil dengan tautan', () => {
        propsHalaman = Props(['tenant.lihat', 'tagihan.lihat']);
        render(<Beranda Ringkasan={Penuh} Bisnis={Bisnis} />);

        expect(screen.getByText('MRR').nextElementSibling?.textContent).toBe('Rp 48,2 jt');
        expect(screen.getByText('MRR').parentElement?.textContent).toContain('+8,4% dari bulan lalu');
        expect(screen.getByText('Konversi uji coba').nextElementSibling?.textContent).toBe('37,8%');
        expect(screen.getByText('Churn bulan ini').nextElementSibling?.textContent).toBe('2,10%');
        expect(screen.getByRole('img', { name: /Tren MRR 12 bulan: Jan Rp 4 jt/ })).toBeTruthy();
        expect(screen.getByText('Pro (80)')).toBeTruthy();
        expect(screen.getByText('Retail & minimarket')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Toko Sembako Rukun Makmur' }).getAttribute('href')).toBe(
            '/tenant/01JABC',
        );
        expect(screen.getByText('Skor 18')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Tenant baru: Kedai Rasa Nusantara' }).getAttribute('href')).toBe(
            '/tenant/01JXYZ',
        );
    });

    it('D-207 tanpa data bisnis (tanpa izin tagihan) kartu MRR dan paket tidak tampil', () => {
        propsHalaman = Props(['tenant.lihat']);
        render(<Beranda Ringkasan={Penuh} Bisnis={{ ...Bisnis, Bisnis: null, TenantRisiko: null }} />);

        expect(screen.queryByText('MRR')).toBeNull();
        expect(screen.queryByText('Pendapatan per paket')).toBeNull();
        expect(screen.queryByText('Tenant berisiko')).toBeNull();
        expect(screen.getByText('Tenant per sektor')).toBeTruthy();
    });

    it('D-207 Atur widget menyembunyikan widget dan diingat di peramban ini', () => {
        propsHalaman = Props(['tenant.lihat', 'tagihan.lihat']);
        const { unmount: Lepas } = render(<Beranda Ringkasan={Penuh} Bisnis={Bisnis} />);

        fireEvent.click(screen.getByRole('button', { name: 'Atur widget' }));
        const dialog = within(screen.getByRole('dialog'));
        fireEvent.click(dialog.getByRole('checkbox', { name: 'Tenant per sektor' }));
        fireEvent.click(dialog.getByRole('button', { name: 'Selesai' }));
        expect(screen.queryByText('Tenant per sektor')).toBeNull();

        Lepas();
        render(<Beranda Ringkasan={Penuh} Bisnis={Bisnis} />);
        expect(screen.queryByText('Tenant per sektor')).toBeNull();
        expect(screen.getByText('Pendapatan per paket')).toBeTruthy();
    });

    it('menampilkan butir perhatian bertaut dan ringkasan tiap bidang', () => {
        propsHalaman = Props(['tenant.lihat', 'tagihan.lihat']);
        render(<Beranda Ringkasan={Penuh} />);

        expect(screen.getByRole('link', { name: /1 tiket melewati batas SLA/ }).getAttribute('href')).toBe(
            '/dukungan/tiket',
        );
        expect(screen.getByRole('link', { name: /3 tagihan lewat jatuh tempo/ }).getAttribute('href')).toBe('/tagihan');
        // Angka utama: tenant berbayar dan tagihan lewat tempo (ringkas), kondisi sistem per bagian.
        expect(screen.getByText('Tenant berbayar').nextElementSibling?.textContent).toBe('28');
        expect(screen.getByText('Tagihan lewat tempo').nextElementSibling?.textContent).toBe('Rp 1,5 jt');
        expect(screen.getAllByText('Bermasalah')).toHaveLength(2);
        expect(screen.getAllByText('Sehat')).toHaveLength(2);
    });

    it('semua terkendali bila tidak ada yang perlu tindakan', () => {
        propsHalaman = Props(['tenant.lihat']);
        render(<Beranda Ringkasan={{ ...Penuh, Perhatian: [] }} />);

        expect(screen.getByText(/Semua terkendali/)).toBeTruthy();
    });

    it('bagian tanpa izin tidak tampil, tanpa satu pun bagian muncul pesan hubungi Super Admin', () => {
        propsHalaman = Props([]);
        render(
            <Beranda Ringkasan={{ Tenant: null, Tagihan: null, Dukungan: null, Operasional: null, Perhatian: [] }} />,
        );

        expect(screen.queryByText('Tagihan lewat tempo')).toBeNull();
        expect(screen.getByText(/Belum ada ringkasan untuk peran Anda/)).toBeTruthy();
    });
});
