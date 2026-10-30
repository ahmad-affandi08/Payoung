import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanAturanKehadiran from '@/Halaman/Kelola/Karyawan/AturanKehadiran';
import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import type { PropsAturanKehadiran } from '@/Tipe/Karyawan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * F-18 bagian 5 (D-44): halaman Aturan kehadiran. Bawaan longgar; hanya pemegang karyawan.kelola yang bisa menyimpan;
 * peringatan bila WhatsApp belum aktif.
 */

function Props(ubah: Partial<PropsAturanKehadiran> = {}): PropsAturanKehadiran {
    return {
        Aturan: {
            WajibJadwal: false,
            MasukPalingAwalMenit: 60,
            ToleransiTerlambatMenit: 5,
            ToleransiPulangCepatMenit: 5,
            LemburSetelahMenit: 30,
            PengingatShiftAktif: false,
            PengingatShiftMenitSebelum: 30,
            PeringatanPengelolaAktif: false,
            PeringatanPengelolaSetelahMenit: 15,
            SimpanFotoBukti: true,
            TolakDiLuarRadius: false,
            TutupOtomatisAktif: true,
            TutupOtomatisSetelahMenit: 300,
        },
        WhatsappAktif: true,
        Izin: { Kelola: true },
        ...ubah,
    };
}

describe('Aturan kehadiran (F-18 bagian 5)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/karyawan/aturan-kehadiran'));
    afterEach(() => cleanup());

    it('menyimpan semua aturan sebagai teks menit. Wajib jadwal dan notifikasi bisa dinyalakan', () => {
        RenderUji(<HalamanAturanKehadiran {...Props()} />);
        fireEvent.click(screen.getByRole('checkbox', { name: 'Wajib punya jadwal kerja untuk absen masuk' }));
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Beritahu pengelola bila karyawan terlambat atau belum masuk' }),
        );
        fireEvent.change(screen.getByLabelText(/Toleransi terlambat \(menit\)/), { target: { value: '10' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan aturan kehadiran' }));

        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'put',
            url: '/kelola/karyawan/aturan-kehadiran',
            data: {
                WajibJadwal: true,
                PeringatanPengelolaAktif: true,
                PengingatShiftAktif: false,
                ToleransiTerlambatMenit: '10',
                MasukPalingAwalMenit: '60',
            },
        });
    });

    it('D-250: tutup otomatis absensi yang lupa keluar bisa dimatikan dan batas tunggunya diubah', () => {
        RenderUji(<HalamanAturanKehadiran {...Props()} />);

        expect(
            screen
                .getByRole('checkbox', { name: 'Tutup otomatis absensi yang lupa keluar' })
                .getAttribute('aria-checked'),
        ).toBe('true');
        fireEvent.change(screen.getByLabelText(/Tunggu setelah jam selesai \(menit\)/), { target: { value: '90' } });
        fireEvent.click(screen.getByRole('checkbox', { name: 'Tutup otomatis absensi yang lupa keluar' }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan aturan kehadiran' }));

        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'put',
            data: { TutupOtomatisAktif: false, TutupOtomatisSetelahMenit: '90' },
        });
    });

    it('isian menit hanya menerima angka', () => {
        RenderUji(<HalamanAturanKehadiran {...Props()} />);
        fireEvent.change(screen.getByLabelText(/Lembur dihitung setelah \(menit\)/), { target: { value: '4a5' } });

        expect((screen.getByLabelText(/Lembur dihitung setelah \(menit\)/) as HTMLInputElement).value).toBe('45');
    });

    it('tanpa izin ubah: bisa dibaca, tanpa tombol simpan, isian dikunci', () => {
        RenderUji(<HalamanAturanKehadiran {...Props({ Izin: { Kelola: false } })} />);

        expect(screen.getByText('Hanya bisa melihat')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Simpan aturan kehadiran' })).toBeNull();
        expect((screen.getByLabelText(/Toleransi terlambat \(menit\)/) as HTMLInputElement).disabled).toBe(true);
    });

    it('WhatsApp belum aktif: pengaturan tetap bisa disimpan tetapi ada peringatan', () => {
        RenderUji(<HalamanAturanKehadiran {...Props({ WhatsappAktif: false })} />);

        expect(screen.getByText('WhatsApp belum aktif untuk usaha ini')).toBeTruthy();
    });
});
