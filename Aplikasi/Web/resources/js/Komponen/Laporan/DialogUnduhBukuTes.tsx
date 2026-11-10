import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import DialogUnduhBuku, { BuatAlamatBuku, BuatDaftarLembar } from '@/Komponen/Laporan/DialogUnduhBuku';
import { tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-255 tahap 2: dialog "Unduh laporan lengkap". Ringkasan selalu ikut, pilihan terakhir diingat di peramban, lembar
 * per hari berganti per bulan untuk rentang panjang, dan alamat unduhan menyiapkan berkas lewat Pusat Unduhan.
 */
const saring = { Dari: '2026-10-01', Sampai: '2026-10-31', Outlet: '' };

function Pasang(sebagian: Partial<{ hariRentang: number; tawarkanPerOutlet: boolean; saatTutup: () => void }> = {}) {
    return render(
        <DialogUnduhBuku
            alamat="/kelola/laporan/pemasukan-pengeluaran"
            saring={saring}
            hariRentang={31}
            tawarkanPerOutlet={false}
            saatTutup={() => undefined}
            {...sebagian}
        />,
    );
}

describe('DialogUnduhBuku (D-255)', () => {
    beforeEach(() => {
        window.localStorage.clear();
        tiruanRouter.get.mockClear();
    });
    afterEach(cleanup);

    it('daftar lembar: Ringkasan terkunci, per hari untuk rentang pendek, per bulan untuk rentang panjang, Per outlet bila ditawarkan', () => {
        const pendek = BuatDaftarLembar(31, false);
        expect(pendek.map((o) => o.judul)).toEqual([
            'Ringkasan',
            'Uang masuk dan uang keluar per kategori',
            'Per hari',
            'Daftar transaksi per hari dan kategori',
            'Hutang dan piutang',
            'Laba rugi',
            'Nilai stok',
            'Penjualan per produk',
        ]);
        expect(pendek[0]?.terkunci).toBe(true);
        expect(BuatDaftarLembar(120, true).map((o) => o.judul)).toContain('Per bulan');
        expect(BuatDaftarLembar(120, true).map((o) => o.judul)).toContain('Per outlet');
    });

    it('alamat unduhan memuat rentang, outlet, lembar, format xlsx, dan latar belakang', () => {
        const alamat = BuatAlamatBuku('/kelola/laporan/pemasukan-pengeluaran', { ...saring, Outlet: 'uuid-1' }, [
            'Ringkasan',
            'Transaksi',
        ]);
        const parameter = new URL(alamat, 'http://x').searchParams;

        expect(alamat.startsWith('/kelola/laporan/pemasukan-pengeluaran/buku?')).toBe(true);
        expect(parameter.get('dari')).toBe('2026-10-01');
        expect(parameter.get('sampai')).toBe('2026-10-31');
        expect(parameter.get('outlet')).toBe('uuid-1');
        expect(parameter.getAll('lembar[]')).toEqual(['Ringkasan', 'Transaksi']);
        expect(parameter.get('format')).toBe('xlsx');
        expect(parameter.get('latarbelakang')).toBe('1');
    });

    it('semua lembar tercentang bawaan, Ringkasan tidak bisa dimatikan', () => {
        Pasang();
        const kotak = screen.getAllByRole('checkbox');

        expect(kotak).toHaveLength(8);
        // Laba rugi, Stok, dan Penjualan per produk (memakai HPP) tidak tercentang bawaan, sisanya tercentang.
        expect(kotak.map((k) => k.getAttribute('aria-checked'))).toEqual([
            'true',
            'true',
            'true',
            'true',
            'true',
            'false',
            'false',
            'false',
        ]);
        expect((kotak[0] as HTMLButtonElement).disabled).toBe(true);
    });

    it('memilih Laba rugi otomatis mencentang dan mengunci Stok, melepasnya melepas Stok lagi', () => {
        Pasang();
        const LabaRugi = () => screen.getByRole('checkbox', { name: /Laba rugi/ });
        const Stok = () => screen.getByRole('checkbox', { name: /Nilai stok/ });

        fireEvent.click(LabaRugi());
        expect(Stok().getAttribute('aria-checked')).toBe('true');
        expect((Stok() as HTMLButtonElement).disabled).toBe(true);

        fireEvent.click(screen.getByRole('button', { name: 'Siapkan Excel' }));
        const alamat = String(tiruanRouter.get.mock.calls[0]?.[0]);
        const lembar = new URL(alamat, 'http://x').searchParams.getAll('lembar[]');

        expect(lembar).toContain('LabaRugi');
        expect(lembar).toContain('Stok');
        // Yang diingat hanya pilihan sendiri.
        expect(JSON.parse(window.localStorage.getItem('Laporan.PemasukanPengeluaran.Lembar') ?? '[]')).not.toContain(
            'Stok',
        );
    });

    it('Siapkan Excel mengirim lembar terpilih, menyimpan pilihan, dan menutup dialog', () => {
        const SaatTutup = vi.fn();
        Pasang({ saatTutup: SaatTutup });

        fireEvent.click(screen.getByRole('checkbox', { name: /Daftar transaksi/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Siapkan Excel' }));

        expect(tiruanRouter.get).toHaveBeenCalledTimes(1);
        const alamat = String(tiruanRouter.get.mock.calls[0]?.[0]);
        const lembar = new URL(alamat, 'http://x').searchParams.getAll('lembar[]');

        expect(lembar).toEqual(['Ringkasan', 'Kategori', 'PerPeriode', 'HutangPiutang']);
        expect(SaatTutup).toHaveBeenCalled();
        expect(JSON.parse(window.localStorage.getItem('Laporan.PemasukanPengeluaran.Lembar') ?? '[]')).toEqual(lembar);
    });

    it('pilihan terakhir diingat saat dialog dibuka lagi', () => {
        window.localStorage.setItem('Laporan.PemasukanPengeluaran.Lembar', JSON.stringify(['Ringkasan', 'Kategori']));
        Pasang();
        const kotak = screen.getAllByRole('checkbox');

        expect(kotak[1]?.getAttribute('aria-checked')).toBe('true');
        expect(kotak[2]?.getAttribute('aria-checked')).toBe('false');
        expect(kotak[3]?.getAttribute('aria-checked')).toBe('false');
        expect(kotak[4]?.getAttribute('aria-checked')).toBe('false');
    });

    it('penyimpanan peramban yang rusak tidak mengganggu: kembali ke pilihan bawaan', () => {
        window.localStorage.setItem('Laporan.PemasukanPengeluaran.Lembar', '{bukan json');
        Pasang();

        const kotak = screen.getAllByRole('checkbox');
        expect(kotak.slice(0, 5).map((k) => k.getAttribute('aria-checked'))).toEqual([
            'true',
            'true',
            'true',
            'true',
            'true',
        ]);
        expect(kotak[5]?.getAttribute('aria-checked')).toBe('false');
    });
});
