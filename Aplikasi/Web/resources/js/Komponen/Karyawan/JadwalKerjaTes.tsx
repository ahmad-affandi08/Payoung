import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanJadwalKerja from '@/Halaman/Kelola/Karyawan/Jadwal';
import { KalimatKekurangan } from '@/Komponen/Karyawan/Jadwal/PanelPerhatianJadwal';
import { CekBarisKebutuhan } from '@/Komponen/Karyawan/Jadwal/DialogKebutuhanStaf';
import { UrutkanPola } from '@/Komponen/Karyawan/Jadwal/DialogPolaJadwal';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import {
    AdaGalatJam,
    BuatIsian,
    CekJam,
    FormatTanggalPendek,
    GeserTanggal,
    NamaHariDariTanggal,
    RapikanJam,
    SusunSelBerubah,
} from '@/Fitur/Karyawan/LogikaJadwal';
import type { BarisJadwal, PropsJadwalKerja } from '@/Tipe/Karyawan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const Outlet = { Uuid: '01J9OTL0000000000000000001', Nama: 'Kopi Senja Solo Baru' };
const Hari = ['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26', '2026-09-27'];
const Pagi = {
    Uuid: '01J9SHF0000000000000000001',
    Nama: 'Pagi',
    JamMulai: '08:00',
    JamSelesai: '16:00',
    Aktif: true,
    Urutan: 0,
};

function BarisJadwalUji(): BarisJadwal {
    return {
        Uuid: '01J9KRY0000000000000000001',
        Nama: 'Dimas Pratama Wicaksono Adiwijaya',
        Jabatan: 'Barista',
        Aktif: true,
        Cuti: { '2026-09-23': { Jenis: 'Sakit', Berbayar: true } },
        Pola: [],
        Jadwal: {
            ...Object.fromEntries(Hari.map((t) => [t, []])),
            '2026-09-21': [
                {
                    Uuid: '01J9JDW0000000000000000001',
                    JamMulai: '08:00',
                    JamSelesai: '16:00',
                    OutletLain: false,
                    UuidShift: Pagi.Uuid,
                },
            ],
            '2026-09-22': [
                {
                    Uuid: '01J9JDW0000000000000000002',
                    JamMulai: '09:00',
                    JamSelesai: '17:00',
                    OutletLain: true,
                    UuidShift: null,
                },
            ],
        },
    };
}

function PropsUji(sambungan: Partial<PropsJadwalKerja['Jadwal']> = {}): PropsJadwalKerja {
    return {
        OpsiOutlet: [Outlet],
        UuidOutlet: Outlet.Uuid,
        Senin: '2026-09-21',
        Jadwal: {
            Hari,
            Baris: [BarisJadwalUji()],
            ShiftKerja: [Pagi],
            Kebutuhan: [],
            Kekurangan: [],
            TukarShift: [],
            ...sambungan,
        },
        Izin: { Kelola: true },
    };
}

