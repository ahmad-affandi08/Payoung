import { cleanup, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it } from 'vitest';

import { PilihOpsi } from '@/Pengujian/InteraksiPilihan';

import BidangOutlet from './BidangOutlet';

afterEach(() => cleanup());

const solo = { Nilai: 'O-1', Label: 'Outlet Solo Baru' };
const jogja = { Nilai: 'O-2', Label: 'Outlet Yogyakarta' };

function Terkendali({
    opsi,
    required,
    sembunyiBilaTunggal,
}: {
    opsi: { Nilai: string; Label: string }[];
    required?: boolean;
    sembunyiBilaTunggal?: boolean;
}) {
    const [nilai, AturNilai] = useState('');

    return (
        <>
            <BidangOutlet
                nilai={nilai}
                opsi={opsi}
                saatBerubah={AturNilai}
                {...(required === undefined ? {} : { required })}
                {...(sembunyiBilaTunggal === undefined ? {} : { sembunyiBilaTunggal })}
            />
            <output data-testid="nilai">{nilai}</output>
        </>
    );
}

describe('BidangOutlet (outlet bidang pertama. Satu outlet = isi otomatis)', () => {
    it('hanya satu outlet: terisi otomatis dan terkunci', () => {
        render(<Terkendali opsi={[solo]} />);

        expect(screen.getByTestId('nilai').textContent).toBe('O-1');
        const pemicu = screen.getByRole<HTMLButtonElement>('combobox', { name: /Outlet/ });
        expect(pemicu.textContent).toContain('Outlet Solo Baru');
        expect(pemicu.disabled).toBe(true);
    });

    it('lebih dari satu outlet: kosong sampai dipilih pengguna', () => {
        render(<Terkendali opsi={[solo, jogja]} />);

        expect(screen.getByTestId('nilai').textContent).toBe('');
        const pemicu = screen.getByRole<HTMLButtonElement>('combobox', { name: /Outlet/ });
        expect(pemicu.disabled).toBe(false);
        PilihOpsi(pemicu, 'O-2');
        expect(screen.getByTestId('nilai').textContent).toBe('O-2');
    });

    it('outlet boleh kosong (tidak wajib): tidak diisi otomatis walau hanya satu', () => {
        render(<Terkendali opsi={[solo]} required={false} />);

        expect(screen.getByTestId('nilai').textContent).toBe('');
        expect(screen.getByRole<HTMLButtonElement>('combobox', { name: /Outlet/ }).disabled).toBe(false);
    });

    it('sembunyiBilaTunggal: bidang tidak tampil tetapi nilainya tetap terisi', () => {
        render(<Terkendali opsi={[solo]} sembunyiBilaTunggal />);

        expect(screen.queryByRole('combobox', { name: /Outlet/ })).toBeNull();
        expect(screen.getByTestId('nilai').textContent).toBe('O-1');
    });
});
