import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import HalamanJadwal, { type BarisJadwal, type PropsJadwal } from '@/Halaman/Pengelola/Operasional/Jadwal';
import type { PropsBersamaPengelola } from '@/Tipe/Pengelola';

/* D-209: halaman Jadwal & penyimpanan di konsol Operasional. */

const uji = vi.hoisted(() => ({
    kiriman: [] as { url: string; data: unknown }[],
    halaman: { props: {} as Record<string, unknown>, url: '/operasional/jadwal' },
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

function AturHalaman() {
    const props: PropsBersamaPengelola = {
        NamaAplikasi: 'Payoung',
        Lingkungan: 'Produksi',
        Kilat: null,
        Pengguna: {
            Uuid: 'P1',
            Nama: 'Teguh Teknis',
            Email: 'teguh@contoh.id',
            KodePeran: [],
            Izin: ['operasional.lihat'],
        },
        PeringatanSuperAdmin: false,
        PeringatanIntegrasi: [],
        PeringatanOperasional: [],
        errors: {},
    };
    uji.halaman.props = props;
    uji.kiriman.length = 0;
}

const gagal: BarisJadwal = {
    Perintah: 'tenant:periksa-silang',
    Ekspresi: '15 3 * * 1',
    Zona: 'Asia/Jakarta',
    Berikutnya: '2026-11-02T20:15:00Z',
    Status: 'Gagal',
    Terakhir: {
        MulaiPada: '2026-10-26T20:15:00Z',
        SelesaiPada: '2026-10-26T20:15:04Z',
        Berhasil: false,
        DurasiMs: 4000,
        Keterangan: 'Koneksi basis data terputus.',
        Sumber: 'Jadwal',
    },
    Riwayat: [],
};

const baru: BarisJadwal = {
    Perintah: 'unduhan:bersihkan',
    Ekspresi: '35 3 * * *',
    Zona: 'UTC',
    Berikutnya: '2026-10-26T03:35:00Z',
    Status: 'BelumPernah',
    Terakhir: null,
    Riwayat: [],
};

function Props(bolehKelola: boolean): PropsJadwal {
    return {
        Jadwal: { Ringkasan: { Total: 2, Gagal: 1, Berjalan: 0, BelumPernah: 1 }, Data: [gagal, baru] },
        Penyimpanan: {
            TotalByte: 7_340_032,
            JumlahTenant: 2,
            Terbesar: [{ Uuid: '01JTENANT', Nama: 'Toko Foto Besar', Byte: 5_242_880, BatasMb: 10, Persen: 50 }],
        },
        BolehKelola: bolehKelola,
    };
}

function Render(props: PropsJadwal) {
    return render(
        <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
            <HalamanJadwal {...props} />
        </QueryClientProvider>,
    );
}

describe('Jadwal & penyimpanan (D-209)', () => {
    afterEach(() => cleanup());

    it('menampilkan status, alasan gagal, dan tenant penyimpanan terbesar dengan tautan', () => {
        AturHalaman();
        Render(Props(false));

        expect(screen.getByText('tenant:periksa-silang')).toBeTruthy();
        expect(screen.getByText('Gagal')).toBeTruthy();
        expect(screen.getByText('Belum pernah jalan')).toBeTruthy();
        expect(screen.getByText('Koneksi basis data terputus.')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Toko Foto Besar' }).getAttribute('href')).toBe('/tenant/01JTENANT');
        expect(screen.getByText(/dari 10 MB \(50%\)/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Jalankan sekarang' })).toBeNull();
    });

    it('tombol Jalankan sekarang mengirim nama perintah bagi yang berizin kelola', () => {
        AturHalaman();
        Render(Props(true));

        fireEvent.click(screen.getAllByRole('button', { name: 'Jalankan sekarang' }).at(0) as HTMLElement);

        expect(uji.kiriman).toEqual([
            { url: '/operasional/jadwal/jalankan', data: { Perintah: 'tenant:periksa-silang' } },
        ]);
    });
});
