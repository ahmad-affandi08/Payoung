import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import type { ReactElement, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import HalamanAnalitikSitus from '@/Halaman/Pengelola/AnalitikSitus/Tampil';
import { PilihTab } from '@/Pengujian/InteraksiRadix';
import type { LaporanGa, PropsAnalitikSitus } from '@/Tipe/AnalitikGoogle';
import { IzinPengelola, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

/*
 * D-215: halaman Analitik situs (Google Analytics 4). Google tidak dihubungi: `fetch` ke `/analitik/situs/data` ditiru.
 * Yang dijaga: keadaan belum terhubung, angka berformat Indonesia dengan pembanding periode, tabel generik dari
 * definisi kolom server, laporan lazy per tab, dan pesan Google yang ditolak tampil dengan tombol Coba lagi.
 */

const uji = vi.hoisted(() => ({
    halaman: { props: {} as Record<string, unknown>, url: '/analitik/situs' },
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

function Props(ubah: Partial<PropsAnalitikSitus> = {}): PropsAnalitikSitus {
    return {
        Status: { Aktif: true, IdProperti: '412345678' },
        Rentang: { Dari: '2026-09-28', Sampai: '2026-10-25', Hari: 28 },
        MaksHari: 365,
        ...ubah,
    };
}

const rentang = { Dari: '2026-09-28', Sampai: '2026-10-25', Hari: 28 };
const pembanding = { Dari: '2026-08-31', Sampai: '2026-09-27', Hari: 28 };

const ringkasan: LaporanGa = {
    Laporan: 'ringkasan',
    Rentang: rentang,
    Pembanding: pembanding,
    Kartu: [
        { Kunci: 'PenggunaAktif', Label: 'Pengguna aktif', Jenis: 'Bilangan', Nilai: '12345', Pembanding: '10000' },
        {
            Kunci: 'TingkatKeterlibatan',
            Label: 'Tingkat keterlibatan',
            Jenis: 'Persen',
            Nilai: '0.6667',
            Pembanding: '0.8',
        },
        {
            Kunci: 'RataRataDurasiSesi',
            Label: 'Rata-rata durasi sesi',
            Jenis: 'Durasi',
            Nilai: '95.5',
            Pembanding: '0',
        },
    ],
    Tabel: [
        {
            Id: 'harian',
            Judul: 'Tren harian',
            Kolom: [
                { Kunci: 'Tanggal', Label: 'Tanggal', Jenis: 'Teks' },
                { Kunci: 'PenggunaAktif', Label: 'Pengguna aktif', Jenis: 'Bilangan' },
                { Kunci: 'Sesi', Label: 'Sesi', Jenis: 'Bilangan' },
                { Kunci: 'TampilanHalaman', Label: 'Tampilan halaman', Jenis: 'Bilangan' },
                { Kunci: 'EventKunci', Label: 'Event kunci', Jenis: 'Bilangan' },
            ],
            Baris: [
                {
                    Tanggal: '2026-10-24',
                    PenggunaAktif: '1500',
                    Sesi: '1800',
                    TampilanHalaman: '4200',
                    EventKunci: '3',
                },
                { Tanggal: '2026-10-25', PenggunaAktif: '900', Sesi: '1000', TampilanHalaman: '2500', EventKunci: '1' },
            ],
        },
    ],
};

const akuisisi: LaporanGa = {
    Laporan: 'akuisisi',
    Rentang: rentang,
    Pembanding: pembanding,
    Kartu: [],
    Tabel: [
        {
            Id: 'saluran',
            Judul: 'Saluran akuisisi',
            Kolom: [
                { Kunci: 'Saluran', Label: 'Saluran', Jenis: 'Teks' },
                { Kunci: 'Sesi', Label: 'Sesi', Jenis: 'Bilangan' },
                { Kunci: 'TingkatKeterlibatan', Label: 'Tingkat keterlibatan', Jenis: 'Persen' },
            ],
            Baris: [
                { Saluran: 'Organic Search', Sesi: '2400', TingkatKeterlibatan: '0.55' },
                { Saluran: 'Direct', Sesi: '12000', TingkatKeterlibatan: '0.7' },
            ],
        },
    ],
};

function TiruFetch(jawaban: Record<string, unknown>) {
    const Ambil = vi.fn().mockImplementation((url: string) => {
        const laporan = new URL(url, 'http://localhost').searchParams.get('laporan') ?? '';

        return Promise.resolve(
            new Response(JSON.stringify(jawaban[laporan] ?? { Galat: 'Tidak dikenal.' }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }),
        );
    });
    vi.stubGlobal('fetch', Ambil);

    return Ambil;
}

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('HalamanAnalitikSitus (D-215)', () => {
    it('belum terhubung: tidak mengambil data dan mengarahkan ke Integrasi', () => {
        AturHalaman();
        const Ambil = TiruFetch({});
        Render(<HalamanAnalitikSitus {...Props({ Status: { Aktif: false, IdProperti: null } })} />);

        expect(screen.getByText('Google Analytics belum dihubungkan.')).toBeTruthy();
        expect(screen.getByRole('link', { name: /Integrasi › Google Analytics/ }).getAttribute('href')).toBe(
            '/integrasi',
        );
        expect(screen.queryByRole('tab', { name: 'Ringkasan' })).toBeNull();
        expect(Ambil).not.toHaveBeenCalled();
    });

    it('tab dua pilihan (Platform dan Situs) dengan Situs aktif', () => {
        AturHalaman();
        TiruFetch({ ringkasan });
        Render(<HalamanAnalitikSitus {...Props()} />);

        const nav = screen.getByRole('navigation', { name: 'Analitik' });

        expect(within(nav).getByRole('link', { name: 'Situs (Google Analytics)' }).getAttribute('aria-current')).toBe(
            'page',
        );
        expect(within(nav).getByRole('link', { name: 'Platform' }).getAttribute('aria-current')).toBeNull();
    });

    it('ringkasan: angka berformat Indonesia dengan perubahan dari periode sebelumnya', async () => {
        AturHalaman();
        const Ambil = TiruFetch({ ringkasan });
        Render(<HalamanAnalitikSitus {...Props()} />);

        expect(await screen.findByText('12.345')).toBeTruthy();
        expect(screen.getByText('+23,5%')).toBeTruthy();
        expect(screen.getByText(/dari 10\.000 sebelumnya/)).toBeTruthy();
        expect(screen.getByText('66,7%')).toBeTruthy();
        expect(screen.getByText('-16,7%')).toBeTruthy();
        expect(screen.getByText('1m 36d')).toBeTruthy();
        // Tanpa dasar pembanding (periode sebelumnya 0) tidak ada persen yang menyesatkan.
        expect(screen.getByText('Baru')).toBeTruthy();
        expect(screen.getByRole('table', { name: 'Tren harian' })).toBeTruthy();

        const url = String(Ambil.mock.calls[0]?.[0]);
        expect(url).toContain('/analitik/situs/data?');
        expect(url).toContain('laporan=ringkasan');
        expect(url).toContain('dari=2026-09-28');
        expect(url).toContain('sampai=2026-10-25');
    });

    it('laporan lain diambil saat tabnya dibuka, bukan sejak awal', async () => {
        AturHalaman();
        const Ambil = TiruFetch({ ringkasan, akuisisi });
        Render(<HalamanAnalitikSitus {...Props()} />);

        await screen.findByText('12.345');
        expect(Ambil).toHaveBeenCalledTimes(1);

        PilihTab(screen.getByRole('tab', { name: 'Akuisisi' }));

        const tabel = await screen.findByRole('table', { name: 'Saluran akuisisi' });
        const baris = within(tabel).getAllByRole('row');

        expect(Ambil).toHaveBeenCalledTimes(2);
        // Urut bawaan = kolom angka pertama menurun: Direct (12.000) di atas Organic Search (2.400).
        expect(within(baris[1] as HTMLElement).getByText('Direct')).toBeTruthy();
        expect(within(baris[1] as HTMLElement).getByText('12.000')).toBeTruthy();
        expect(within(baris[1] as HTMLElement).getByText('70%')).toBeTruthy();
        expect(within(baris[2] as HTMLElement).getByText('55%')).toBeTruthy();
    });

    it('Google menolak: pesan tampil apa adanya dengan tombol Coba lagi yang mengambil ulang', async () => {
        AturHalaman();
        const jawaban: Record<string, unknown> = {
            ringkasan: {
                Galat: 'Google menolak akses ke properti GA4 412345678. Tambahkan akun layanan sebagai Viewer.',
            },
        };
        const Ambil = TiruFetch(jawaban);
        Render(<HalamanAnalitikSitus {...Props()} />);

        expect(await screen.findByText(/Tambahkan akun layanan sebagai Viewer/)).toBeTruthy();

        jawaban.ringkasan = ringkasan;
        screen.getByRole('button', { name: 'Coba lagi' }).click();

        await waitFor(() => expect(screen.getByText('12.345')).toBeTruthy());

        const url = String(Ambil.mock.calls[1]?.[0]);

        expect(url).toContain('segarkan=1');
    });
});
