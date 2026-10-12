import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, kirimanForm, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import PanelTurunPaket, { type DataTurunPaket } from '@/Komponen/Langganan/PanelTurunPaket';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/* BR-P04.4: turun paket mandiri di halaman Langganan Owner. */

const kapasitasAman = {
    Batas: { Outlet: 1, Pengguna: 5, Sku: null, PerangkatPerOutlet: 2 },
    Pakai: { Outlet: 1, Pengguna: 1, Sku: 10, PerangkatMaksimal: 1 },
    Lebih: { Outlet: 0, Pengguna: 0, Sku: 0, Perangkat: 0 },
    Ada: false,
};

const kapasitasLebih = {
    Batas: { Outlet: 1, Pengguna: 5, Sku: null, PerangkatPerOutlet: 2 },
    Pakai: { Outlet: 3, Pengguna: 6, Sku: 10, PerangkatMaksimal: 1 },
    Lebih: { Outlet: 2, Pengguna: 1, Sku: 0, Perangkat: 0 },
    Ada: true,
};

const dasar: DataTurunPaket = {
    Menunggu: null,
    Bisa: true,
    Alasan: null,
    Pilihan: [
        {
            Kode: 'GRATIS',
            Nama: 'Gratis',
            Gratis: true,
            HargaBulanan: '0.00',
            HargaTahunan: '0.00',
            Kapasitas: kapasitasAman,
        },
        {
            Kode: 'STARTER',
            Nama: 'Starter',
            Gratis: false,
            HargaBulanan: '99000.00',
            HargaTahunan: '990000.00',
            Kapasitas: kapasitasLebih,
        },
    ],
    Outlet: [
        { Uuid: 'O-1', Nama: 'Kopi Pusat', JumlahPerangkat: 1 },
        { Uuid: 'O-2', Nama: 'Cabang Solo', JumlahPerangkat: 1 },
        { Uuid: 'O-3', Nama: 'Cabang Klaten', JumlahPerangkat: 0 },
    ],
    PindahGratis: null,
    Anggota: [
        { Uuid: 'A-0', Nama: 'Rina Wulandari', Email: 'rina@kopi.id', Pemilik: true },
        { Uuid: 'A-1', Nama: 'Budi Santoso', Email: 'budi@kopi.id', Pemilik: false },
        { Uuid: 'A-2', Nama: 'Siti Aminah', Email: null, Pemilik: false },
    ],
};

describe('PanelTurunPaket', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/langganan');
        kirimanForm.length = 0;
    });
    afterEach(() => cleanup());

    it('permintaan menunggu: menjelaskan kapan berlaku dan bisa dibatalkan', () => {
        render(
            <PanelTurunPaket
                data={{
                    ...dasar,
                    Bisa: false,
                    Menunggu: {
                        Uuid: 'TP-1',
                        KodePaket: 'STARTER',
                        NamaPaket: 'Starter',
                        Gratis: false,
                        BerlakuPada: '2026-10-03T03:00:00Z',
                        Kelebihan: null,
                    },
                }}
            />,
        );

        expect(screen.getByText(/ditagih dengan paket Starter/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Batalkan turun paket' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/langganan/turun-paket/TP-1/batalkan',
            {},
            expect.anything(),
        );
    });

    it('tanpa kelebihan: cukup memilih paket tujuan, tanpa pilihan outlet atau pengguna', () => {
        render(<PanelTurunPaket data={dasar} />);

        fireEvent.click(screen.getByRole('button', { name: 'Turun paket' }));
        const dialog = screen.getByRole('dialog', { name: 'Turun paket' });
        expect(within(dialog).queryByText(/Outlet yang tetap aktif/)).toBeNull();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Jadwalkan turun paket' }));

        expect(kirimanForm[0]).toMatchObject({
            metode: 'post',
            url: '/kelola/langganan/turun-paket',
            data: { KodePaket: 'GRATIS', UuidOutletTetap: [], UuidAnggotaTetap: [] },
        });
    });

    it('dengan kelebihan: menampilkan peringatan dan pilihan outlet & pengguna yang tetap aktif (Pemilik tidak bisa dipilih)', () => {
        render(
            <PanelTurunPaket data={{ ...dasar, Pilihan: dasar.Pilihan.filter((item) => item.Kode === 'STARTER') }} />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Turun paket' }));
        const dialog = screen.getByRole('dialog', { name: 'Turun paket' });

        expect(within(dialog).getByText('Pemakaian Anda melebihi batas paket tujuan')).toBeTruthy();
        expect(within(dialog).getByText(/Outlet aktif 3 dari batas 1/)).toBeTruthy();
        expect(within(dialog).queryByLabelText('Rina Wulandari | rina@kopi.id')).toBeNull();

        fireEvent.click(within(dialog).getByLabelText('Cabang Solo'));
        fireEvent.click(within(dialog).getByLabelText('Budi Santoso | budi@kopi.id'));
        fireEvent.click(within(dialog).getByRole('button', { name: 'Jadwalkan turun paket' }));

        expect(kirimanForm[0]).toMatchObject({
            metode: 'post',
            url: '/kelola/langganan/turun-paket',
            data: { KodePaket: 'STARTER', UuidOutletTetap: ['O-2'], UuidAnggotaTetap: ['A-1'] },
        });
    });

    it('ditangguhkan karena tunggakan: pindah ke Gratis seketika memakai rute sendiri dan pilihan kapasitas Gratis', () => {
        render(
            <PanelTurunPaket
                data={{ ...dasar, Bisa: false, Pilihan: [], PindahGratis: { Kapasitas: kapasitasLebih } }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Pindah ke paket Gratis' }));
        const dialog = screen.getByRole('dialog', { name: 'Pindah ke paket Gratis' });
        fireEvent.click(within(dialog).getByLabelText('Kopi Pusat'));
        fireEvent.click(within(dialog).getByRole('button', { name: 'Pindah ke Gratis sekarang' }));

        expect(kirimanForm[0]).toMatchObject({
            metode: 'post',
            url: '/kelola/langganan/pindah-gratis',
            data: { UuidOutletTetap: ['O-1'] },
        });
    });

    it('tidak ada pilihan: hanya menampilkan alasan bila ada', () => {
        const { container } = render(
            <PanelTurunPaket
                data={{ ...dasar, Bisa: false, Alasan: 'Paket dengan harga khusus diubah lewat tim kami.' }}
            />,
        );

        expect(container.textContent).toContain('harga khusus');
        expect(screen.queryByRole('button', { name: 'Turun paket' })).toBeNull();
    });
});
