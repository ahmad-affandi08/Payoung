import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanPortalKurir from '@/Halaman/Publik/PortalKurir';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * F-10 (v3.49) portal kurir: kartu pengiriman berjalan berisi telepon & alamat; Dikemas → "Berangkat antar";
 * Dikirim → serah terima (nama penerima + foto, dikirim sebagai FormData) atau gagal dengan alasan; yang sudah selesai
 * tampil ringkas tanpa data kontak.
 */

const dasar = 'https://dashboard.payoung.id/kopi-senja/kurir/' + 'a'.repeat(40);

function Pengiriman(status: 'Dikemas' | 'Dikirim' | 'Diterima', uuid: string, nomor: string) {
    const berjalan = status !== 'Diterima';
    return {
        Uuid: uuid,
        Nomor: nomor,
        Status: status,
        LabelStatus: status === 'Dikemas' ? 'Dikemas' : status === 'Dikirim' ? 'Dikirim' : 'Diterima',
        NamaPelanggan: 'Bu Ratna Sari Dewi',
        NoHp: berjalan ? '6281234567890' : null,
        Alamat: berjalan ? 'Jl. Merdeka 10, Braga, Sumur Bandung, Bandung, 40123' : null,
        Catatan: berjalan ? 'Pagar hijau' : null,
        MetodePembayaran: 'Cod',
        Total: '72000.00',
        SudahDibayar: false,
        Baris: [{ NamaProduk: 'Kopi Susu Gula Aren', Jumlah: '2.0000' }],
        NamaPenerima: berjalan ? null : 'Pak Darto',
        AdaBukti: !berjalan,
    };
}

beforeEach(() => AturHalamanUji({}, '/kopi-senja/kurir'));
afterEach(cleanup);

describe('portal kurir', () => {
    it('Dikemas: data kontak tampil, tagih COD, tombol berangkat mengirim ke rute kirim', () => {
        RenderUji(
            <HalamanPortalKurir
                NamaToko="Kopi Senja"
                NamaKurir="Joko Santoso"
                AlamatDasar={dasar}
                Pengiriman={[Pengiriman('Dikemas', '01JKIRIM00000000000000001', 'ON/SLB/261002-0001')]}
            />,
        );

        const kartu = screen.getByRole('region', { name: 'Pesanan ON/SLB/261002-0001' });
        expect(within(kartu).getByRole('link', { name: '+6281234567890' }).getAttribute('href')).toBe(
            'tel:+6281234567890',
        );
        expect(within(kartu).getByText('Jl. Merdeka 10, Braga, Sumur Bandung, Bandung, 40123')).toBeTruthy();
        expect(within(kartu).getByText('Tagih tunai Rp 72.000')).toBeTruthy();
        expect(within(kartu).getByText('2 × Kopi Susu Gula Aren')).toBeTruthy();

        fireEvent.click(within(kartu).getByRole('button', { name: 'Berangkat antar' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `${dasar}/pengiriman/01JKIRIM00000000000000001/kirim`,
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('Dikirim: serah terima mengirim nama penerima + foto sebagai FormData. Gagal mengirim alasan', () => {
        RenderUji(
            <HalamanPortalKurir
                NamaToko="Kopi Senja"
                NamaKurir="Joko Santoso"
                AlamatDasar={dasar}
                Pengiriman={[
                    Pengiriman('Dikirim', '01JKIRIM00000000000000002', 'ON/SLB/261002-0002'),
                    Pengiriman('Diterima', '01JKIRIM00000000000000003', 'ON/SLB/261002-0003'),
                ]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Sudah diterima' }));
        fireEvent.change(screen.getByLabelText(/Nama penerima/), { target: { value: 'Pak Darto' } });
        const foto = new File(['jpeg'], 'bukti.jpg', { type: 'image/jpeg' });
        fireEvent.change(screen.getByLabelText(/Foto bukti serah terima/), { target: { files: [foto] } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan serah terima' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `${dasar}/pengiriman/01JKIRIM00000000000000002/terima`,
            { NamaPenerima: 'Pak Darto', Foto: foto },
            expect.objectContaining({ forceFormData: true }),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Batal' }));
        fireEvent.click(screen.getByRole('button', { name: 'Gagal dikirim' }));
        fireEvent.change(screen.getByLabelText(/Alasan gagal dikirim/), { target: { value: 'Rumah kosong' } });
        fireEvent.click(screen.getByRole('button', { name: 'Tandai gagal' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            `${dasar}/pengiriman/01JKIRIM00000000000000002/gagal`,
            { Alasan: 'Rumah kosong' },
            expect.anything(),
        );

        // Riwayat selesai tanpa telepon & alamat.
        expect(screen.getByText('Selesai 24 jam terakhir')).toBeTruthy();
        expect(screen.getByText(/diterima Pak Darto \| ada foto/)).toBeTruthy();
        expect(screen.getAllByRole('link')).toHaveLength(1);
    });

    it('tanpa pengiriman: keterangan kosong', () => {
        RenderUji(
            <HalamanPortalKurir NamaToko="Kopi Senja" NamaKurir="Joko Santoso" AlamatDasar={dasar} Pengiriman={[]} />,
        );
        expect(screen.getByText(/Belum ada pengiriman untuk Anda/)).toBeTruthy();
    });
});
