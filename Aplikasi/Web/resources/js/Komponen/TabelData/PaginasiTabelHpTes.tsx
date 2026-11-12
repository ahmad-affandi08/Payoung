import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import PaginasiTabel from './PaginasiTabel';

const meta = { Halaman: 2, PerHalaman: 25, Total: 61, JumlahHalaman: 3 } as never;

describe('PaginasiTabel di HP (D-280)', () => {
    it('ringkasan di tengah, panah menyebar kiri-kanan dengan "Halaman x dari y" di tengahnya', () => {
        render(
            <PaginasiTabel
                label="Penyesuaian stok"
                meta={meta}
                jumlahBaris={25}
                AturHalaman={vi.fn()}
                AturPerHalaman={vi.fn()}
            />,
        );

        const ringkasan = screen.getByText(/Menampilkan/);
        const sebelumnya = screen.getByRole('button', { name: 'Halaman sebelumnya' });
        const baris = sebelumnya.parentElement as HTMLElement;

        expect(ringkasan.className).toContain('text-center');
        expect(baris.className).toContain('w-full');
        expect(baris.className).toContain('justify-between');
        expect(screen.getByText('Halaman 2 dari 3').className).toContain('flex-1');
    });
});
