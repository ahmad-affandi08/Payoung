import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import Tombol from '@/Komponen/Formulir/Tombol';
import Pemberitahuan, { KelasBilahStatus } from '@/Komponen/Umpan/Pemberitahuan';

describe('Pemberitahuan (D-193, D-202)', () => {
    it('bilah gelap memberi teks gelap pada tombol outline di dalamnya (tombol sekunder tidak kosong)', () => {
        expect(KelasBilahStatus()).toContain('[&_button[data-variant=outline]]:text-teks-utama');

        render(
            <Pemberitahuan jenis="peringatan" judul="Shift ini masih terbuka">
                <Tombol varian="sekunder">Tutup paksa shift</Tombol>
            </Pemberitahuan>,
        );

        expect(screen.getByRole('button', { name: 'Tutup paksa shift' }).getAttribute('data-variant')).toBe('outline');
    });
});
