import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import PanelJadwalSaya, { LabelShiftSaya } from '@/Komponen/Absensi/PanelJadwalSaya';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import type { JadwalSaya } from '@/Tipe/Karyawan';

const KirimJsonTiruan = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);
vi.mock('@/Pustaka/PermintaanJson', () => ({ KirimJson: KirimJsonTiruan }));

const Jadwal: JadwalSaya = {
    Shift: [
        {
            Uuid: '01J9JDW0000000000000000001',
            Tanggal: '2026-09-24',
            JamMulai: '08:00',
            JamSelesai: '16:00',
            NamaOutlet: 'Kopi Senja',
        },
    ],
    Rekan: [
        {
            Uuid: '01J9KRY0000000000000000002',
            Nama: 'Dimas',
            Shift: [
                { Uuid: '01J9JDW0000000000000000009', Tanggal: '2026-09-25', JamMulai: '16:00', JamSelesai: '23:00' },
            ],
        },
    ],
    Diajukan: [],
    Masuk: [],
};

/*
 * F-18 (D-251): jadwal pribadi dan tukar shift di halaman Absen berlogin.
 */
beforeEach(() => {
    AturHalamanUji({}, '/kelola/absen');
    KirimJsonTiruan.mockReset();
    vi.clearAllMocks();
});

afterEach(() => cleanup());

describe('Panel jadwal saya', () => {
    it('label shift memuat hari, tanggal, dan jam', () => {
        expect(LabelShiftSaya({ Tanggal: '2026-09-24', JamMulai: '08:00', JamSelesai: '16:00' })).toBe(
            'Kamis 24 Sep | 08:00–16:00',
        );
    });

    it('tanpa shift menampilkan keadaan kosong', () => {
        RenderUji(<PanelJadwalSaya jadwal={{ ...Jadwal, Shift: [] }} alamatDasar="/kelola/absen" />);
        expect(screen.getByText('Belum ada shift untuk 14 hari ke depan.')).not.toBeNull();
    });

    it('mengajukan tukar shift ke rekan mengirim shift, rekan, balasan, dan alasan', async () => {
        KirimJsonTiruan.mockResolvedValue({});
        RenderUji(<PanelJadwalSaya jadwal={Jadwal} alamatDasar="/kelola/absen" />);
        fireEvent.click(screen.getByRole('button', { name: 'Tukar' }));
        const kirim = screen.getByRole('button', { name: 'Kirim permintaan' });
        expect((kirim as HTMLButtonElement).disabled).toBe(true);
        fireEvent.click(screen.getByRole('combobox', { name: /Tukar dengan/ }));
        fireEvent.click(await screen.findByRole('option', { name: 'Dimas' }));
        fireEvent.change(screen.getByLabelText(/Alasan/), { target: { value: 'Ada acara keluarga' } });
        fireEvent.click(screen.getByRole('button', { name: 'Kirim permintaan' }));
        await waitFor(() =>
            expect(KirimJsonTiruan).toHaveBeenCalledWith('/kelola/absen/tukar-shift', {
                UuidJadwal: '01J9JDW0000000000000000001',
                UuidRekan: '01J9KRY0000000000000000002',
                UuidJadwalRekan: null,
                Alasan: 'Ada acara keluarga',
            }),
        );
        expect(tiruanRouter.reload).toHaveBeenCalled();
    });

    it('menanggapi permintaan masuk dan membatalkan pengajuan sendiri', async () => {
        KirimJsonTiruan.mockResolvedValue({});
        RenderUji(
            <PanelJadwalSaya
                jadwal={{
                    ...Jadwal,
                    Masuk: [
                        {
                            Uuid: '01J9TKR0000000000000000001',
                            Pemohon: 'Rina',
                            Tanggal: '2026-09-26',
                            JamMulai: '08:00',
                            JamSelesai: '16:00',
                            TukarDengan: null,
                            Alasan: '',
                        },
                    ],
                    Diajukan: [
                        {
                            Uuid: '01J9TKR0000000000000000002',
                            Status: 'MenungguRekan',
                            Label: 'Menunggu rekan',
                            Tanggal: '2026-09-24',
                            JamMulai: '08:00',
                            JamSelesai: '16:00',
                            Rekan: 'Dimas',
                            TukarDengan: null,
                            AlasanKeputusan: null,
                        },
                    ],
                }}
                alamatDasar="/kelola/absen"
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Terima' }));
        await waitFor(() =>
            expect(KirimJsonTiruan).toHaveBeenCalledWith(
                '/kelola/absen/tukar-shift/01J9TKR0000000000000000001/tanggapi',
                {
                    Terima: true,
                },
            ),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Batalkan' }));
        await waitFor(() =>
            expect(KirimJsonTiruan).toHaveBeenCalledWith(
                '/kelola/absen/tukar-shift/01J9TKR0000000000000000002/batal',
                {},
            ),
        );
    });
});
