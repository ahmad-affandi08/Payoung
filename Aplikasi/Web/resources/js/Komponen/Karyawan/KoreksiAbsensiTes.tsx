import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanAbsensi, { RapikanJamKetik } from '@/Halaman/Kelola/Karyawan/Absensi';
import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel } from '@/Komponen/Persediaan/DataUjiPersediaan';
import type { BarisAbsensi } from '@/Tipe/Karyawan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const Outlet = { Uuid: '01J9OTL0000000000000000001', Nama: 'Kopi Senja Solo Baru' };
const Karyawan = { Uuid: '01J9KRY0000000000000000001', Nama: 'Rina Wulandari Kusumaningrum' };

function BarisUji(ubah: Partial<BarisAbsensi> = {}): BarisAbsensi {
    return {
        Uuid: '01J9ABS0000000000000000001',
        TanggalBisnis: '2026-09-30',
        UuidKaryawan: Karyawan.Uuid,
        NamaKaryawan: Karyawan.Nama,
        NamaOutlet: Outlet.Nama,
        JamMasuk: '20:00',
        JamKeluar: '02:30',
        KeluarBeda: true,
        DurasiMenit: 390,
        Jadwal: null,
        TerlambatMenit: 0,
        PulangCepatMenit: 0,
        LemburMenit: 0,
        DiluarJadwal: false,
        PerluDicek: false,
        CatatanPeriksa: null,
        Status: 'TanpaJadwal',
        LabelStatus: 'Tanpa jadwal',
        AdaSwafotoMasuk: false,
        AdaSwafotoKeluar: false,
        Sumber: 'Pos',
        Dikoreksi: false,
        AlasanKoreksi: null,
        JarakMasukMeter: null,
        JarakKeluarMeter: null,
        KemiripanWajahMasuk: null,
        KemiripanWajahKeluar: null,
        ...ubah,
    };
}

function Render(baris: BarisAbsensi[]) {
    RenderUji(
        <HalamanAbsensi Absensi={BuatHasilTabel(baris)} OpsiKaryawan={[Karyawan]} OpsiOutlet={[Outlet]} BolehKoreksi />,
    );
}

describe('Koreksi absensi (F-18, v3.34)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/karyawan/absensi');
        window.history.replaceState({}, '', '/kelola/karyawan/absensi');
    });
    afterEach(() => cleanup());

    it('merapikan jam ketikan 4 angka', () => {
        expect(RapikanJamKetik('0830')).toBe('08:30');
        expect(RapikanJamKetik(' 08:30 ')).toBe('08:30');
        expect(RapikanJamKetik('083')).toBe('083');
    });

    it('absensi manual & dikoreksi diberi tanda beserta alasannya', () => {
        Render([
            BarisUji({ Sumber: 'Manual', Dikoreksi: true, AlasanKoreksi: 'Lupa absen, HP kasir mati' }),
            BarisUji({ Uuid: '01J9ABS0000000000000000002', Dikoreksi: true, AlasanKoreksi: 'Salah tekan' }),
        ]);
        expect(screen.getAllByText('Dicatat manual: Lupa absen, HP kasir mati').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Dikoreksi: Salah tekan').length).toBeGreaterThan(0);
    });

    it('catat absensi terlewat: outlet tunggal terisi otomatis, kirim ke POST /kelola/karyawan/absensi', () => {
        Render([]);
        fireEvent.click(screen.getByRole('button', { name: 'Catat absensi terlewat' }));
        const dialog = screen.getByRole('dialog', { name: 'Catat absensi terlewat' });
        fireEvent.change(within(dialog).getByRole('textbox', { name: /Jam masuk/ }), { target: { value: '0800' } });
        fireEvent.change(within(dialog).getByLabelText(/^Alasan/), { target: { value: 'Lupa absen pagi' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan absensi' }));

        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'post',
            url: '/kelola/karyawan/absensi',
            data: { Outlet: Outlet.Uuid, JamMasuk: '08:00', JamKeluar: '', Alasan: 'Lupa absen pagi' },
        });
    });

    it('koreksi jam dari aksi baris: terisi jam lama & tanggal bisnis, kirim PUT', () => {
        Render([BarisUji()]);
        const [tombolAksi] = screen.getAllByRole('button', { name: `Aksi absensi ${Karyawan.Nama} 30 Sep 2026` });
        expect(tombolAksi).toBeTruthy();
        fireEvent.keyDown(tombolAksi as HTMLElement, { key: 'Enter' });
        fireEvent.click(screen.getByRole('menuitem', { name: 'Koreksi jam' }));
        const dialog = screen.getByRole('dialog', { name: `Koreksi absensi ${Karyawan.Nama}` });
        expect((within(dialog).getByRole('textbox', { name: /Jam masuk/ }) as HTMLInputElement).value).toBe('20:00');
        fireEvent.change(within(dialog).getByRole('textbox', { name: /Jam keluar/ }), { target: { value: '0300' } });
        fireEvent.change(within(dialog).getByLabelText(/^Alasan/), { target: { value: 'Kasir lupa absen keluar' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan koreksi' }));

        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'put',
            url: '/kelola/karyawan/absensi/01J9ABS0000000000000000001',
            data: { Tanggal: '2026-09-30', JamMasuk: '20:00', JamKeluar: '03:00', KeluarHariBerikutnya: true },
        });
    });
});
