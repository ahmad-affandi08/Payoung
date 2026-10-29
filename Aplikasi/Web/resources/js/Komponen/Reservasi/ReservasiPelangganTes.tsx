import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanReservasiPublik from '@/Halaman/Publik/Reservasi';
import HalamanStatusReservasi from '@/Halaman/Publik/StatusReservasi';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { SusunJajaranHari } from '@/Komponen/Reservasi/PemilihHari';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-240: halaman reservasi pelanggan bergaya kepala bermerek tenant. Yang dijaga di sini: logo/inisial, langkah pesan,
 * jajaran hari tanpa pergeseran zona waktu, bilah ringkasan, dan pelacak status.
 */

const NAMA_TOKO = 'Salon Ayu Sejahtera Nusantara';
const LAYANAN = [
    { Uuid: '01JLAYANAN0000000000000001', Nama: 'Potong Rambut Premium', DurasiMenit: 45, Harga: '75000.00' },
    { Uuid: '01JLAYANAN0000000000000002', Nama: 'Creambath', DurasiMenit: 60, Harga: null },
];

function PropsPesan(ubah: Record<string, unknown> = {}) {
    return {
        Aktif: true,
        Slug: 'salon-ayu',
        Toko: { Nama: NAMA_TOKO, UrlLogo: null },
        Outlet: [{ Uuid: '01JOUTLET00000000000000001', Nama: 'Outlet Solo' }],
        Layanan: LAYANAN,
        Staf: [{ Uuid: '01JSTAF0000000000000000001', Nama: 'Maya' }],
        HariIni: '2026-10-12',
        TanggalTerakhir: '2026-10-20',
        KonfirmasiOtomatis: false,
        ...ubah,
    };
}

describe('Reservasi pelanggan (D-240)', () => {
    beforeEach(() => AturHalamanUji({}, '/salon-ayu/reservasi'));
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('jajaran hari dihitung UTC: Senin 12 Oktober 2026 dan tanggal pertama sama dengan awal', () => {
        const hari = SusunJajaranHari('2026-10-12', '2026-10-14');

        expect(hari.map((h) => h.Nilai)).toEqual(['2026-10-12', '2026-10-13', '2026-10-14']);
        expect(hari[0]).toMatchObject({ NamaHari: 'Sen', Tanggal: 12, Bulan: 'Okt', Penuh: 'Senin, 12 Oktober 2026' });
        expect(SusunJajaranHari('2026-10-12', '2026-01-01')).toEqual([]);
        expect(SusunJajaranHari('2026-01-01', '2027-12-31')).toHaveLength(62);
    });

    it('kepala memuat nama toko sebagai judul dan logo tenant bila ada, inisial bila tidak', () => {
        const { unmount: Lepas } = RenderUji(<HalamanReservasiPublik {...PropsPesan()} />);
        expect(screen.getByRole('heading', { level: 1, name: NAMA_TOKO })).toBeTruthy();
        expect(screen.getByText('SA')).toBeTruthy();
        Lepas();

        RenderUji(
            <HalamanReservasiPublik {...PropsPesan({ Toko: { Nama: NAMA_TOKO, UrlLogo: '/salon-ayu/logo' } })} />,
        );
        expect(screen.getByAltText(`Logo ${NAMA_TOKO}`).getAttribute('src')).toBe('/salon-ayu/logo');
    });

    it('memilih layanan dan hari memuat jam kosong. Tombol kirim menunggu jam dan persetujuan', async () => {
        const TiruanFetch = vi.fn(
            async () =>
                new Response(
                    JSON.stringify({
                        Slot: [
                            { Jam: '09:00', Staf: [] },
                            { Jam: '10:30', Staf: [] },
                        ],
                    }),
                ),
        );
        vi.stubGlobal('fetch', TiruanFetch);
        RenderUji(<HalamanReservasiPublik {...PropsPesan()} />);

        const kirim = screen.getByRole('button', { name: 'Kirim permintaan reservasi' }) as HTMLButtonElement;
        expect(kirim.disabled).toBe(true);
        expect(screen.getByText('Pilih layanan, tanggal, dan jam.')).toBeTruthy();

        fireEvent.click(screen.getByRole('radio', { name: /Potong Rambut Premium/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Selasa, 13 Oktober 2026' }));
        fireEvent.click(await screen.findByRole('button', { name: '10:30' }));

        await waitFor(() =>
            expect(screen.getByRole('button', { name: '10:30' }).getAttribute('aria-pressed')).toBe('true'),
        );
        expect(String((TiruanFetch.mock.calls as unknown[][]).at(-1)?.[0])).toContain('Tanggal=2026-10-13');
        expect(screen.getByText(/Potong Rambut Premium \| Selasa, 13 Oktober 2026 \| 10:30/)).toBeTruthy();
        expect(kirim.disabled).toBe(true);

        fireEvent.click(screen.getByRole('checkbox', { name: /Saya setuju/ }));
        await waitFor(() => expect(kirim.disabled).toBe(false));
    });

    it('tanpa layanan online: pesan ramah, tanpa formulir', () => {
        RenderUji(<HalamanReservasiPublik {...PropsPesan({ Layanan: [] })} />);
        expect(screen.getByText('Belum ada layanan')).toBeTruthy();
        expect(screen.queryByRole('button', { name: /reservasi/i })).toBeNull();
    });

    it('status: pelacak menandai langkah berjalan, reservasi batal tanpa pelacak, tombol batal hanya bila boleh', () => {
        const dasar = {
            Ditemukan: true,
            Slug: 'salon-ayu',
            Kode: 'ABCDEFGH1234',
            Toko: { Nama: NAMA_TOKO, UrlLogo: null },
            Reservasi: {
                Nomor: 'RS/2026/10/0001',
                MulaiPada: '2026-10-13T03:00:00Z',
                SelesaiPada: '2026-10-13T03:45:00Z',
                Layanan: 'Potong Rambut Premium',
                Staf: null,
                Outlet: 'Outlet Solo',
                NamaPelanggan: 'Ayu',
                Status: 'Dikonfirmasi' as const,
                LabelStatus: 'Dikonfirmasi',
                BolehBatal: true,
            },
        };
        const { unmount: Lepas } = RenderUji(<HalamanStatusReservasi {...dasar} />);
        const langkah = within(screen.getByRole('list', { name: 'Perkembangan reservasi' }));
        expect(langkah.getAllByRole('listitem')).toHaveLength(4);
        expect(screen.getByRole('listitem', { current: 'step' }).textContent).toContain('Dikonfirmasi');
        expect(screen.getByRole('button', { name: 'Batalkan reservasi' })).toBeTruthy();
        expect(screen.getByText('Ditentukan toko')).toBeTruthy();
        Lepas();

        RenderUji(
            <HalamanStatusReservasi
                {...dasar}
                Reservasi={{ ...dasar.Reservasi, Status: 'Batal', LabelStatus: 'Dibatalkan', BolehBatal: false }}
            />,
        );
        expect(screen.queryByRole('list', { name: 'Perkembangan reservasi' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Batalkan reservasi' })).toBeNull();
        expect(screen.getAllByText('Dibatalkan').length).toBeGreaterThan(0);
    });
});
