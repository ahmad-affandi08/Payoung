import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { RapikanTeksJam } from '@/Pustaka/Tanggal';

import PemilihJam, { AmbilJamSekarang, SusunDaftarMenit, type PilihanCepatJam } from './PemilihJam';

afterEach(() => cleanup());

function Terkendali({
    awal,
    saatBerubah,
    pilihanCepat,
    tombolSekarang,
}: {
    awal: string;
    saatBerubah: (nilai: string) => void;
    pilihanCepat?: PilihanCepatJam[];
    tombolSekarang?: boolean;
}) {
    const [nilai, AturNilai] = useState(awal);

    return (
        <PemilihJam
            label="Jam masuk"
            nilai={nilai}
            saatBerubah={(baru) => {
                saatBerubah(baru);
                AturNilai(baru);
            }}
            {...(pilihanCepat ? { pilihanCepat } : {})}
            {...(tombolSekarang ? { tombolSekarang } : {})}
        />
    );
}

describe('RapikanTeksJam', () => {
    it('membaca ketikan tanpa titik dua & pemisah titik/koma; menolak jam di luar 24 jam', () => {
        expect(['8', '08', '830', '0830', '1730', '17.30', '17,30', '7:05', '23:59'].map(RapikanTeksJam)).toEqual([
            '08:00',
            '08:00',
            '08:30',
            '08:30',
            '17:30',
            '17:30',
            '17:30',
            '07:05',
            '23:59',
        ]);
        expect(['', '24', '2400', '25:00', '1260', '7:5', 'jam 8'].map(RapikanTeksJam)).toEqual(
            Array(7).fill(undefined),
        );
    });

    it('daftar menit per langkah & jam sekarang dibulatkan ke bawah', () => {
        expect(SusunDaftarMenit(15)).toEqual(['00', '15', '30', '45']);
        expect(SusunDaftarMenit(15, '20')).toEqual(['00', '15', '20', '30', '45']);
        expect(AmbilJamSekarang(5, new Date(2026, 9, 4, 9, 47))).toBe('09:45');
    });
});

describe('PemilihJam', () => {
    it('ketik 4 angka langsung jadi JJ:MM; ketikan pendek dirapikan saat ditinggalkan; ketikan salah diberi masalah', () => {
        const SaatBerubah = vi.fn();
        render(<Terkendali awal="" saatBerubah={SaatBerubah} />);
        const isian = screen.getByLabelText<HTMLInputElement>('Jam masuk');

        fireEvent.change(isian, { target: { value: '0830' } });
        expect(SaatBerubah).toHaveBeenLastCalledWith('08:30');
        expect(isian.value).toBe('08:30');

        fireEvent.change(isian, { target: { value: '9' } });
        fireEvent.blur(isian);
        expect(SaatBerubah).toHaveBeenLastCalledWith('09:00');

        fireEvent.change(isian, { target: { value: '25' } });
        fireEvent.blur(isian);
        expect(screen.getByText('Tulis jam sebagai JJ:MM (24 jam), misal 08:30.')).toBeTruthy();
        expect(isian.getAttribute('aria-invalid')).toBe('true');
    });

    it('klik isian membuka panel: pilih jam lalu menit; pilihan cepat & Sekarang menutup panel', () => {
        const SaatBerubah = vi.fn();
        render(
            <Terkendali
                awal="08:30"
                saatBerubah={SaatBerubah}
                pilihanCepat={[{ Label: 'Shift pagi', Nilai: '07:00' }]}
                tombolSekarang
            />,
        );
        const isian = screen.getByLabelText<HTMLInputElement>('Jam masuk');

        fireEvent.click(isian);
        const kolomJam = screen.getByRole('listbox', { name: 'Jam Jam masuk' });
        expect(within(kolomJam).getByRole('option', { name: '08' }).getAttribute('aria-selected')).toBe('true');
        fireEvent.click(within(kolomJam).getByRole('option', { name: '17' }));
        expect(SaatBerubah).toHaveBeenLastCalledWith('17:30');

        fireEvent.click(
            within(screen.getByRole('listbox', { name: 'Menit Jam masuk' })).getByRole('option', { name: '45' }),
        );
        expect(SaatBerubah).toHaveBeenLastCalledWith('17:45');
        expect(screen.queryByRole('listbox', { name: 'Jam Jam masuk' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Pilih Jam masuk' }));
        fireEvent.click(screen.getByRole('button', { name: 'Shift pagi' }));
        expect(SaatBerubah).toHaveBeenLastCalledWith('07:00');
        expect(isian.value).toBe('07:00');

        fireEvent.click(screen.getByRole('button', { name: 'Pilih Jam masuk' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sekarang' }));
        expect(SaatBerubah).toHaveBeenLastCalledWith(expect.stringMatching(/^([01]\d|2[0-3]):[0-5][05]$/));

        fireEvent.click(screen.getByRole('button', { name: 'Kosongkan Jam masuk' }));
        expect(SaatBerubah).toHaveBeenLastCalledWith('');
    });
});
