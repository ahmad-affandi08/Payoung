import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarPreOrder from '@/Halaman/Kelola/PreOrder/Daftar';
import HalamanDetailPreOrder, { CekPositif } from '@/Halaman/Kelola/PreOrder/Detail';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel } from '@/Komponen/Persediaan/DataUjiPersediaan';
import type { BarisPreOrder, DetailPreOrder } from '@/Tipe/PreOrder';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const baris: BarisPreOrder = {
    Uuid: '01K5PREORDER00000000000001',
    Nomor: 'SO/SLO/260925/POS-001-0001',
    Status: 'Siap',
    LabelStatus: 'Siap diambil',
    TanggalAmbil: '2026-09-28',
    TanggalPesan: '2026-09-25',
    Pelanggan: 'Ibu Ratna Kusuma',
    NoHpPelanggan: '0813****0077',
    Outlet: 'Solo',
    TotalPesanan: '77000.00',
    UangMuka: '50000.00',
    SisaUangMuka: '50000.00',
};

const detail: DetailPreOrder = {
    ...baris,
    Catatan: 'Tulisan: Selamat ulang tahun Dimas ke-7',
    DipesanPada: '2026-09-25T03:00:00Z',
    SiapPada: '2026-09-27T03:00:00Z',
    DiambilPada: null,
    DibatalkanPada: null,
    AlasanBatal: null,
    UangMukaTerpakai: '0.00',
    UangMukaDikembalikan: '0.00',
    UangMukaHangus: '0.00',
    Penjualan: null,
    Baris: [
        {
            Uuid: '01K5PREORDERBARIS000000001',
            NamaProduk: 'Kue Ulang Tahun Cokelat Diameter 20 cm',
            Jumlah: '2.0000',
            HargaSatuan: '38500.00',
            HargaPilihan: '0.00',
            Pilihan: [],
            Catatan: 'Krim vanila',
        },
    ],
    Pembayaran: [{ Uuid: '01K5PREORDERBAYAR000000001', Metode: 'Tunai', Jumlah: '50000.00', Referensi: null }],
};

describe('Halaman pre-order (F-12 bagian 2)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/pre-order');
        window.history.replaceState({}, '', '/kelola/pre-order');
    });
    afterEach(() => cleanup());

    it('daftar: nomor, pelanggan, status, dan uang muka tampil', () => {
        expect(CekPositif('0.00')).toBe(false);
        expect(CekPositif('50000.00')).toBe(true);
        expect(CekPositif('-5.00')).toBe(false);
        RenderUji(
            <HalamanDaftarPreOrder
                Pesanan={BuatHasilTabel([baris])}
                OpsiStatus={[{ Nilai: 'Siap', Label: 'Siap diambil' }]}
            />,
        );
        expect(screen.getAllByText('SO/SLO/260925/POS-001-0001').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Ibu Ratna Kusuma').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Rp 50.000').length).toBeGreaterThan(0);
    });

    it('detail: batalkan dengan DP dikembalikan dari akun bank dikirim sebagai POST, tanpa izin tombol tersembunyi', () => {
        window.history.replaceState({}, '', `/kelola/pre-order/${detail.Uuid}`);
        RenderUji(
            <HalamanDetailPreOrder
                Pesanan={detail}
                OpsiAkun={[{ Uuid: '01K5AKUNBANK00000000000001', Kode: '1-1200', Nama: 'Bank BCA' }]}
                OpsiCara={[
                    { Nilai: 'Dikembalikan', Label: 'Dikembalikan ke pelanggan' },
                    { Nilai: 'Hangus', Label: 'Hangus (pendapatan lain)' },
                ]}
                Izin={{ Siap: true, Selesaikan: true }}
            />,
        );
        expect(screen.getByText('Krim vanila', { exact: false })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Tandai siap diambil' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Batalkan pre-order' }));
        fireEvent.change(screen.getByLabelText('Alasan'), { target: { value: 'Pelanggan batal acara' } });
        fireEvent.click(screen.getAllByRole('button', { name: 'Batalkan pre-order' }).at(-1) as HTMLElement);
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/pre-order/${detail.Uuid}/selesaikan-uang-muka`,
            { Cara: 'Dikembalikan', UuidAkun: '01K5AKUNBANK00000000000001', Alasan: 'Pelanggan batal acara' },
            expect.anything(),
        );

        cleanup();
        RenderUji(
            <HalamanDetailPreOrder
                Pesanan={{ ...detail, Status: 'Dipesan' }}
                OpsiAkun={[]}
                OpsiCara={[]}
                Izin={{ Siap: true, Selesaikan: false }}
            />,
        );
        expect(screen.getByRole('button', { name: 'Tandai siap diambil' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Batalkan pre-order' })).toBeNull();
    });
});
