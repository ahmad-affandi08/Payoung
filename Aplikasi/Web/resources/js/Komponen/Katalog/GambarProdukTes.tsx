import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import GambarProduk, { AmbilInisialProduk } from './GambarProduk';

afterEach(() => cleanup());

describe('GambarProduk', () => {
    it('menampilkan foto produk bila ada, sebagai gambar dekoratif yang dimuat malas', () => {
        const { container } = render(<GambarProduk nama="Es Kopi Susu" url="https://contoh.test/kopi.jpg" />);

        const gambar = container.querySelector('img');
        expect(gambar?.getAttribute('src')).toBe('https://contoh.test/kopi.jpg');
        expect(gambar?.getAttribute('alt')).toBe('');
        expect(gambar?.getAttribute('loading')).toBe('lazy');
        expect(gambar?.className).toContain('object-cover');
        expect(container.textContent).toBe('');
    });

    it('tanpa foto: inisial dua kata pertama, dekoratif dan tidak dibacakan', () => {
        const { container } = render(<GambarProduk nama="Es Kopi Susu" url={null} />);

        const inisial = container.querySelector('span');
        expect(container.querySelector('img')).toBeNull();
        expect(inisial?.textContent).toBe('EK');
        expect(inisial?.getAttribute('aria-hidden')).toBe('true');
        expect(inisial?.className).toContain('text-label');
        expect(inisial?.className).toContain('font-mono');
    });

    it('ukuran sedang memakai skala teks kepala halaman', () => {
        const { container } = render(<GambarProduk nama="Teh" url={null} ukuran="sedang" />);

        expect(container.querySelector('span')?.className).toContain('text-subjudul');
    });

    it('ukuran besar memakai skala judul dan kelas wadah dari pemanggil', () => {
        const { container } = render(
            <GambarProduk
                nama="Teh"
                url={null}
                ukuran="besar"
                className="flex aspect-[4/3] w-full bg-permukaan-sorot"
            />,
        );

        expect(container.querySelector('span')?.className).toContain('text-judul');
        const wadah = container.firstElementChild;
        expect(wadah?.className).toContain('items-center');
        expect(wadah?.className).toContain('aspect-[4/3]');
    });

    it('inisial: satu kata → satu huruf, nama kosong/spasi → "?"', () => {
        expect(AmbilInisialProduk('Teh')).toBe('T');
        expect(AmbilInisialProduk('  Es   Kopi Susu ')).toBe('EK');
        expect(AmbilInisialProduk('')).toBe('?');
        expect(AmbilInisialProduk('   ')).toBe('?');
    });
});
