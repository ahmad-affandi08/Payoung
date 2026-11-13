import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanPusatWhatsapp from '@/Halaman/Kelola/Pelanggan/PusatWhatsapp';
import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import type { PropsHalamanPusatWhatsapp } from '@/Tipe/Whatsapp';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/* D-299 tahap 1: Pusat WhatsApp menampilkan nomor pengirim per outlet, tanpa isian token atau templat. */

const props: PropsHalamanPusatWhatsapp = {
    PesanOtomatis: [
        {
            Kunci: 'PoinBertambah',
            Label: 'Poin bertambah',
            Keterangan: 'Pelanggan diberi tahu poin.',
            Kelompok: 'Pelanggan',
            Aktif: false,
        },
        {
            Kunci: 'LaporanTutupKasir',
            Label: 'Laporan tutup kasir',
            Keterangan: 'Ringkasan saat shift ditutup.',
            Kelompok: 'Karyawan',
            Aktif: true,
        },
    ],
    Penerima: [
        {
            Uuid: '01K5PENERIMA0000000000AA1',
            Nama: 'Bu Rina',
            NomorTelepon: '6281234500001',
            UuidOutlet: '01K5OUTLET0000000000000AA1',
            NamaOutlet: 'Cabang Solo Baru',
        },
    ],
    MaksPenerima: 6,
    Riwayat: [],
    Nomor: [
        {
            Uuid: '01K5NOMOR00000000000000AA1',
            Label: 'Kasir utama',
            Fungsi: 'Umum',
            LabelFungsi: 'Semua pesan',
            NomorTelepon: '6281234567890',
            UuidOutlet: '01K5OUTLET0000000000000AA1',
            NamaOutlet: 'Cabang Solo Baru',
            Status: 'Terhubung',
            LabelStatus: 'Terhubung',
            Aktif: true,
            PesanStatus: null,
            TerhubungPada: '2026-11-13T03:00:00Z',
        },
        {
            Uuid: '01K5NOMOR00000000000000AA2',
            Label: 'Admin toko',
            Fungsi: 'Karyawan',
            LabelFungsi: 'Laporan ke karyawan',
            NomorTelepon: '6281234567891',
            UuidOutlet: '01K5OUTLET0000000000000AA1',
            NamaOutlet: 'Cabang Solo Baru',
            Status: 'Terputus',
            LabelStatus: 'Terputus',
            Aktif: false,
            PesanStatus: 'Nomor terputus dari WhatsApp. Pindai QR lagi.',
            TerhubungPada: null,
        },
    ],
    Outlet: [{ Uuid: '01K5OUTLET0000000000000AA1', Nama: 'Cabang Solo Baru' }],
    OpsiFungsi: [
        { Nilai: 'Umum', Label: 'Semua pesan' },
        { Nilai: 'Pelanggan', Label: 'Pesan ke pelanggan' },
        { Nilai: 'Karyawan', Label: 'Laporan ke karyawan' },
        { Nilai: 'Broadcast', Label: 'Broadcast' },
    ],
    MaksPerOutlet: 6,
    SesiSiap: true,
};

describe('D-299 Pusat WhatsApp', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/pelanggan/whatsapp');
        kirimanForm.length = 0;
    });
    afterEach(cleanup);

    it('menampilkan nomor, outlet, fungsi, dan status sambungan', () => {
        RenderUji(<HalamanPusatWhatsapp {...props} />);

        expect(screen.getAllByText('Kasir utama').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Cabang Solo Baru').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Terputus').length).toBeGreaterThan(0);
        expect(screen.getAllByText(/Nomor terputus dari WhatsApp/).length).toBeGreaterThan(0);
    });

    it('formulir tambah nomor tidak meminta token atau nama templat, outlet tunggal terisi otomatis', () => {
        RenderUji(<HalamanPusatWhatsapp {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Tambah nomor' }));

        expect(screen.queryByLabelText(/token/i)).toBeNull();
        expect(screen.queryByLabelText(/templat/i)).toBeNull();
        fireEvent.change(screen.getByLabelText(/Nama nomor/), { target: { value: 'Kasir dua' } });
        fireEvent.change(screen.getByLabelText(/Nomor WhatsApp/), { target: { value: '081234567892' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan dan lanjut pindai' }));

        expect(kirimanForm.at(-1)).toMatchObject({
            url: '/kelola/pelanggan/whatsapp/nomor',
            data: {
                UuidOutlet: '01K5OUTLET0000000000000AA1',
                Label: 'Kasir dua',
                NomorTelepon: '081234567892',
                Fungsi: 'Umum',
            },
        });
    });

    it('tab Pesan otomatis menampilkan sakelar poin dan tab Laporan karyawan menampilkan penerima', () => {
        RenderUji(<HalamanPusatWhatsapp {...props} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Pesan otomatis' }));

        expect(screen.getByRole('switch', { name: 'Poin bertambah' })).toBeTruthy();

        fireEvent.click(screen.getByRole('tab', { name: 'Laporan karyawan' }));

        expect(screen.getByRole('switch', { name: 'Laporan tutup kasir' })).toBeTruthy();
        expect(screen.getAllByText('Bu Rina').length).toBeGreaterThan(0);
    });

    it('tombol tambah nonaktif dan ada penjelasan saat layanan sesi belum siap', () => {
        RenderUji(<HalamanPusatWhatsapp {...props} SesiSiap={false} />);

        expect(screen.getByText('Layanan nomor pengirim belum siap')).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Tambah nomor' }) as HTMLButtonElement).disabled).toBe(true);
    });
});
