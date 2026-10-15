import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ObrolanKonsol, { type BarisObrolan, type KeadaanInbox } from '@/Halaman/Pengelola/Dukungan/Obrolan';
import { IzinPengelola } from '@/Tipe/Pengelola';

/*
 * PLT-12 (D-163): inbox obrolan petugas di konsol. Daftar menempatkan yang menunggu di atas, judul tab memuat jumlah
 * yang menunggu, kesiapan dan detak berjalan lewat server, balasan/ambil/selesai memakai endpoint obrolan.
 */

const uji = vi.hoisted(() => ({ props: {} as Record<string, unknown>, url: '/dukungan/obrolan' }));

vi.mock('@inertiajs/react', async () => {
    const tiruan = (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia;

    return { ...tiruan, usePage: () => ({ props: uji.props, url: uji.url }) };
});

const BuatBaris = (ubah: Partial<BarisObrolan> = {}): BarisObrolan => ({
    Uuid: 'OB1',
    Nomor: 'TKT-2026-000007',
    Judul: 'Obrolan: printer tidak mencetak',
    NamaTenant: 'Kopi Nusantara',
    NamaPelapor: 'Rina Wulandari',
    Status: 'Baru',
    LabelStatus: 'Baru',
    IdPenanggungJawab: null,
    Petugas: null,
    MenungguBalasan: true,
    Pratinjau: 'Printer tidak mencetak sejak pagi',
    PesanTerakhirPada: new Date(Date.now() - 5 * 60_000).toISOString(),
    ...ubah,
});

const BuatAwal = (ubah: Partial<KeadaanInbox> = {}): KeadaanInbox => ({
    Siap: false,
    PetugasOnline: 0,
    Obrolan: [
        BuatBaris(),
        BuatBaris({
            Uuid: 'OB2',
            Nomor: 'TKT-2026-000008',
            NamaTenant: 'Laundry Bersih',
            NamaPelapor: 'Budi',
            MenungguBalasan: false,
            Petugas: 'Dewi',
            Pratinjau: 'Terima kasih ya',
        }),
    ],
    ...ubah,
});

const BuatPercakapan = (ubah: Record<string, unknown> = {}) => ({
    Uuid: 'OB1',
    Nomor: 'TKT-2026-000007',
    Status: 'Baru',
    LabelStatus: 'Baru',
    BisaDibalas: true,
    Pesan: [
        {
            Uuid: 'M1',
            JenisPengirim: 'Pengguna',
            NamaPengirim: 'Rina Wulandari',
            Isi: 'Printer tidak mencetak sejak pagi',
            DibuatPada: '2026-10-15T02:00:00+00:00',
        },
    ],
    ...ubah,
});

type Panggilan = { url: string; metode: string; isi: unknown };

function PasangFetch(inbox: () => KeadaanInbox, jawab?: (p: Panggilan) => unknown): Panggilan[] {
    const catatan: Panggilan[] = [];
    vi.stubGlobal(
        'fetch',
        vi.fn((url: string, opsi?: RequestInit) => {
            const p = {
                url,
                metode: opsi?.method ?? 'GET',
                isi: opsi?.body === undefined ? null : JSON.parse(String(opsi.body)),
            };
            catatan.push(p);
            let isi: unknown = jawab?.(p);

            if (isi === undefined) {
                isi = url.endsWith('/daftar')
                    ? inbox()
                    : url.endsWith('/kehadiran')
                      ? { Siap: (p.isi as { Siap?: boolean } | null)?.Siap ?? false, PetugasOnline: 1 }
                      : BuatPercakapan();
            }

            return Promise.resolve(
                new Response(JSON.stringify(isi), { status: 200, headers: { 'Content-Type': 'application/json' } }),
            );
        }),
    );

    return catatan;
}

function PropsKonsol(bolehTangani: boolean): Record<string, unknown> {
    return {
        NamaAplikasi: 'Kasir',
        Lingkungan: 'Staging',
        Kilat: null,
        Pengguna: {
            Uuid: 'P1',
            Nama: 'Dewi Dukungan',
            Email: 'dewi@contoh.id',
            KodePeran: [],
            Izin: bolehTangani
                ? [IzinPengelola.DukunganTiketLihat, IzinPengelola.DukunganTiketTangani]
                : [IzinPengelola.DukunganTiketLihat],
        },
        PeringatanSuperAdmin: false,
        PeringatanIntegrasi: [],
        PeringatanOperasional: [],
        errors: {},
    };
}

function Render(keadaan: KeadaanInbox = BuatAwal(), bolehTangani = true) {
    uji.props = PropsKonsol(bolehTangani);

    return render(
        <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
            <ObrolanKonsol Awal={keadaan} BolehTangani={bolehTangani} SegarkanDetik={60} MaksPanjangPesan={2000} />
        </QueryClientProvider>,
    );
}

beforeEach(() => {
    document.title = 'Konsol';
    Object.defineProperty(document, 'cookie', { value: 'XSRF-TOKEN=tok', configurable: true });
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('Inbox obrolan konsol (PLT-12)', () => {
    it('menampilkan obrolan yang menunggu lebih dulu dengan lama menunggu, dan judul tab memuat jumlah yang menunggu', async () => {
        PasangFetch(() => BuatAwal());
        Render();

        const daftar = screen.getByRole('list', { name: 'Daftar obrolan terbuka' });
        const butir = within(daftar).getAllByRole('listitem');
        expect(butir).toHaveLength(2);
        expect(within(butir[0] as HTMLElement).getByText('Kopi Nusantara')).toBeTruthy();
        expect(within(butir[0] as HTMLElement).getByText(/Menunggu 5 mnt/)).toBeTruthy();
        expect(within(butir[1] as HTMLElement).getByText('Sudah dibalas')).toBeTruthy();
        await waitFor(() => expect(document.title.startsWith('(1) ')).toBe(true));
    });

    it('keadaan kosong menjelaskan obrolan akan muncul otomatis', () => {
        PasangFetch(() => BuatAwal({ Obrolan: [] }));
        Render(BuatAwal({ Obrolan: [] }));

        expect(screen.getByText(/Obrolan baru dari tenant akan muncul di sini otomatis/)).toBeTruthy();
    });

    it('Siap menerima obrolan memanggil server, lalu tombol berganti menjadi Berhenti', async () => {
        const catatan = PasangFetch(() => BuatAwal());
        Render();

        fireEvent.click(screen.getByRole('button', { name: 'Siap menerima obrolan' }));

        expect(await screen.findByRole('button', { name: 'Berhenti menerima obrolan' })).toBeTruthy();
        expect(catatan.find((p) => p.url === '/dukungan/obrolan/kehadiran')).toMatchObject({
            metode: 'POST',
            isi: { Siap: true },
        });
    });

    it('memilih obrolan memuat percakapan; balasan, ambil, dan selesaikan memakai endpoint obrolan', async () => {
        const catatan = PasangFetch(() => BuatAwal());
        Render();

        fireEvent.click(screen.getByRole('button', { name: /Kopi Nusantara/ }));
        const log = await screen.findByRole('log', { name: 'Percakapan obrolan' });
        expect(await within(log).findByText('Printer tidak mencetak sejak pagi')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Pesan obrolan'), { target: { value: 'Halo Rina, saya cek ya.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Kirim balasan' }));
        await waitFor(() =>
            expect(catatan.some((p) => p.url === '/dukungan/obrolan/OB1/pesan' && p.metode === 'POST')).toBe(true),
        );
        expect(catatan.find((p) => p.metode === 'POST' && p.url === '/dukungan/obrolan/OB1/pesan')?.isi).toEqual({
            Isi: 'Halo Rina, saya cek ya.',
        });

        fireEvent.click(screen.getByRole('button', { name: 'Ambil obrolan ini' }));
        await waitFor(() => expect(catatan.some((p) => p.url === '/dukungan/obrolan/OB1/ambil')).toBe(true));
        fireEvent.click(screen.getByRole('button', { name: 'Selesaikan obrolan' }));
        await waitFor(() => expect(catatan.some((p) => p.url === '/dukungan/obrolan/OB1/selesai')).toBe(true));
        expect(screen.getByRole('link', { name: 'Buka sebagai tiket' }).getAttribute('href')).toBe(
            '/dukungan/tiket/OB1',
        );
    });

    it('obrolan yang sudah selesai tidak bisa dibalas dan menjelaskannya', async () => {
        PasangFetch(
            () => BuatAwal(),
            (p) =>
                p.url.endsWith('/pesan')
                    ? BuatPercakapan({ Status: 'Selesai', LabelStatus: 'Selesai', BisaDibalas: false })
                    : undefined,
        );
        Render();

        fireEvent.click(screen.getByRole('button', { name: /Kopi Nusantara/ }));

        expect(await screen.findByText(/sudah selesai dan tidak bisa dibalas dari inbox/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Kirim balasan' })).toBeNull();
    });

    it('tanpa izin tangani: daftar dan percakapan terbaca, tetapi tidak ada tombol siap, balas, ambil, atau selesai', async () => {
        PasangFetch(() => BuatAwal());
        Render(BuatAwal(), false);

        expect(screen.queryByRole('button', { name: 'Siap menerima obrolan' })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: /Kopi Nusantara/ }));
        await screen.findByRole('log', { name: 'Percakapan obrolan' });

        expect(screen.queryByRole('button', { name: 'Kirim balasan' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Ambil obrolan ini' })).toBeNull();
    });
});
