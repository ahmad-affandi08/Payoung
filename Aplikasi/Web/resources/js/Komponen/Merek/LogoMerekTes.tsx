import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { IkonMerek, LogoMerek } from './LogoMerek';

describe('LogoMerek', () => {
    it('menyediakan varian warna dan putih untuk logo lengkap serta ikon', () => {
        render(
            <>
                <LogoMerek nama="Payoung warna" />
                <LogoMerek nama="Payoung putih" varian="putih" />
                <IkonMerek nama="Ikon Payoung warna" />
                <IkonMerek nama="Ikon Payoung putih" varian="putih" />
            </>,
        );

        expect(screen.getByRole('img', { name: 'Payoung warna' }).getAttribute('src')).toContain('LogoHorizontal.webp');
        expect(screen.getByRole('img', { name: 'Payoung putih' }).getAttribute('src')).toContain(
            'LogoHorizontalPutih.webp',
        );
        expect(screen.getByRole('img', { name: 'Ikon Payoung warna' }).getAttribute('src')).toContain('IkonMerek.webp');
        expect(screen.getByRole('img', { name: 'Ikon Payoung putih' }).getAttribute('src')).toContain(
            'IkonMerekPutih.png',
        );
    });

    it('menjaga perbandingan gambar logo saat wadah menyempit (tidak gepeng)', () => {
        render(<LogoMerek nama="Payoung" className="h-12" />);

        const gambar = screen.getByRole('img', { name: 'Payoung' });

        expect(gambar.className).toContain('w-auto');
        expect(gambar.className).toContain('object-contain');
        expect(gambar.className).toContain('shrink-0');
    });
});
