import { cleanup, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import type { PropsCetakTransferStok } from '@/Tipe/DokumenPersediaan';

import HalamanCetakTransferStok from './Cetak';
import HalamanCetakAmbilTransferStok from './CetakAmbil';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

function Data(tambahan: Partial<PropsCetakTransferStok['Transfer']> = {}): PropsCetakTransferStok {
    return {
        Transfer: {
            Nomor: 'TRF/20261012/0007',
            Status: 'Dikirim',
            LabelStatus: 'Dikirim',
            Tanggal: '2026-10-12',
            NamaGudangAsal: 'Gudang Pusat Solo',
            NamaOutletAsal: 'Payoung Pusat',
            NamaGudangTujuan: 'Toko Cabang Klaten',
            NamaOutletTujuan: 'Cabang Klaten',
            Catatan: 'Kirim sebelum jam 10',
            AlasanBatal: null,
            NamaPengirim: 'Rina Wulandari',
            DikirimPada: '2026-10-12T02:00:00+07:00',
            ...tambahan,
        },
        Baris: [
            {
                Urutan: 1,
                NamaProduk: 'Minyak Goreng 2 Liter',
                Sku: 'MG-2L',
                SimbolSatuan: 'dus',
                Jumlah: '12.0000',
                NomorBatch: null,
                TanggalKedaluwarsa: null,
                NomorSeri: null,
            },
            {
                Urutan: 2,
                NamaProduk: 'Susu UHT 1 Liter',
                Sku: null,
                SimbolSatuan: 'pcs',
                Jumlah: '5.0000',
                NomorBatch: 'UHT-2609A',
                TanggalKedaluwarsa: '2027-03-31',
                NomorSeri: null,
            },
        ],
        Usaha: { Nama: 'Toko Makmur', Npwp: null },
    };
}

describe('Cetak surat jalan transfer stok (D-94)', () => {
    afterEach(() => cleanup());

    it('memuat nomor, lokasi asal & tujuan, barang dengan batch, kolom Diterima kosong, dan tanda tangan, tanpa nilai uang', () => {
        const { container } = RenderUji(<HalamanCetakTransferStok {...Data()} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Surat Jalan Transfer' })).toBeTruthy();
        expect(screen.getByText('TRF/20261012/0007')).toBeTruthy();
        expect(screen.getByText('Gudang Pusat Solo')).toBeTruthy();
        expect(screen.getByText('Toko Cabang Klaten')).toBeTruthy();
        expect(screen.getByText('Minyak Goreng 2 Liter')).toBeTruthy();
        expect(screen.getByText(/Batch UHT-2609A/)).toBeTruthy();
        expect(screen.getByRole('columnheader', { name: 'Diterima' })).toBeTruthy();
        expect(screen.getByText('Pengirim')).toBeTruthy();
        expect(screen.getByText('Pembawa')).toBeTruthy();
        expect(screen.getByText('Penerima')).toBeTruthy();
        expect(screen.getByText('Rina Wulandari')).toBeTruthy();
        expect(container.textContent).not.toMatch(/Rp\s?\d/);
    });

    it('transfer yang dibatalkan dicetak dengan tanda DIBATALKAN dan alasannya', () => {
        RenderUji(<HalamanCetakTransferStok {...Data({ Status: 'Dibatalkan', AlasanBatal: 'Salah gudang tujuan' })} />);

        expect(screen.getByText(/Dibatalkan/)).toBeTruthy();
        expect(screen.getByText('Salah gudang tujuan')).toBeTruthy();
    });

    it('daftar ambil barang: kotak centang Diambil, tanpa kolom tanda terima & tanpa nilai uang (D-95)', () => {
        const { container } = RenderUji(
            <HalamanCetakAmbilTransferStok {...Data({ Status: 'Draf', LabelStatus: 'Draf', Nomor: null })} />,
        );

        expect(screen.getByRole('heading', { level: 1, name: 'Daftar Ambil Barang' })).toBeTruthy();
        expect(screen.getByText('Ambil dari')).toBeTruthy();
        expect(screen.getByRole('columnheader', { name: 'Diambil' })).toBeTruthy();
        expect(screen.getByText('Petugas pengambil')).toBeTruthy();
        expect(screen.getByText('Pemeriksa')).toBeTruthy();
        expect(screen.queryByText('Pembawa')).toBeNull();
        expect(container.textContent).not.toMatch(/Rp\s?\d/);
    });
});
