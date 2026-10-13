import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactElement, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import HalamanInsiden, { StatusBerikut } from '@/Halaman/Pengelola/Operasional/Insiden';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import { IzinPengelola, type BarisInsiden, type PropsBersamaPengelola, type PropsInsiden } from '@/Tipe/Pengelola';

/*
 * P-11 PGL-21 halaman konsol insiden: daftar insiden, kronologi, aksi sesuai status dan izin, serta pilihan status
 * yang selaras dengan siklus di server (hanya maju; Selesai final).
 */

const uji = vi.hoisted(() => ({
    kiriman: [] as { url: string; data: unknown }[],
    halaman: { props: {} as Record<string, unknown>, url: '/operasional/insiden' },
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
        Pengguna: { Uuid: 'P1', Nama: 'Teguh Teknis', Email: 'teguh@contoh.id', KodePeran: [], Izin: izin },
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

const aktif: BarisInsiden = {
    Uuid: '01JINSIDEN00000000000000A',
    Judul: 'Sinkron kasir tertunda',
    Tingkat: 'Besar',
    Status: 'Diselidiki',
    LabelStatus: 'Sedang diselidiki',
    Dampak: 'Transaksi terkirim lebih lambat dari biasanya.',
    Komponen: ['Kasir'],
    LabelKomponen: ['Aplikasi Kasir & sinkron data'],
    PersenTenantTerdampak: 12,
    MulaiPada: '2026-10-14T03:00:00Z',
    DiumumkanPada: '2026-10-14T03:00:00Z',
    SelesaiPada: null,
    PostMortem: null,
    PostMortemDiterbitkanPada: null,
    DibuatOleh: 'Teguh Teknis',
    Pembaruan: [
        {
            Status: 'Diselidiki',
            LabelStatus: 'Sedang diselidiki',
            Isi: 'Antrean sinkron menumpuk sejak pukul 10.00.',
            Waktu: '2026-10-14T03:00:00Z',
            Oleh: 'Teguh Teknis',
        },
    ],
};

const selesai: BarisInsiden = {
    ...aktif,
    Uuid: '01JINSIDEN00000000000000B',
    Judul: 'Pembayaran QRIS gagal',
    Status: 'Selesai',
    LabelStatus: 'Selesai',
    DiumumkanPada: null,
    SelesaiPada: '2026-10-14T05:00:00Z',
};

function Props(insiden: BarisInsiden[]): PropsInsiden {
    return {
        Insiden: insiden,
        OpsiTingkat: [
            { Nilai: 'Kecil', Label: 'Kecil' },
            { Nilai: 'Besar', Label: 'Besar' },
            { Nilai: 'Kritis', Label: 'Kritis' },
        ],
        OpsiStatus: [
            { Nilai: 'Diselidiki', Label: 'Sedang diselidiki' },
            { Nilai: 'Selesai', Label: 'Selesai' },
        ],
        OpsiKomponen: [
            { Nilai: 'Kasir', Label: 'Aplikasi Kasir & sinkron data' },
            { Nilai: 'Pembayaran', Label: 'Pembayaran (QRIS & langganan)' },
        ],
        AmbangWajibUmumPersen: 10,
    };
}

afterEach(cleanup);

describe('konsol insiden (PGL-21)', () => {
    it('pilihan status mengikuti siklus server: hanya maju, Dipantau boleh mundur, Selesai final', () => {
        expect(StatusBerikut('Diselidiki')).toEqual(['Diselidiki', 'Teridentifikasi', 'Dipantau', 'Selesai']);
        expect(StatusBerikut('Dipantau')).toEqual(['Dipantau', 'Teridentifikasi', 'Selesai']);
        expect(StatusBerikut('Selesai')).toEqual([]);
    });

    it('Teknis: menu insiden berjalan memuat pembaruan; insiden selesai tanpa pembaruan; kronologi bisa dibuka', () => {
        AturHalaman([IzinPengelola.OperasionalLihat, IzinPengelola.OperasionalKelola]);
        Render(<HalamanInsiden {...Props([aktif, selesai])} />);

        BukaMenu(screen.getAllByRole('button', { name: /Aksi Sinkron kasir tertunda/ })[0] as HTMLElement);
        expect(screen.getByRole('menuitem', { name: 'Tambah pembaruan' })).toBeTruthy();
        expect(screen.queryByRole('menuitem', { name: 'Umumkan ke halaman status' })).toBeNull();
        fireEvent.click(screen.getByRole('menuitem', { name: 'Lihat kronologi' }));
        expect(screen.getByRole('list', { name: 'Kronologi insiden' }).textContent).toContain(
            'Antrean sinkron menumpuk sejak pukul 10.00.',
        );
        cleanup();

        AturHalaman([IzinPengelola.OperasionalLihat, IzinPengelola.OperasionalKelola]);
        Render(<HalamanInsiden {...Props([selesai])} />);
        BukaMenu(screen.getAllByRole('button', { name: /Aksi Pembayaran QRIS gagal/ })[0] as HTMLElement);
        expect(screen.queryByRole('menuitem', { name: 'Tambah pembaruan' })).toBeNull();
        expect(screen.getByRole('menuitem', { name: 'Umumkan ke halaman status' })).toBeTruthy();
        expect(screen.getByRole('menuitem', { name: 'Tulis post-mortem' })).toBeTruthy();
    });

    it('pembaruan mengirim status dan isi ke alamat pembaruan insiden', () => {
        AturHalaman([IzinPengelola.OperasionalLihat, IzinPengelola.OperasionalKelola]);
        Render(<HalamanInsiden {...Props([aktif])} />);
        BukaMenu(screen.getAllByRole('button', { name: /Aksi Sinkron kasir tertunda/ })[0] as HTMLElement);
        fireEvent.click(screen.getByRole('menuitem', { name: 'Tambah pembaruan' }));
        fireEvent.change(screen.getByLabelText(/Isi pembaruan/), {
            target: { value: 'Penyebab ditemukan: antrean macet.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Kirim pembaruan' }));
        expect(uji.kiriman.at(-1)).toMatchObject({
            url: '/operasional/insiden/01JINSIDEN00000000000000A/pembaruan',
            data: { Status: 'Diselidiki', Isi: 'Penyebab ditemukan: antrean macet.' },
        });
    });

    it('hanya melihat (operasional.lihat): tanpa tombol catat insiden dan tanpa aksi menulis, kronologi tetap bisa dibaca', () => {
        AturHalaman([IzinPengelola.OperasionalLihat]);
        Render(<HalamanInsiden {...Props([aktif])} />);
        expect(screen.queryByRole('button', { name: 'Catat insiden' })).toBeNull();
        BukaMenu(screen.getAllByRole('button', { name: /Aksi Sinkron kasir tertunda/ })[0] as HTMLElement);
        expect(screen.getByRole('menuitem', { name: 'Lihat kronologi' })).toBeTruthy();
        expect(screen.queryByRole('menuitem', { name: 'Tambah pembaruan' })).toBeNull();
    });

    it('catat insiden: persen di atas ambang membuat kotak "tampilkan di halaman status" wajib dan terkirim true', () => {
        AturHalaman([IzinPengelola.OperasionalLihat, IzinPengelola.OperasionalKelola]);
        Render(<HalamanInsiden {...Props([])} />);
        fireEvent.click(screen.getByRole('button', { name: 'Catat insiden' }));
        fireEvent.change(screen.getByLabelText(/Judul/), { target: { value: 'Gangguan luas pembayaran' } });
        fireEvent.change(screen.getByLabelText(/Perkiraan tenant aktif terdampak/), { target: { value: '35' } });
        expect(screen.getByRole('checkbox', { name: /wajib: dampak di atas ambang/ })).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Catat insiden' }));
        expect(uji.kiriman.at(-1)).toMatchObject({
            url: '/operasional/insiden',
            data: { Judul: 'Gangguan luas pembayaran', Tingkat: 'Kecil', PersenTenantTerdampak: 35, Diumumkan: true },
        });
    });
});
