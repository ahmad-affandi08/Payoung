import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarReservasi, { FormatWaktuReservasi } from '@/Halaman/Kelola/Reservasi/Daftar';
import HalamanReservasiPublik from '@/Halaman/Publik/Reservasi';
import { BuatHasilTabel } from '@/Komponen/Katalog/DataUjiKatalog';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import type { BarisReservasi, PropsDaftarReservasi } from '@/Tipe/Reservasi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * F-07 mode service: halaman reservasi back-office (aksi status sesuai transisi, catat reservasi memuat jam kosong dari
 * server) dan halaman publik (tutup = pesan ramah).
 */

const baris: BarisReservasi = {
    Uuid: '01JRESERVASI00000000000001',
    Nomor: 'RS/2026/10/0001',
    MulaiPada: '2026-10-13T03:00:00Z',
    SelesaiPada: '2026-10-13T03:45:00Z',
    NamaPelanggan: 'Rina Wulandari Kusumaningrum',
    NoHp: '0812-3456-7890',
    Layanan: 'Potong Rambut & Styling Premium',
    Staf: { Uuid: '01JSTAF0000000000000000001', Nama: 'Maya Senior Stylist' },
    Outlet: { Uuid: '01JOUTLET00000000000000001', Nama: 'Outlet Solo' },
    Status: 'Dikonfirmasi',
    LabelStatus: 'Dikonfirmasi',
    Sumber: 'Online',
    LabelSumber: 'Online',
    Catatan: null,
    AlasanBatal: null,
    StatusBerikutnya: ['Hadir', 'Batal', 'TidakDatang'],
};

function Props(ubah: Partial<PropsDaftarReservasi> = {}): PropsDaftarReservasi {
    return {
        Reservasi: BuatHasilTabel([baris]),
        OpsiStatus: [{ Nilai: 'Dikonfirmasi', Label: 'Dikonfirmasi' }],
        OpsiOutlet: [{ Uuid: '01JOUTLET00000000000000001', Nama: 'Outlet Solo' }],
        OpsiStaf: [{ Uuid: '01JSTAF0000000000000000001', Nama: 'Maya Senior Stylist' }],
        OpsiLayanan: [
            {
                Uuid: '01JLAYANAN0000000000000001',
                Nama: 'Potong Rambut & Styling Premium',
                DurasiMenit: 45,
                Harga: '75000.00',
            },
        ],
        Pengaturan: {
            OnlineAktif: true,
            KonfirmasiOtomatis: true,
            IntervalSlotMenit: 30,
            JedaMenit: 0,
            BatasHariKeDepan: 30,
            MinimalMenitSebelum: 60,
            PengingatAktif: true,
        },
        HariIni: '2026-10-12',
        TautanPublik: 'https://dashboard.payoung.id/salon-ayu/reservasi',
        Izin: { Pengaturan: true },
        ...ubah,
    };
}

describe('Reservasi (F-07 mode service)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/reservasi'));
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('waktu reservasi berformat Indonesia. Aksi baris mengikuti transisi status', () => {
        expect(FormatWaktuReservasi(baris.MulaiPada, baris.SelesaiPada)).toMatch(
            /13 Okt 2026 \| \d{2}\.\d{2}–\d{2}\.\d{2}/,
        );
        RenderUji(<HalamanDaftarReservasi {...Props()} />);
        expect(screen.getAllByText('Rina Wulandari Kusumaningrum').length).toBeGreaterThan(0);
        expect(screen.getByRole('link', { name: 'https://dashboard.payoung.id/salon-ayu/reservasi' })).toBeTruthy();

        BukaMenu(screen.getAllByRole('button', { name: /Aksi/ })[0] as HTMLElement);
        expect(screen.getByRole('menuitem', { name: 'Pelanggan sudah datang' })).toBeTruthy();
        expect(screen.getByRole('menuitem', { name: 'Pindah jadwal' })).toBeTruthy();
        expect(screen.getByRole('menuitem', { name: 'Batalkan' })).toBeTruthy();
        expect(screen.queryByRole('menuitem', { name: 'Konfirmasi' })).toBeNull();
    });

    it('catat reservasi: jam kosong dimuat dari server setelah layanan dipilih', async () => {
        const TiruanFetch = vi.fn(
            async () =>
                new Response(
                    JSON.stringify({
                        Slot: [
                            { Jam: '09:00', Staf: [] },
                            { Jam: '11:00', Staf: [] },
                        ],
                    }),
                ),
        );
        vi.stubGlobal('fetch', TiruanFetch);
        RenderUji(<HalamanDaftarReservasi {...Props()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Catat reservasi' }));
        expect(screen.getByText('Pilih layanan dan tanggal untuk melihat jam kosong.')).toBeTruthy();
        fireEvent.click(screen.getByRole('combobox', { name: /Layanan/ }));
        fireEvent.click(await screen.findByRole('option', { name: /Potong Rambut/ }));

        expect(await screen.findByRole('button', { name: '11:00' })).toBeTruthy();
        expect(String((TiruanFetch.mock.calls as unknown[][])[0]?.[0])).toContain(
            '/kelola/reservasi/slot?Outlet=01JOUTLET00000000000000001',
        );
        fireEvent.click(screen.getByRole('button', { name: '11:00' }));
        await waitFor(() =>
            expect(screen.getByRole('button', { name: '11:00' }).getAttribute('aria-pressed')).toBe('true'),
        );
    });

    it('tanpa layanan berdurasi: tombol catat nonaktif dengan petunjuk. Halaman publik tutup', () => {
        RenderUji(<HalamanDaftarReservasi {...Props({ OpsiLayanan: [] })} />);
        expect((screen.getByRole('button', { name: 'Catat reservasi' }) as HTMLButtonElement).disabled).toBe(true);
        expect(screen.getByText(/Isi "Durasi layanan"/)).toBeTruthy();
        cleanup();

        RenderUji(<HalamanReservasiPublik Aktif={false} Slug="salon-ayu" />);
        expect(screen.getByText('Reservasi online belum dibuka')).toBeTruthy();
    });
});
