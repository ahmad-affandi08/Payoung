import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import PanelObrolan from '@/Komponen/Bantuan/PanelObrolan';
import type { KeadaanObrolanTenant, PesanObrolan } from '@/Pustaka/Obrolan';

/*
 * PLT-12 (D-163): panel obrolan langsung di lembar Bantuan. Polling berjalan hanya selagi ada obrolan, pesan baru
 * digabung lewat penanda, galat tidak menghapus ketikan, dan layar jujur soal ada/tidaknya petugas online.
 */

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const BuatLayanan = (ubah: Partial<KeadaanObrolanTenant['Layanan']> = {}): KeadaanObrolanTenant['Layanan'] => ({
    Buka: false,
    PetugasOnline: 0,
    DalamJamKerja: true,
    Pesan: 'Belum ada petugas yang siap menjawab saat ini. Pesan Anda tetap tercatat sebagai tiket.',
    SegarkanDetik: 1,
    MaksPanjangPesan: 2000,
    ...ubah,
});

const BuatPesan = (uuid: string, isi: string, jenis: PesanObrolan['JenisPengirim'] = 'Pengguna'): PesanObrolan => ({
    Uuid: uuid,
    JenisPengirim: jenis,
    NamaPengirim: jenis === 'Pengguna' ? 'Rina Wulandari' : 'Dewi | Tim Dukungan',
    Isi: isi,
    DibuatPada: '2026-10-15T02:00:00+00:00',
});

const BuatObrolan = (daftar: PesanObrolan[]): NonNullable<KeadaanObrolanTenant['Obrolan']> => ({
    Uuid: 'OB1',
    Nomor: 'TKT-2026-000007',
    Status: 'Baru',
    LabelStatus: 'Baru',
    Petugas: null,
    Pesan: daftar,
});

type Tiruan = { url: string; metode: string; isi: unknown };

/** fetch palsu: `jawab` menerima permintaan dan mengembalikan [status, isi]. */
function PasangFetch(jawab: (permintaan: Tiruan) => [number, unknown]): Tiruan[] {
    const catatan: Tiruan[] = [];
    vi.stubGlobal(
        'fetch',
        vi.fn((url: string, opsi?: RequestInit) => {
            const permintaan = {
                url,
                metode: opsi?.method ?? 'GET',
                isi: opsi?.body === undefined ? null : JSON.parse(String(opsi.body)),
            };
            catatan.push(permintaan);
            const [status, isi] = jawab(permintaan);

            return Promise.resolve(
                new Response(JSON.stringify(isi), { status, headers: { 'Content-Type': 'application/json' } }),
            );
        }),
    );

    return catatan;
}

function Render() {
    return render(
        <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
            <PanelObrolan halamanAsal="/kelola/produk" />
        </QueryClientProvider>,
    );
}

