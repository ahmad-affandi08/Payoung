import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import PilihLamaLangganan, {
    CariHargaLama,
    PilihanCepatLama,
    UraiLama,
    type AturanLamaLangganan,
    type HargaPerLama,
} from '@/Komponen/Langganan/PilihLamaLangganan';

const aturan: AturanLamaLangganan = {
    MinimalBulan: 1,
    MaksimalBulan: 12,
    TanggaDiskon: [
        { DariBulan: 3, Persen: '7' },
        { DariBulan: 6, Persen: '12' },
    ],
};

const harga: HargaPerLama[] = [
    { JumlahBulan: 1, Subtotal: '100000.00', HargaPenuh: '100000.00', DiskonPersen: '0' },
    { JumlahBulan: 2, Subtotal: '200000.00', HargaPenuh: '200000.00', DiskonPersen: '0' },
    { JumlahBulan: 3, Subtotal: '279000.00', HargaPenuh: '300000.00', DiskonPersen: '7' },
];

afterEach(() => cleanup());

describe('PilihLamaLangganan (D-249)', () => {
    it('pilihan cepat diturunkan dari aturan server: batas, setiap anak tangga, dan batas atas', () => {
        expect(PilihanCepatLama(aturan)).toEqual([1, 3, 6, 12]);
        expect(
            PilihanCepatLama({ MinimalBulan: 2, MaksimalBulan: 4, TanggaDiskon: [{ DariBulan: 6, Persen: '5' }] }),
        ).toEqual([2, 4]);
        expect(PilihanCepatLama({ MinimalBulan: 3, MaksimalBulan: 3, TanggaDiskon: [] })).toEqual([3]);
    });

    it('lama sah hanya bilangan bulat dalam rentang aturan', () => {
        expect(UraiLama('2', aturan)).toBe(2);
        expect(UraiLama('12', aturan)).toBe(12);
        expect(UraiLama('0', aturan)).toBeNull();
        expect(UraiLama('13', aturan)).toBeNull();
        expect(UraiLama('2,5', aturan)).toBeNull();
        expect(UraiLama('', aturan)).toBeNull();
    });

    it('mencari harga server untuk lama terpilih', () => {
        expect(CariHargaLama(harga, 3)?.Subtotal).toBe('279000.00');
        expect(CariHargaLama(harga, 5)).toBeNull();
        expect(CariHargaLama(harga, null)).toBeNull();
    });

    it('mengetik 2 bulan menampilkan harga server tanpa diskon, 3 bulan menampilkan hemat', () => {
        const SaatBerubah = vi.fn();
        const { rerender: UlangRender } = render(
            <PilihLamaLangganan aturan={aturan} nilai="2" saatBerubah={SaatBerubah} harga={harga} />,
        );

        expect(screen.getByText(/2 bulan:/)).toBeTruthy();
        expect(screen.getByText('Rp 200.000')).toBeTruthy();
        expect(screen.queryByText(/Hemat/)).toBeNull();

        UlangRender(<PilihLamaLangganan aturan={aturan} nilai="3" saatBerubah={SaatBerubah} harga={harga} />);
        expect(screen.getByText('Rp 279.000')).toBeTruthy();
        expect(screen.getByText('Hemat 7%')).toBeTruthy();
    });

    it('isian hanya menerima angka dan pilihan cepat mengisi lama', () => {
        const SaatBerubah = vi.fn();
        render(<PilihLamaLangganan aturan={aturan} nilai="2" saatBerubah={SaatBerubah} harga={harga} />);

        fireEvent.change(screen.getByLabelText(/Lama langganan \(bulan\)/), { target: { value: '4a' } });
        expect(SaatBerubah).toHaveBeenLastCalledWith('4');
        fireEvent.click(screen.getByRole('button', { name: '6 bulan' }));
        expect(SaatBerubah).toHaveBeenLastCalledWith('6');
    });

    it('lama di luar rentang menampilkan pesan dan tidak menampilkan harga', () => {
        render(<PilihLamaLangganan aturan={aturan} nilai="40" saatBerubah={vi.fn()} harga={harga} />);

        expect(screen.getAllByText(/Isi bebas 1 sampai 12 bulan/).length).toBeGreaterThan(0);
        expect(screen.queryByText(/Rp /)).toBeNull();
    });
});
