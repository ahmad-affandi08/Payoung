import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import RincianPerubahanAudit from './RincianPerubahanAudit';

describe('RincianPerubahanAudit', () => {
    afterEach(cleanup);

    it('menulis perubahan sebagai "Bidang: lama → baru" dan menyimpan JSON mentah di Data teknis', () => {
        render(
            <RincianPerubahanAudit
                Perubahan={[
                    { Bidang: 'Nama', Lama: 'Kopi', Baru: 'Kopi Susu' },
                    { Bidang: 'Penanggung jawab', Lama: null, Baru: '7' },
                ]}
                NilaiLama={{ Nama: 'Kopi' }}
                NilaiBaru={{ Nama: 'Kopi Susu', IdPenanggungJawab: 7 }}
                Alasan="Koreksi"
            />,
        );

        expect(screen.getByText('Nama:')).toBeTruthy();
        expect(screen.getByText(/^Kopi\s*→/)).toBeTruthy();
        expect(screen.getByText('Kopi Susu')).toBeTruthy();
        expect(screen.getByText('Penanggung jawab:')).toBeTruthy();
        expect(screen.getByText('Alasan: Koreksi')).toBeTruthy();
        expect(screen.getByText('Data teknis')).toBeTruthy();
    });

    it('tanpa perubahan, data teknis, dan alasan menampilkan strip', () => {
        render(<RincianPerubahanAudit Perubahan={[]} NilaiLama={null} NilaiBaru={null} />);

        expect(screen.getByText('—')).toBeTruthy();
        expect(screen.queryByText('Data teknis')).toBeNull();
    });
});