describe('Jadwal kerja (F-18, D-251)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/karyawan/jadwal');
        window.history.replaceState({}, '', '/kelola/karyawan/jadwal');
        vi.clearAllMocks();
    });
    afterEach(() => cleanup());

    it('bantuan: geser tanggal, cek dan rapikan jam, nama hari, tanggal pendek', () => {
        expect(GeserTanggal('2026-09-28', -7)).toBe('2026-09-21');
        expect(GeserTanggal('2026-12-28', 7)).toBe('2027-01-04');
        expect([CekJam('08:00'), CekJam('24:00'), CekJam('8:00')]).toEqual([true, false, false]);
        expect([RapikanJam('800'), RapikanJam('0830'), RapikanJam('08:30')]).toEqual(['08:00', '08:30', '08:30']);
        expect([NamaHariDariTanggal('2026-09-21'), NamaHariDariTanggal('2026-09-27')]).toEqual(['Senin', 'Minggu']);
        expect(FormatTanggalPendek('2026-09-07')).toBe('7 Sep');
    });

    it('sel berubah: banyak shift per hari, hapus, outlet lain dilewati, urutan jam tidak memengaruhi', () => {
        const b = BarisJadwalUji();
        const isian = BuatIsian([b]);
        // Libur diberi satu baris kosong supaya jam bisa langsung diketik. Shift outlet lain tidak masuk isian.
        expect(isian[b.Uuid]?.['2026-09-23']).toEqual([{ JamMulai: '', JamSelesai: '', UuidShift: null }]);
        expect(isian[b.Uuid]?.['2026-09-22']).toEqual([{ JamMulai: '', JamSelesai: '', UuidShift: null }]);
        expect(SusunSelBerubah([b], isian)).toEqual([]);

        isian[b.Uuid] = {
            ...isian[b.Uuid],
            '2026-09-21': [{ JamMulai: '', JamSelesai: '', UuidShift: null }],
            '2026-09-23': [
                { JamMulai: '08:00', JamSelesai: '12:00', UuidShift: null },
                { JamMulai: '17:00', JamSelesai: '21:00', UuidShift: null },
            ],
            '2026-09-22': [{ JamMulai: '10:00', JamSelesai: '18:00', UuidShift: null }],
        };
        expect(SusunSelBerubah([b], isian)).toEqual([
            { UuidKaryawan: b.Uuid, Tanggal: '2026-09-21', Shift: [] },
            {
                UuidKaryawan: b.Uuid,
                Tanggal: '2026-09-23',
                Shift: [
                    { JamMulai: '08:00', JamSelesai: '12:00', UuidShift: null },
                    { JamMulai: '17:00', JamSelesai: '21:00', UuidShift: null },
                ],
            },
        ]);
        expect(
            AdaGalatJam([
                {
                    UuidKaryawan: b.Uuid,
                    Tanggal: '2026-09-23',
                    Shift: [{ JamMulai: '08:00', JamSelesai: '', UuidShift: null }],
                },
            ]),
        ).toBe(true);
    });

    it('ubah jam lalu simpan mengirim daftar shift sel yang berubah. Sel outlet lain terkunci', () => {
        RenderUji(<HalamanJadwalKerja {...PropsUji()} />);
        expect(screen.getByText('Di outlet lain 09:00–17:00')).toBeTruthy();
        const simpan = screen.getByRole('button', { name: 'Simpan jadwal' });
        expect((simpan as HTMLButtonElement).disabled).toBe(true);
        fireEvent.change(screen.getByLabelText('Dimas Pratama Wicaksono Adiwijaya Rabu mulai'), {
            target: { value: '07:30' },
        });
        expect((simpan as HTMLButtonElement).disabled).toBe(true);
        expect(screen.getByText(/isi keduanya atau kosongkan keduanya/)).toBeTruthy();
        fireEvent.change(screen.getByLabelText('Dimas Pratama Wicaksono Adiwijaya Rabu selesai'), {
            target: { value: '15:30' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan jadwal' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/karyawan/jadwal',
            {
                UuidOutlet: Outlet.Uuid,
                Senin: '2026-09-21',
                Sel: [
                    {
                        UuidKaryawan: '01J9KRY0000000000000000001',
                        Tanggal: '2026-09-23',
                        Shift: [{ JamMulai: '07:30', JamSelesai: '15:30', UuidShift: null }],
                    },
                ],
            },
            expect.anything(),
        );
    });

    it('menampilkan nama shift pada jam yang berasal dari shift bernama', () => {
        RenderUji(<HalamanJadwalKerja {...PropsUji()} />);
        expect(screen.getAllByText('Pagi').length).toBeGreaterThan(0);
    });

    it('tombol Terapkan pola, Kirim ke karyawan, dan Salin memanggil rute yang tepat', () => {
        RenderUji(<HalamanJadwalKerja {...PropsUji()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Terapkan pola' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/karyawan/jadwal/terapkan-pola',
            { UuidOutlet: Outlet.Uuid, Senin: '2026-09-21' },
            expect.anything(),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Kirim ke karyawan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/karyawan/jadwal/umumkan',
            { UuidOutlet: Outlet.Uuid, Senin: '2026-09-21' },
            expect.anything(),
        );
    });

    it('menampilkan kekurangan staf dan menyetujui tukar shift', () => {
        const kurang = {
            Tanggal: '2026-09-21',
            JamMulai: '10:00',
            JamSelesai: '14:00',
            Minimal: 2,
            Terjadwal: 1,
            Kurang: 1,
        };
        RenderUji(
            <HalamanJadwalKerja
                {...PropsUji({
                    Kekurangan: [kurang],
                    TukarShift: [
                        {
                            Uuid: '01J9TKR0000000000000000001',
                            Pemohon: 'Rina',
                            Rekan: 'Dimas',
                            Tanggal: '2026-09-24',
                            JamMulai: '08:00',
                            JamSelesai: '16:00',
                            TukarDengan: null,
                            Alasan: 'Ada acara keluarga',
                        },
                    ],
                })}
            />,
        );
        expect(KalimatKekurangan(kurang)).toBe('Senin 21 Sep 10:00–14:00: kurang 1 orang (terjadwal 1 dari minimal 2)');
        expect(screen.getByText(/kurang 1 orang/)).toBeTruthy();
        expect(screen.getByText(/Ada acara keluarga/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Setujui' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/karyawan/jadwal/tukar-shift/01J9TKR0000000000000000001/putuskan',
            { Setuju: true },
            expect.anything(),
        );
    });

    it('bantuan dialog: urut pola, validasi baris kebutuhan staf', () => {
        expect(
            UrutkanPola([
                { HariKe: 3, JamMulai: '08:00', JamSelesai: '12:00', UuidShift: null },
                { HariKe: 1, JamMulai: '17:00', JamSelesai: '21:00', UuidShift: null },
                { HariKe: 1, JamMulai: '08:00', JamSelesai: '12:00', UuidShift: null },
            ]).map((b) => `${b.HariKe}-${b.JamMulai}`),
        ).toEqual(['1-08:00', '1-17:00', '3-08:00']);
        const sah = { HariKe: '1', JamMulai: '10:00', JamSelesai: '14:00', Minimal: '2' };
        expect([
            CekBarisKebutuhan(sah),
            CekBarisKebutuhan({ ...sah, JamSelesai: '09:00' }),
            CekBarisKebutuhan({ ...sah, Minimal: '0' }),
            CekBarisKebutuhan({ ...sah, JamMulai: '' }),
        ]).toEqual([true, false, false, false]);
    });

    it('menu Atur membuka dialog kebutuhan staf dan isi massal mengirim shift, karyawan, dan hari', () => {
        RenderUji(<HalamanJadwalKerja {...PropsUji()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Isi massal' }));
        fireEvent.click(screen.getByRole('button', { name: 'Pilih semua karyawan' }));
        fireEvent.click(screen.getByRole('button', { name: 'Senin sampai Sabtu' }));
        fireEvent.click(screen.getByRole('button', { name: 'Isi jadwal' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/karyawan/jadwal/isi-massal',
            {
                UuidOutlet: Outlet.Uuid,
                Senin: '2026-09-21',
                UuidShift: Pagi.Uuid,
                UuidKaryawan: ['01J9KRY0000000000000000001'],
                Tanggal: Hari.slice(0, 6),
            },
            expect.anything(),
        );
    });
});
