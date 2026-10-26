import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { BuatPresetTanggal, TulisTanggal } from '@/Pustaka/Tanggal';

import PemilihRentangTanggal, { PanelRentangTanggal, RingkasRentang } from './PemilihRentangTanggal';
import PemilihTanggal from './PemilihTanggal';
import PemilihTanggalWaktu from './PemilihTanggalWaktu';

afterEach(() => cleanup());

function Terkendali<T extends 'tunggal' | 'waktu' | 'rentang'>({
    jenis,
    awal,
    saatBerubah,
    min,
    max,
}: {
    jenis: T;
    awal: string;
    saatBerubah: (nilai: string) => void;
    min?: string;
    max?: string;
}) {
    const [nilai, AturNilai] = useState(awal);
    const Ubah = (baru: string) => {
        saatBerubah(baru);
        AturNilai(baru);
    };

    if (jenis === 'waktu') {
        return <PemilihTanggalWaktu label="Mulai berlaku" nilai={nilai} saatBerubah={Ubah} />;
    }

    if (jenis === 'rentang') {
        return <PanelRentangTanggal label="Tanggal" nilai={nilai} saatBerubah={Ubah} satuBulan />;
    }

    return <PemilihTanggal label="Tanggal transfer" nilai={nilai} saatBerubah={Ubah} min={min} max={max} />;
}

describe('PemilihTanggal (§17.6.7 tampilan 22/09/2026)', () => {
    it('menampilkan HH/BB/TTTT, mengirim TTTT-BB-HH, dan memberi masalah ketikan saat keluar', () => {
        const SaatBerubah = vi.fn();
        render(<Terkendali jenis="tunggal" awal="2026-09-04" saatBerubah={SaatBerubah} />);
        const isian = screen.getByLabelText<HTMLInputElement>('Tanggal transfer');

        expect(isian.value).toBe('04/09/2026');
        fireEvent.change(isian, { target: { value: '31/02/2026' } });
        fireEvent.blur(isian);
        expect(SaatBerubah).not.toHaveBeenCalled();
        expect(screen.getByText('Tulis tanggal sebagai HH/BB/TTTT, misal 24/09/2026.')).toBeTruthy();
        expect(isian.getAttribute('aria-invalid')).toBe('true');

        fireEvent.change(isian, { target: { value: '1-10-2026' } });
        expect(SaatBerubah).toHaveBeenLastCalledWith('2026-10-01');
        fireEvent.blur(isian);
        expect(isian.value).toBe('01/10/2026');
        expect(screen.queryByText(/Tulis tanggal sebagai/)).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Kosongkan Tanggal transfer' }));
        expect(SaatBerubah).toHaveBeenLastCalledWith('');
        expect(isian.value).toBe('');
    });

    it('batas max: ketikan setelah batas tidak dikirim dan hari di kalender dinonaktifkan', () => {
        const SaatBerubah = vi.fn();
        render(<Terkendali jenis="tunggal" awal="2026-09-10" max="2026-09-15" saatBerubah={SaatBerubah} />);
        const isian = screen.getByLabelText<HTMLInputElement>('Tanggal transfer');

        fireEvent.change(isian, { target: { value: '20/09/2026' } });
        fireEvent.blur(isian);
        expect(SaatBerubah).not.toHaveBeenCalled();
        expect(screen.getByText('Tanggal paling akhir 15 Sep 2026.')).toBeTruthy();

        fireEvent.keyDown(isian, { key: 'ArrowDown', altKey: true });
        const kalender = screen.getByRole('grid');
        expect(within(kalender).getByText('16').closest('button')?.disabled).toBe(true);
        fireEvent.click(within(kalender).getByText('12'));
        expect(SaatBerubah).toHaveBeenLastCalledWith('2026-09-12');
    });

    it('tombol Hari ini mengisi tanggal hari ini', () => {
        const SaatBerubah = vi.fn();
        render(<Terkendali jenis="tunggal" awal="" saatBerubah={SaatBerubah} />);

        fireEvent.click(screen.getByRole('button', { name: 'Pilih Tanggal transfer dari kalender' }));
        fireEvent.click(screen.getByRole('button', { name: 'Hari ini' }));
        expect(SaatBerubah).toHaveBeenLastCalledWith(TulisTanggal(new Date()));
    });
});