beforeEach(() => {
    Object.defineProperty(document, 'cookie', { value: 'XSRF-TOKEN=tok', configurable: true });
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('PanelObrolan (PLT-12)', () => {
    it('tanpa obrolan: menyatakan tim belum online terus terang dan tombol Mulai baru aktif setelah ada isi', async () => {
        PasangFetch(() => [200, { Layanan: BuatLayanan(), Obrolan: null }]);
        Render();

        expect(await screen.findByText('Tim belum online')).toBeTruthy();
        expect(screen.getByText(/tetap tercatat sebagai tiket/)).toBeTruthy();

        const mulai = screen.getByRole('button', { name: 'Mulai obrolan' });
        expect((mulai as HTMLButtonElement).disabled).toBe(true);

        fireEvent.change(screen.getByLabelText('Pesan obrolan'), { target: { value: '   ' } });
        expect((mulai as HTMLButtonElement).disabled).toBe(true);
        fireEvent.change(screen.getByLabelText('Pesan obrolan'), { target: { value: 'Printer tidak mencetak' } });
        expect((mulai as HTMLButtonElement).disabled).toBe(false);
    });

    it('petugas online: menampilkan "Tim online"', async () => {
        PasangFetch(() => [
            200,
            { Layanan: BuatLayanan({ Buka: true, PetugasOnline: 2, Pesan: 'Tim dukungan online.' }), Obrolan: null },
        ]);
        Render();

        expect(await screen.findByText('Tim online')).toBeTruthy();
    });

    it('mengirim pesan pertama (dengan halaman asal), menampilkan percakapan, dan Enter mengirim sedangkan Shift+Enter tidak', async () => {
        // Setelah pesan pertama terkirim, jawaban GET berikutnya ikut memuat obrolannya (seperti server sungguhan).
        let terkirim = false;
        const catatan = PasangFetch((p) => {
            if (p.metode === 'POST') {
                terkirim = true;

                return [
                    201,
                    {
                        Layanan: BuatLayanan({ SegarkanDetik: 60 }),
                        Obrolan: BuatObrolan([BuatPesan('M1', 'Printer tidak mencetak')]),
                        Baru: true,
                    },
                ];
            }

            return [
                200,
                {
                    Layanan: BuatLayanan({ SegarkanDetik: 60 }),
                    Obrolan: terkirim ? BuatObrolan([BuatPesan('M1', 'Printer tidak mencetak')]) : null,
                },
            ];
        });
        Render();
        await screen.findByText('Tim belum online');

        const kotak = screen.getByLabelText('Pesan obrolan');
        fireEvent.change(kotak, { target: { value: 'Printer tidak mencetak' } });
        fireEvent.keyDown(kotak, { key: 'Enter', shiftKey: true });
        expect(catatan.filter((p) => p.metode === 'POST')).toHaveLength(0);

        fireEvent.keyDown(kotak, { key: 'Enter' });

        const log = await screen.findByRole('log', { name: 'Percakapan obrolan' });
        expect(within(log).getByText('Printer tidak mencetak')).toBeTruthy();
        expect(catatan.find((p) => p.metode === 'POST')).toMatchObject({
            url: '/kelola/obrolan',
            isi: { Isi: 'Printer tidak mencetak', HalamanAsal: '/kelola/produk' },
        });
        expect(screen.getByText(/menunggu petugas/)).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Lihat sebagai tiket' }).getAttribute('href')).toBe(
            '/kelola/bantuan/OB1',
        );
        // Kotak dikosongkan setelah terkirim.
        expect((screen.getByLabelText('Pesan obrolan') as HTMLTextAreaElement).value).toBe('');
    });

    it('polling membawa penanda pesan terakhir dan menggabungkan pesan petugas tanpa menggandakan', async () => {
        let putaran = 0;
        const catatan = PasangFetch((p) => {
            if (p.metode === 'GET') {
                putaran += 1;

                return putaran === 1
                    ? [
                          200,
                          {
                              Layanan: BuatLayanan(),
                              Obrolan: BuatObrolan([BuatPesan('M1', 'Halo, ada yang bisa dibantu?', 'Pengguna')]),
                          },
                      ]
                    : [
                          200,
                          {
                              Layanan: BuatLayanan(),
                              Obrolan: {
                                  ...BuatObrolan([BuatPesan('M2', 'Halo Rina, saya cek ya.', 'Pengelola')]),
                                  Petugas: 'Dewi',
                                  Status: 'Ditangani',
                              },
                          },
                      ];
            }

            return [200, {}];
        });
        Render();

        const log = await screen.findByRole('log', { name: 'Percakapan obrolan' });
        expect(within(log).getByText('Halo, ada yang bisa dibantu?')).toBeTruthy();

        await waitFor(
            () =>
                expect(
                    within(screen.getByRole('log', { name: 'Percakapan obrolan' })).getByText(
                        'Halo Rina, saya cek ya.',
                    ),
                ).toBeTruthy(),
            { timeout: 4000 },
        );
        expect(within(screen.getByRole('log', { name: 'Percakapan obrolan' })).getAllByRole('listitem')).toHaveLength(
            2,
        );
        expect(catatan.filter((p) => p.metode === 'GET').some((p) => p.url === '/kelola/obrolan?setelah=M1')).toBe(
            true,
        );
        expect(screen.getByText(/dengan Dewi/)).toBeTruthy();
    });

    it('masalah dari server tampil sebagai pesan jelas dan ketikan tidak hilang', async () => {
        PasangFetch((p) =>
            p.metode === 'POST'
                ? [
                      422,
                      {
                          Galat: {
                              Kode: 'P-09',
                              Pesan: 'Tiket ini sudah ditutup. Buat tiket baru bila masalahnya muncul lagi.',
                          },
                      },
                  ]
                : [200, { Layanan: BuatLayanan(), Obrolan: null }],
        );
        Render();
        await screen.findByText('Tim belum online');

        fireEvent.change(screen.getByLabelText('Pesan obrolan'), { target: { value: 'Masih bermasalah' } });
        fireEvent.click(screen.getByRole('button', { name: 'Mulai obrolan' }));

        expect(await screen.findByText(/Tiket ini sudah ditutup/)).toBeTruthy();
        expect((screen.getByLabelText('Pesan obrolan') as HTMLTextAreaElement).value).toBe('Masih bermasalah');
    });

    it('Akhiri obrolan memanggil server dan kembali ke keadaan awal', async () => {
        const catatan = PasangFetch((p) =>
            p.metode === 'POST'
                ? [200, { Layanan: BuatLayanan({ SegarkanDetik: 60 }), Obrolan: null }]
                : [
                      200,
                      { Layanan: BuatLayanan({ SegarkanDetik: 60 }), Obrolan: BuatObrolan([BuatPesan('M1', 'Halo')]) },
                  ],
        );
        Render();

        fireEvent.click(await screen.findByRole('button', { name: 'Akhiri obrolan' }));

        await waitFor(() => expect(screen.queryByRole('log', { name: 'Percakapan obrolan' })).toBeNull());
        expect(catatan.some((p) => p.metode === 'POST' && p.url === '/kelola/obrolan/akhiri')).toBe(true);
        expect(screen.getByRole('button', { name: 'Mulai obrolan' })).toBeTruthy();
    });
});
