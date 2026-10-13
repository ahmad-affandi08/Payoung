import { cleanup, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import HalamanStatusPlatform from '@/Halaman/Situs/StatusPlatform';
import type { DataSitus } from '@/Tipe/Situs';
import type { StatusPlatformData } from '@/Tipe/StatusPlatform';

const situs = {
    NamaSitus: 'Payoung',
    Slogan: null,
    Logo: null,
    Menu: [{ Label: 'Harga', Tautan: '/harga' }],
    MenuKaki: [],
    TeksKaki: null,
    Kontak: { WhatsApp: null, TautanWhatsApp: null, Email: null, Telepon: null, Alamat: null, JamLayanan: null },
    MediaSosial: {},
    Pengumuman: null,
    TautanUnduh: {},
    TombolDaftar: { Label: 'Coba gratis', Tautan: 'https://dashboard.payoung.id/daftar' },
    TombolMasuk: { Label: 'Masuk', Tautan: 'https://dashboard.payoung.id/masuk' },
    WhatsAppMelayang: false,
    Tahun: 2026,
} satisfies DataSitus;

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...sisa }: { href: string; children: ReactNode }) => (
        <a href={href} {...sisa}>
            {children}
        </a>
    ),
    usePage: () => ({ props: { Situs: situs }, url: '/status' }),
}));

const kasir = {
    Kode: 'Kasir',
    Nama: 'Aplikasi Kasir & sinkron data',
    Kondisi: 'Normal' as const,
    LabelKondisi: 'Normal',
};
const pembayaran = {
    Kode: 'Pembayaran',
    Nama: 'Pembayaran (QRIS & langganan)',
    Kondisi: 'Normal' as const,
    LabelKondisi: 'Normal',
};
const komponen = [kasir, pembayaran];

const normal: StatusPlatformData = {
    DiperbaruiPada: '2026-10-14T03:00:00Z',
    Keseluruhan: 'Normal',
    LabelKeseluruhan: 'Semua layanan berjalan normal',
    Komponen: komponen,
    Aktif: [],
    Riwayat: [],
};

const gangguan: StatusPlatformData = {
    ...normal,
    Keseluruhan: 'Besar',
    LabelKeseluruhan: 'Ada gangguan besar pada layanan',
    Komponen: [{ ...kasir, Kondisi: 'Besar', LabelKondisi: 'Gangguan besar' }, pembayaran],
    Aktif: [
        {
            Uuid: '01JINSIDEN00000000000000A',
            Judul: 'Sinkron kasir tertunda',
            Tingkat: 'Besar',
            Status: 'Dipantau',
            LabelStatus: 'Dipantau',
            Dampak: 'Transaksi terkirim lebih lambat dari biasanya.',
            Komponen: ['Aplikasi Kasir & sinkron data'],
            MulaiPada: '2026-10-14T03:00:00Z',
            SelesaiPada: null,
            PostMortem: null,
            Pembaruan: [
                {
                    Status: 'Dipantau',
                    LabelStatus: 'Dipantau',
                    Isi: 'Perbaikan terpasang.',
                    Waktu: '2026-10-14T04:00:00Z',
                },
                {
                    Status: 'Diselidiki',
                    LabelStatus: 'Sedang diselidiki',
                    Isi: 'Antrean menumpuk.',
                    Waktu: '2026-10-14T03:00:00Z',
                },
            ],
        },
    ],
    Riwayat: [
        {
            Uuid: '01JINSIDEN00000000000000B',
            Judul: 'Pembayaran QRIS gagal',
            Tingkat: 'Kritis',
            Status: 'Selesai',
            LabelStatus: 'Selesai',
            Dampak: 'QRIS tidak bisa dibayar.',
            Komponen: ['Pembayaran (QRIS & langganan)'],
            MulaiPada: '2026-10-10T03:00:00Z',
            SelesaiPada: '2026-10-10T05:00:00Z',
            PostMortem: 'Penyebab: sertifikat gerbang kedaluwarsa.',
            Pembaruan: [{ Status: 'Selesai', LabelStatus: 'Selesai', Isi: 'Pulih.', Waktu: '2026-10-10T05:00:00Z' }],
        },
    ],
};

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('halaman status layanan publik (PGL-21)', () => {
    it('normal: banner bertulis, setiap komponen punya label teks, tidak ada gangguan', () => {
        render(<HalamanStatusPlatform Status={normal} />);
        expect(
            within(screen.getByRole('status', { name: 'Kondisi keseluruhan' })).getByText(
                'Semua layanan berjalan normal',
            ),
        ).toBeTruthy();
        const daftar = screen.getByRole('region', { name: 'Komponen layanan' });
        expect(within(daftar).getAllByText('Normal')).toHaveLength(2);
        expect(screen.getByText('Tidak ada gangguan saat ini.')).toBeTruthy();
        expect(screen.getByText('Tidak ada gangguan dalam 30 hari terakhir.')).toBeTruthy();
    });

    it('gangguan: insiden aktif dengan kronologi, komponen terdampak bertulis, riwayat dengan post-mortem', () => {
        render(<HalamanStatusPlatform Status={gangguan} />);
        expect(screen.getByText('Ada gangguan besar pada layanan')).toBeTruthy();
        expect(
            within(screen.getByRole('region', { name: 'Komponen layanan' })).getByText('Gangguan besar'),
        ).toBeTruthy();
        const kronologi = screen.getByRole('list', { name: 'Kronologi Sinkron kasir tertunda' });
        expect(within(kronologi).getAllByRole('listitem')).toHaveLength(2);
        expect(kronologi.textContent).toContain('Perbaikan terpasang.');
        expect(screen.getByText('Pembayaran QRIS gagal')).toBeTruthy();
        expect(screen.getByText('Post-mortem')).toBeTruthy();
        expect(screen.getByText('Penyebab: sertifikat gerbang kedaluwarsa.')).toBeTruthy();
    });

    it('teks insiden ditampilkan sebagai teks biasa, bukan HTML', () => {
        const berbahaya: StatusPlatformData = {
            ...gangguan,
            Aktif: gangguan.Aktif.map((i) => ({ ...i, Dampak: '<img src=x onerror=alert(1)> dampak' })),
        };
        const { container } = render(<HalamanStatusPlatform Status={berbahaya} />);
        expect(container.querySelector('img[src="x"]')).toBeNull();
        expect(screen.getByText(/<img src=x onerror=alert\(1\)> dampak/)).toBeTruthy();
    });
});
