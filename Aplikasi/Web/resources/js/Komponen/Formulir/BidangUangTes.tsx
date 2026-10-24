import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import BidangUang from './BidangUang';

function BidangUji({ awal = '', saatBerubah }: { awal?: string; saatBerubah?: (nilai: string) => void }) {
    const [nilai, AturNilai] = useState(awal);

    return (
        <BidangUang
            label="Harga jual"
            nilai={nilai}
            saatBerubah={(baru) => {
                AturNilai(baru);
                saatBerubah?.(baru);
            }}
        />
    );
}

describe('BidangUang (F-01: tampil Rp 15.000, dikirim "15000")', () => {
    afterEach(() => cleanup());

    it('menampilkan nilai server dengan titik ribuan, rata kanan, dan keyboard angka', () => {
        render(<BidangUji awal="1250000000.00" />);

        const input = screen.getByLabelText<HTMLInputElement>('Harga jual');

        expect(input.value).toBe('1.250.000.000');
        expect(input.getAttribute('inputmode')).toBe('numeric');
        expect(input.className).toContain('text-right');
        expect(input.className).toContain('tabular-nums');
        expect(screen.getByText('Rp')).toBeTruthy();
    });

    it('meneruskan string desimal polos tanpa pemisah ribuan', () => {
        const SaatBerubah = vi.fn();
        render(<BidangUji saatBerubah={SaatBerubah} />);
        const input = screen.getByLabelText<HTMLInputElement>('Harga jual');

        fireEvent.change(input, { target: { value: 'Rp 15.000' } });

        expect(SaatBerubah).toHaveBeenLastCalledWith('15000');
        expect(input.value).toBe('15.000');

        fireEvent.change(input, { target: { value: '15.0005' } });

        expect(SaatBerubah).toHaveBeenLastCalledWith('150005');
        expect(input.value).toBe('150.005');
    });

    it('mengabaikan ketikan yang bukan nominal Rupiah', () => {
        const SaatBerubah = vi.fn();
        render(<BidangUji awal="12" saatBerubah={SaatBerubah} />);
        const input = screen.getByLabelText<HTMLInputElement>('Harga jual');

        fireEvent.change(input, { target: { value: '12,5' } });
        fireEvent.change(input, { target: { value: '12a' } });

        expect(SaatBerubah).not.toHaveBeenCalled();
        expect(input.value).toBe('12');
    });

    it('masalah terhubung ke input lewat aria-describedby', () => {
        render(<BidangUang label="Harga" nilai="" saatBerubah={() => undefined} galat="Harga wajib diisi." />);

        const input = screen.getByLabelText('Harga');
        const galat = screen.getByText('Harga wajib diisi.');

        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(input.getAttribute('aria-describedby')).toContain(galat.id);
    });
});