describe('PemilihTanggalWaktu', () => {
    it('tanggal + jam 24 jam. Tempelan datetime-local diterima utuh', () => {
        const SaatBerubah = vi.fn();
        render(<Terkendali jenis="waktu" awal="" saatBerubah={SaatBerubah} />);
        const tanggal = screen.getByLabelText<HTMLInputElement>('Mulai berlaku');
        const jam = screen.getByLabelText<HTMLInputElement>('Jam Mulai berlaku');

        expect(jam.disabled).toBe(true);
        fireEvent.change(tanggal, { target: { value: '01/11/2026' } });
        expect(SaatBerubah).toHaveBeenLastCalledWith('2026-11-01T00:00');

        fireEvent.change(jam, { target: { value: '8.30' } });
        expect(SaatBerubah).toHaveBeenLastCalledWith('2026-11-01T08:30');

        fireEvent.change(tanggal, { target: { value: '2026-12-24T19:45' } });
        expect(SaatBerubah).toHaveBeenLastCalledWith('2026-12-24T19:45');
        expect(tanggal.value).toBe('24/12/2026');
        expect(jam.value).toBe('19:45');

        fireEvent.change(tanggal, { target: { value: '' } });
        expect(SaatBerubah).toHaveBeenLastCalledWith('');
    });
});

describe('PemilihRentangTanggal (§17.6.5)', () => {
    it('preset, klik kalender awal lalu akhir (ke arah mana pun), dan isian Dari/Sampai', () => {
        const SaatBerubah = vi.fn();
        render(<Terkendali jenis="rentang" awal="2026-09-10..2026-09-10" saatBerubah={SaatBerubah} />);

        const preset = BuatPresetTanggal();
        fireEvent.click(screen.getByRole('button', { name: '7 hari terakhir' }));
        expect(SaatBerubah).toHaveBeenLastCalledWith(preset[2]?.nilai);
        expect(screen.getByRole('button', { name: '7 hari terakhir' }).getAttribute('aria-pressed')).toBe('true');

        fireEvent.change(screen.getByLabelText('Dari'), { target: { value: '05/09/2026' } });
        fireEvent.change(screen.getByLabelText('Sampai'), { target: { value: '20/09/2026' } });
        expect(SaatBerubah).toHaveBeenLastCalledWith('2026-09-05..2026-09-20');

        const kalender = screen.getByRole('grid');
        fireEvent.click(within(kalender).getByText('18'));
        expect(SaatBerubah).toHaveBeenLastCalledWith('2026-09-18..2026-09-18');
        // Hari luar bulan (Okt) ikut tampil; ambil 3 September (kemunculan pertama).
        fireEvent.click(within(screen.getByRole('grid')).getAllByText('3')[0] ?? document.body);
        expect(SaatBerubah).toHaveBeenLastCalledWith('2026-09-03..2026-09-18');
    });

    it('ringkasan tombol: preset, satu hari, rentang, sejak/sampai', () => {
        const hariIni = new Date(2026, 8, 24);

        expect(RingkasRentang('2026-09-24..2026-09-24', hariIni)).toBe('Hari ini');
        expect(RingkasRentang('2026-01-02..2026-01-02', hariIni)).toBe('2 Jan 2026');
        expect(RingkasRentang('2026-01-02..2026-01-05', hariIni)).toBe('2 Jan 2026 – 5 Jan 2026');
        expect(RingkasRentang('2026-01-02..', hariIni)).toBe('sejak 2 Jan 2026');
        expect(RingkasRentang('..2026-01-05', hariIni)).toBe('sampai 5 Jan 2026');
        expect(RingkasRentang('', hariIni)).toBe('');
    });

    it('bidang form: tombol ringkasan membuka panel; Kosongkan menghapus nilai', () => {
        const SaatBerubah = vi.fn();
        render(<PemilihRentangTanggal label="Periode" nilai="2026-01-02..2026-01-05" saatBerubah={SaatBerubah} />);

        const tombol = screen.getByRole('button', { name: 'Periode' });
        expect(tombol.textContent).toContain('2 Jan 2026 – 5 Jan 2026');
        fireEvent.click(tombol);
        fireEvent.click(screen.getByRole('button', { name: 'Kosongkan' }));
        expect(SaatBerubah).toHaveBeenLastCalledWith('');
    });
});

describe('Penjaga: isian tanggal bawaan peramban tidak dipakai (§17.6)', () => {
    it('tidak ada type="date" / "datetime-local" di Halaman & Komponen', () => {
        const berkas = import.meta.glob(['/resources/js/Halaman/**/*.tsx', '/resources/js/Komponen/**/*.tsx'], {
            query: '?raw',
            import: 'default',
            eager: true,
        });
        const pelanggar = Object.entries(berkas)
            .filter(([jalur]) => !jalur.endsWith('Tes.tsx'))
            .filter(([, isi]) => /type=["'](date|datetime-local)["']/.test(String(isi)))
            .map(([jalur]) => jalur);

        expect(Object.keys(berkas).length).toBeGreaterThan(50);
        expect(pelanggar).toEqual([]);
    });
});
