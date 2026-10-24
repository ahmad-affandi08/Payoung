import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import PanelKuotaTransaksi, {
    FormatPeriodeKuota,
    type DataKuotaTransaksi,
} from '@/Komponen/Langganan/PanelKuotaTransaksi';

/* P-04: panel kuota transaksi penjualan per bulan di halaman Langganan. */
const dasar: DataKuotaTransaksi = {
    Jangka: 'Bulan',
    Periode: '2026-12',
    Terpakai: 120,
    Batas: 500,
    Sisa: 380,
    Persen: 24,
    Status: 'Normal',
    LabelStatus: 'Aman',
};

describe('PanelKuotaTransaksi', () => {
    afterEach(() => cleanup());

    it('tidak tampil bila paket tanpa batas atau data belum ada', () => {
        const hasil = render(
            <PanelKuotaTransaksi kuota={{ ...dasar, Batas: null, Sisa: null, Persen: null, Status: 'TanpaBatas' }} />,
        );
        expect(hasil.container.innerHTML).toBe('');

        hasil.rerender(<PanelKuotaTransaksi kuota={null} />);
        expect(hasil.container.innerHTML).toBe('');
    });

    it('menampilkan terpakai dari batas, sisa, bulan, dan status berteks', () => {
        render(<PanelKuotaTransaksi kuota={dasar} />);

        expect(screen.getByText('Kuota transaksi bulan ini')).toBeTruthy();
        expect(screen.getByText('Desember 2026')).toBeTruthy();
        expect(screen.getByText('Aman')).toBeTruthy();
        expect(screen.getByText('120')).toBeTruthy();
        expect(screen.getByText('380')).toBeTruthy();
        expect(screen.queryByText('Kuota transaksi hampir habis')).toBeNull();
    });

    it('hampir habis memperingatkan; habis menjelaskan layar jual terkunci dan transaksi lama tetap tersimpan', () => {
        const hasil = render(
            <PanelKuotaTransaksi
                kuota={{
                    ...dasar,
                    Terpakai: 420,
                    Sisa: 80,
                    Persen: 84,
                    Status: 'HampirHabis',
                    LabelStatus: 'Hampir habis',
                }}
            />,
        );
        expect(screen.getByText('Kuota transaksi hampir habis')).toBeTruthy();

        hasil.rerender(
            <PanelKuotaTransaksi
                kuota={{ ...dasar, Terpakai: 500, Sisa: 0, Persen: 100, Status: 'Habis', LabelStatus: 'Habis' }}
            />,
        );
        expect(screen.getByText('Kuota transaksi bulan ini habis')).toBeTruthy();
        expect(screen.getByText(/Layar jual di aplikasi kasir terkunci/)).toBeTruthy();
        expect(screen.getByText(/tetap tersimpan/)).toBeTruthy();
    });

    it('jangka Hari memakai judul hari ini, tanggal bisnis, dan penjelasan hari bisnis', () => {
        render(
            <PanelKuotaTransaksi
                kuota={{
                    ...dasar,
                    Jangka: 'Hari',
                    Periode: '2026-12-13',
                    Terpakai: 100,
                    Batas: 100,
                    Sisa: 0,
                    Persen: 100,
                    Status: 'Habis',
                    LabelStatus: 'Habis',
                }}
            />,
        );

        expect(screen.getByText('Kuota transaksi hari ini')).toBeTruthy();
        expect(screen.getByText('Hari bisnis 13 Desember 2026')).toBeTruthy();
        expect(screen.getByText('Kuota transaksi hari ini habis')).toBeTruthy();
        expect(screen.getByText(/hari bisnis berganti/)).toBeTruthy();
        expect(screen.getByText(/per hari bisnis outlet/)).toBeTruthy();
    });

    it('FormatPeriodeKuota memberi nama bulan Indonesia dan menolak periode tak dikenal dengan apa adanya', () => {
        expect(FormatPeriodeKuota('2026-01')).toBe('Januari 2026');
        expect(FormatPeriodeKuota('2026-12-05')).toBe('5 Desember 2026');
        expect(FormatPeriodeKuota('xx')).toBe('xx');
    });
});
