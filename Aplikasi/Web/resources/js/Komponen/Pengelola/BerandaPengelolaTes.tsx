import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import Beranda from '@/Halaman/Pengelola/Beranda';
import type { PropsBerandaPengelola, PropsBersamaPengelola } from '@/Tipe/Pengelola';

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

describe('Beranda konsol', () => {
    afterEach(() => cleanup());

    it('menampilkan butir perhatian bertaut dan ringkasan tiap bidang', () => {
        propsHalaman = Props(['tenant.lihat', 'tagihan.lihat']);
        render(<Beranda Ringkasan={Penuh} />);

        expect(screen.getByRole('link', { name: /1 tiket melewati batas SLA/ }).getAttribute('href')).toBe(
            '/dukungan/tiket',
        );
        expect(screen.getByRole('link', { name: /3 tagihan lewat jatuh tempo/ }).getAttribute('href')).toBe('/tagihan');
        expect(screen.getByText('42')).toBeTruthy();
        expect(screen.getByText('Rp 1.450.000')).toBeTruthy();
        expect(screen.getByText('Bermasalah')).toBeTruthy();
        expect(screen.getAllByText('Sehat')).toHaveLength(2);
    });

    it('semua terkendali bila tidak ada yang perlu tindakan', () => {
        propsHalaman = Props(['tenant.lihat']);
        render(<Beranda Ringkasan={{ ...Penuh, Perhatian: [] }} />);

        expect(screen.getByText(/Semua terkendali/)).toBeTruthy();
    });

    it('bagian tanpa izin tidak tampil; tanpa satu pun bagian muncul pesan hubungi Super Admin', () => {
        propsHalaman = Props([]);
        render(
            <Beranda Ringkasan={{ Tenant: null, Tagihan: null, Dukungan: null, Operasional: null, Perhatian: [] }} />,
        );

        expect(screen.queryByText('Tagihan langganan')).toBeNull();
        expect(screen.getByText(/Belum ada ringkasan untuk peran Anda/)).toBeTruthy();
    });
});
