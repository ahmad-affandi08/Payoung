import { act, cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import HalamanLabelProduk from '@/Halaman/Kelola/Produk/Label';
import { BukaPilihan } from '@/Pengujian/InteraksiPilihan';
import type { BarisLabelServer, PropsLabelProduk } from '@/Tipe/Katalog';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

function BuatBaris(perubahan: Partial<BarisLabelServer> = {}): BarisLabelServer {
    return {
        Uuid: '01JBLABELSATUAN00000000001',
        UuidProduk: '01JBLABELPRODUK00000000001',
        Nama: 'Air Mineral Botol 600 ml',
        Sku: 'AIR-600',
        NamaSatuan: 'Pieces',
        SimbolSatuan: 'pcs',
        KonversiKeDasar: '1.0000',
        Barcode: ['8991234567891'],
        Harga: '4500.00',
        Jumlah: 3,
        ...perubahan,
    };
}

const Dus = BuatBaris({
    Uuid: '01JBLABELSATUAN00000000002',
    NamaSatuan: 'Dus',
    SimbolSatuan: 'dus',
    KonversiKeDasar: '24.0000',
    Barcode: [],
    Harga: '100000.00',
    Jumlah: 2,
});

function PropsLabel(perubahan: Partial<PropsLabelProduk> = {}): PropsLabelProduk {
    return {
        Baris: [BuatBaris(), Dus],
        Terpotong: false,
        Batas: { Baris: 500, JumlahPerBaris: 500, Total: 5000 },
        Izin: { Kelola: true },
        ...perubahan,
    };
}

function PilihMedia(nama: RegExp) {
    BukaPilihan(screen.getByRole('combobox', { name: 'Ukuran dan jenis kertas label' }));
    fireEvent.click(screen.getByRole('option', { name: nama }));
}

describe('Kelola/Produk/Label (PRD-10 cetak label)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/produk/label');
        window.localStorage.clear();
        window.print = vi.fn();
    });
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('menampilkan satu baris per satuan, total stiker, dan menandai barang tanpa barcode', () => {
        RenderUji(<HalamanLabelProduk {...PropsLabel()} />);

        expect(screen.getAllByText('Air Mineral Botol 600 ml').length).toBeGreaterThan(0);
        expect(screen.getAllByText('8991234567891').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Belum punya barcode').length).toBeGreaterThan(0);
        expect(screen.getByText('5 stiker pada 1 lembar.')).toBeTruthy();
        expect(screen.getByText(/1 barang belum punya barcode/)).toBeTruthy();
        expect(screen.getByText(/Barang ini dicetak sebagai label harga saja/)).toBeTruthy();
    });

    it('barang tanpa barcode tidak diberi barcode otomatis: area cetak hanya menggambar barcode untuk yang punya', () => {
        RenderUji(<HalamanLabelProduk {...PropsLabel()} />);
        const area = document.getElementById('area-cetak-label') as HTMLElement;

        expect(area.querySelectorAll('[data-slot="stiker-label"]')).toHaveLength(5);
        // 3 stiker botol berbarcode; 2 stiker dus tanpa barcode = hanya label harga.
        expect(area.querySelectorAll('svg[aria-label="Barcode 8991234567891"]')).toHaveLength(3);
        expect(area.querySelectorAll('svg')).toHaveLength(3);
        expect(area.textContent).toContain('Rp 100.000');
        expect(area.textContent).toContain('Rp 4.500');
    });

    it('area cetak ada di luar #app, memuat @page seukuran media, dan dilepas saat halaman ditinggalkan', () => {
        const { unmount: Bongkar } = RenderUji(<HalamanLabelProduk {...PropsLabel()} />);
        const area = document.getElementById('area-cetak-label') as HTMLElement;

        expect(area.parentElement).toBe(document.body);
        expect(area.querySelector('style')?.textContent).toContain('@page { size: 210mm 297mm; margin: 0; }');
        expect(area.querySelector('style')?.textContent).toContain('body > *:not(#area-cetak-label)');
        Bongkar();
        expect(document.getElementById('area-cetak-label')).toBeNull();
    });

    it('cetak: tombol aktif bila ada stiker, memanggil window.print. Nonaktif bila total 0', () => {
        RenderUji(<HalamanLabelProduk {...PropsLabel()} />);
        const tombol = screen.getByRole('button', { name: 'Cetak label' });

        fireEvent.click(tombol);
        expect(window.print).toHaveBeenCalledTimes(1);

        fireEvent.click(screen.getByRole('button', { name: 'Kosongkan daftar' }));
        expect((screen.getByRole('button', { name: 'Cetak label' }) as HTMLButtonElement).disabled).toBe(true);
        expect(screen.getByText('Tambahkan barang dan isi jumlah stikernya.')).toBeTruthy();
    });

    it('mengubah jumlah stiker, "Terapkan ke semua", dan jumlah dibatasi 500', () => {
        RenderUji(<HalamanLabelProduk {...PropsLabel()} />);

        fireEvent.change(screen.getByLabelText('Jumlah stiker Air Mineral Botol 600 ml Pieces'), {
            target: { value: '10' },
        });
        expect(screen.getByText('12 stiker pada 1 lembar.')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Jumlah stiker Air Mineral Botol 600 ml Pieces'), {
            target: { value: '9999' },
        });
        expect(screen.getByText('502 stiker pada 8 lembar.')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Jumlah stiker untuk semua'), { target: { value: '4' } });
        fireEvent.click(screen.getByRole('button', { name: 'Terapkan ke semua' }));
        expect(screen.getByText('8 stiker pada 1 lembar.')).toBeTruthy();
    });

    it('lebih dari 5.000 stiker diblokir dengan alasan. Lembar A4 65 stiker membagi halaman. Lewati stiker pertama', () => {
        RenderUji(<HalamanLabelProduk {...PropsLabel({ Baris: [BuatBaris({ Jumlah: 65 })] })} />);
        expect(screen.getByText('65 stiker pada 1 lembar.')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Lewati stiker pertama'), { target: { value: '1' } });
        expect(screen.getByText('65 stiker pada 2 lembar.')).toBeTruthy();
        expect(
            document.getElementById('area-cetak-label')?.querySelectorAll('[data-slot="halaman-label"]'),
        ).toHaveLength(2);
    });

    it('media gulung: satu halaman per stiker, @page seukuran stiker, tanpa kontrol lewati', () => {
        RenderUji(<HalamanLabelProduk {...PropsLabel()} />);

        PilihMedia(/Gulung \| 58 × 40 mm/);

        expect(screen.getByText('5 stiker pada 5 label.')).toBeTruthy();
        expect(screen.queryByLabelText('Lewati stiker pertama')).toBeNull();
        expect(document.getElementById('area-cetak-label')?.querySelector('style')?.textContent).toContain(
            'size: 58mm 40mm',
        );
    });

    it('ukuran sendiri yang tidak sah memblokir cetak dan menjelaskan alasannya', () => {
        RenderUji(<HalamanLabelProduk {...PropsLabel()} />);

        PilihMedia(/A4 \| ukuran stiker sendiri/);
        fireEvent.change(screen.getByLabelText('Lebar stiker (mm)'), { target: { value: '5' } });

        expect(screen.getByText('Ukuran label belum sah')).toBeTruthy();
        expect(screen.getByText(/Ukuran stiker minimal 10 mm/)).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Cetak label' }) as HTMLButtonElement).disabled).toBe(true);

        fireEvent.change(screen.getByLabelText('Lebar stiker (mm)'), { target: { value: '60' } });
        expect(screen.queryByText('Ukuran label belum sah')).toBeNull();
        expect((screen.getByRole('button', { name: 'Cetak label' }) as HTMLButtonElement).disabled).toBe(false);
    });

    it('memperingatkan barcode terlalu rapat untuk label sempit, dan harga yang belum ada', () => {
        RenderUji(
            <HalamanLabelProduk
                {...PropsLabel({ Baris: [BuatBaris({ Barcode: ['AB-1234567890-CD'], Harga: null, Jumlah: 1 })] })}
            />,
        );

        PilihMedia(/Gulung \| 33 × 15 mm, 3 berdampingan/);

        expect(screen.getByText('Barcode terlalu rapat untuk lebar label')).toBeTruthy();
        expect(screen.getByText('1 barang belum punya harga')).toBeTruthy();
        expect(screen.getByText('Isi stiker terlalu banyak untuk tinggi label')).toBeTruthy();
    });

    it('pilihan media dan isi diingat di peramban (localStorage) dan dipulihkan', () => {
        const { unmount: Bongkar } = RenderUji(<HalamanLabelProduk {...PropsLabel()} />);

        PilihMedia(/Gulung \| 50 × 30 mm/);
        fireEvent.click(screen.getByRole('checkbox', { name: 'SKU' }));
        Bongkar();
        cleanup();

        RenderUji(<HalamanLabelProduk {...PropsLabel()} />);
        expect(screen.getByRole('combobox', { name: 'Ukuran dan jenis kertas label' }).textContent).toContain(
            '50 × 30 mm',
        );
        expect(screen.getByRole('checkbox', { name: 'SKU' }).getAttribute('data-state')).toBe('checked');
    });

    it('penyimpanan rusak diabaikan: kembali ke pilihan bawaan tanpa masalah', () => {
        window.localStorage.setItem('payoung.cetak-label.v1', '{"KodeMedia":"hacker"');
        RenderUji(<HalamanLabelProduk {...PropsLabel()} />);

        expect(screen.getByRole('combobox', { name: 'Ukuran dan jenis kertas label' }).textContent).toContain(
            '65 stiker',
        );
    });

    it('buat barcode: konfirmasi dulu, lalu POST ke server dan barcode muncul di baris', async () => {
        const Ambil = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ Data: [{ Uuid: Dus.Uuid, Barcode: '2000000000015', Baru: true }] }),
        });
        vi.stubGlobal('fetch', Ambil);
        RenderUji(<HalamanLabelProduk {...PropsLabel()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Buat barcode' }));
        const dialog = await screen.findByRole('alertdialog');
        expect(within(dialog).getByText(/hanya untuk barang yang memang tidak punya barcode dari pabrik/)).toBeTruthy();
        expect(Ambil).not.toHaveBeenCalled();

        fireEvent.click(within(dialog).getByRole('button', { name: 'Buat barcode' }));

        await waitFor(() => expect(screen.getAllByText('2000000000015').length).toBeGreaterThan(0));
        expect(Ambil).toHaveBeenCalledTimes(1);
        expect(Ambil.mock.calls[0]?.[0]).toBe('/kelola/produk/label/barcode-internal');
        expect(JSON.parse((Ambil.mock.calls[0]?.[1] as { body: string }).body)).toEqual({ Satuan: [Dus.Uuid] });
        expect(screen.queryByRole('alertdialog')).toBeNull();
        expect(document.getElementById('area-cetak-label')?.querySelectorAll('svg')).toHaveLength(5);
    });

    it('buat barcode gagal: dialog tetap terbuka dengan pesan server dan tidak ada barcode palsu', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: false,
                status: 422,
                json: () =>
                    Promise.resolve({
                        Galat: {
                            Kode: 'ProdukDiarsipkan',
                            Pesan: 'Produk yang sudah diarsipkan tidak bisa diberi barcode.',
                        },
                    }),
            }),
        );
        RenderUji(<HalamanLabelProduk {...PropsLabel()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Buat barcode' }));
        const dialog = await screen.findByRole('alertdialog');
        await act(async () => {
            fireEvent.click(within(dialog).getByRole('button', { name: 'Buat barcode' }));
        });

        expect(await screen.findByText('Produk yang sudah diarsipkan tidak bisa diberi barcode.')).toBeTruthy();
        expect(screen.getByRole('alertdialog')).toBeTruthy();
        expect(screen.getAllByText('Belum punya barcode').length).toBeGreaterThan(0);
    });

    it('tanpa izin kelola produk: tidak ada tombol buat barcode, cetak tetap bisa', () => {
        RenderUji(<HalamanLabelProduk {...PropsLabel({ Izin: { Kelola: false } })} />);

        expect(screen.queryByRole('button', { name: 'Buat barcode' })).toBeNull();
        expect(screen.queryByRole('button', { name: /Buat barcode untuk yang belum punya/ })).toBeNull();
        expect((screen.getByRole('button', { name: 'Cetak label' }) as HTMLButtonElement).disabled).toBe(false);
    });

    it('lebih dari satu barcode satu satuan: pilihan barcode yang dicetak', () => {
        RenderUji(
            <HalamanLabelProduk
                {...PropsLabel({ Baris: [BuatBaris({ Barcode: ['8991234567891', 'AIR-600-ALT'], Jumlah: 1 })] })}
            />,
        );

        expect(document.getElementById('area-cetak-label')?.textContent).toContain('8991234567891');
        BukaPilihan(screen.getByRole('combobox', { name: 'Barcode Air Mineral Botol 600 ml Pieces' }));
        fireEvent.click(screen.getByRole('option', { name: 'AIR-600-ALT' }));

        expect(document.getElementById('area-cetak-label')?.textContent).toContain('AIR-600-ALT');
        expect(document.getElementById('area-cetak-label')?.textContent).not.toContain('8991234567891');
    });

    it('mencari barang menambah baris. Memilih barang yang sudah ada menambah satu stiker', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: () => Promise.resolve({ Data: [BuatBaris({ Jumlah: 1 })] }),
            }),
        );
        RenderUji(<HalamanLabelProduk {...PropsLabel({ Baris: [] })} />);

        BukaPilihan(screen.getByRole('combobox', { name: 'Tambah barang' }));
        fireEvent.click(await screen.findByRole('option', { name: /Air Mineral Botol 600 ml \(pcs\)/ }));
        expect(screen.getByText('1 stiker pada 1 lembar.')).toBeTruthy();

        BukaPilihan(screen.getByRole('combobox', { name: 'Tambah barang' }));
        fireEvent.click(await screen.findByRole('option', { name: /Air Mineral Botol 600 ml \(pcs\)/ }));
        expect(screen.getByText('2 stiker pada 1 lembar.')).toBeTruthy();
    });

    it('daftar terpotong dari server diberitahukan', () => {
        RenderUji(<HalamanLabelProduk {...PropsLabel({ Terpotong: true })} />);

        expect(screen.getByText('Daftar dipotong')).toBeTruthy();
    });
});
