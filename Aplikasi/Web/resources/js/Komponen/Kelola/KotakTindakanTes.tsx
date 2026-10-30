import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanKotakTindakan from '@/Halaman/Kelola/Tindakan';
import type { ButirTindakan } from '@/Tipe/Tindakan';

/*
 * D-23 C Kotak Tindakan: halaman kotak (rincian terbuka untuk butir Penting, pilih lalu "Tandai sudah dicek" mengirim
 * Jenis + Uuid) dan keadaan kosong "Semua beres". Ringkasan di Beranda sudah dihapus (D-247).
 */

const uji = vi.hoisted(() => ({ kiriman: [] as { url: string; data: unknown }[] }));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...sisa }: { href: string; children?: ReactNode }) => (
        <a href={href} {...sisa}>
            {children}
        </a>
    ),
    router: {
        post: (url: string, data: unknown) => uji.kiriman.push({ url, data }),
        put: (url: string, data: unknown) => uji.kiriman.push({ url, data }),
    },
    usePage: () => ({ props: { errors: {} }, url: '/kelola/tindakan' }),
}));

vi.mock('@/TataLetak/TataLetakAplikasi', () => ({
    default: ({ judul, children }: { judul: string; children: ReactNode }) => (
        <main>
            <h1>{judul}</h1>
            {children}
        </main>
    ),
}));

function BuatButir(
    kunci: string,
    tingkat: ButirTindakan['Tingkat'],
    tambahan: Partial<ButirTindakan> = {},
): ButirTindakan {
    return {
        Kunci: kunci,
        Modul: 'Penjualan',
        Tingkat: tingkat,
        Judul: `Butir ${kunci}`,
        Keterangan: 'Keterangan butir.',
        Jumlah: 1,
        Tautan: `/kelola/${kunci}`,
        LabelTautan: 'Buka',
        JenisDokumen: null,
        BolehTandai: false,
        Rincian: [],
        ...tambahan,
    };
}

const penjualan = BuatButir('penjualan.tinjauan', 'Penting', {
    Judul: 'Penjualan offline perlu dicek',
    Jumlah: 3,
    JenisDokumen: 'Penjualan',
    BolehTandai: true,
    Rincian: [
        {
            Uuid: 'U1',
            Judul: 'KSR1-0001',
            Keterangan: 'PelangganTidakDikenal',
            Tanggal: null,
            Tautan: '/kelola/penjualan/U1',
        },
        { Uuid: 'U2', Judul: 'KSR1-0002', Keterangan: null, Tanggal: null, Tautan: null },
    ],
});

describe('Kotak Tindakan (D-23 C)', () => {
    beforeEach(() => {
        uji.kiriman = [];
    });
    afterEach(() => cleanup());

    it('butir Penting terbuka. Pilih rincian lalu tandai sudah dicek mengirim Jenis & Uuid', () => {
        render(
            <HalamanKotakTindakan Butir={[penjualan, BuatButir('stok.kritis', 'Perhatian')]} Izin={{ Tandai: true }} />,
        );

        expect(screen.getByText('Menampilkan 2 terbaru dari 3.', { exact: false })).toBeTruthy();
        const tombol = screen.getByRole('button', { name: 'Tandai sudah dicek' });
        expect((tombol as HTMLButtonElement).disabled).toBe(true);

        fireEvent.click(screen.getByRole('checkbox', { name: 'Pilih KSR1-0002' }));
        fireEvent.click(screen.getByRole('button', { name: 'Tandai sudah dicek (1)' }));
        expect(uji.kiriman).toEqual([{ url: '/kelola/tindakan/tinjau', data: { Jenis: 'Penjualan', Uuid: ['U2'] } }]);

        fireEvent.click(screen.getByRole('checkbox', { name: 'Pilih semua yang tampil' }));
        expect(screen.getByRole('button', { name: 'Tandai sudah dicek (2)' })).toBeTruthy();
    });

    it('butir yang tidak bisa ditandai tidak punya kotak centang. Kosong = "Semua beres"', () => {
        render(
            <HalamanKotakTindakan
                Butir={[{ ...penjualan, BolehTandai: false, Tingkat: 'Perhatian' }]}
                Izin={{ Tandai: false }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Lihat rincian (2)' }));
        expect(screen.getByRole('link', { name: 'KSR1-0001' })).toBeTruthy();
        expect(screen.queryByRole('checkbox')).toBeNull();
        cleanup();

        render(<HalamanKotakTindakan Butir={[]} Izin={{ Tandai: false }} />);
        expect(screen.getByText('Semua beres')).toBeTruthy();
    });

    it('D-23 D: ringkasan pagi lewat WhatsApp bisa dimatikan. Akun tanpa nomor HP tidak bisa mengaktifkan', () => {
        render(
            <HalamanKotakTindakan
                Butir={[]}
                Izin={{ Tandai: false }}
                RingkasanWhatsapp={{ BisaWhatsapp: true, Aktif: true }}
            />,
        );
        const saklar = screen.getByRole('switch', { name: 'Kirim ringkasan ke WhatsApp saya setiap pagi' });
        expect(saklar.getAttribute('aria-checked')).toBe('true');
        fireEvent.click(saklar);
        expect(uji.kiriman).toEqual([{ url: '/kelola/tindakan/ringkasan-whatsapp', data: { Aktif: false } }]);
        cleanup();

        render(
            <HalamanKotakTindakan
                Butir={[]}
                Izin={{ Tandai: false }}
                RingkasanWhatsapp={{ BisaWhatsapp: false, Aktif: false }}
            />,
        );
        expect(
            (screen.getByRole('switch', { name: 'Kirim ringkasan ke WhatsApp saya setiap pagi' }) as HTMLButtonElement)
                .disabled,
        ).toBe(true);
        expect(screen.getByText(/belum punya nomor HP/)).toBeTruthy();
    });
});
