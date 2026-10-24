import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import TombolEkspor, { BuatAlamatEkspor } from '@/Komponen/Laporan/TombolEkspor';
import { AmbilHrefEkspor, BukaMenu } from '@/Pengujian/InteraksiRadix';

afterEach(cleanup);

describe('TombolEkspor (D-43)', () => {
    it('menawarkan Excel, CSV, dan cetak dengan filter halaman terbawa dan format ditambahkan', () => {
        render(<TombolEkspor alamat="/kelola/laporan/penjualan/ekspor" query="dari=2026-10-01&tab=produk" />);

        expect(AmbilHrefEkspor('xlsx')).toBe('/kelola/laporan/penjualan/ekspor?dari=2026-10-01&tab=produk&format=xlsx');
        expect(AmbilHrefEkspor('csv')).toBe('/kelola/laporan/penjualan/ekspor?dari=2026-10-01&tab=produk&format=csv');
        expect(AmbilHrefEkspor('cetak')).toBe(
            '/kelola/laporan/penjualan/ekspor?dari=2026-10-01&tab=produk&format=cetak',
        );
    });

    it('cetak dibuka di tab baru, unduhan Excel dan CSV tidak', () => {
        render(<TombolEkspor alamat="/laporan/ekspor" />);
        BukaMenu(screen.getByRole('button', { name: 'Ekspor' }));

        expect(screen.getByRole('menuitem', { name: /Cetak/ }).getAttribute('target')).toBe('_blank');
        expect(screen.getByRole('menuitem', { name: /Excel/ }).getAttribute('target')).toBeNull();
        expect(screen.getByRole('menuitem', { name: /CSV/ }).getAttribute('target')).toBeNull();
    });

    it('format tertentu saja bila diminta, dan nonaktif tidak bisa dibuka', () => {
        render(<TombolEkspor alamat="/laporan/ekspor" format={['xlsx', 'csv']} />);
        BukaMenu(screen.getByRole('button', { name: 'Ekspor' }));
        expect(screen.queryByRole('menuitem', { name: /Cetak/ })).toBeNull();
        expect(screen.getAllByRole('menuitem')).toHaveLength(2);
        cleanup();

        render(<TombolEkspor alamat="" nonaktif />);
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Ekspor' }).disabled).toBe(true);
    });

    it('BuatAlamatEkspor mengganti format yang sudah ada, bukan menggandakan', () => {
        expect(BuatAlamatEkspor('/x', 'a=1&format=csv', 'xlsx')).toBe('/x?a=1&format=xlsx');
    });
});
