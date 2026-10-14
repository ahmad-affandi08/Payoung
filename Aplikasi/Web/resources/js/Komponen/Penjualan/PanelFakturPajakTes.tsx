import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { BukaPilihan } from '@/Pengujian/InteraksiPilihan';
import type { FakturPajakPenjualan } from '@/Tipe/Penjualan';

import PanelFakturPajak from './PanelFakturPajak';
import { BuatUrlCariPembeli, KeteranganIdentitas } from './PemilihPembeliFakturPajak';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const pembeli = [
    { Uuid: 'P-SINAR', Nama: 'CV Sinar Bengawan', JenisIdentitas: 'NPWP', IdentitasTersamar: '••••1234' },
    { Uuid: 'P-WATI', Nama: 'Bu Wati', JenisIdentitas: null, IdentitasTersamar: null },
];

const fakturAktif: FakturPajakPenjualan = {
    Uuid: 'FP1',
    Status: 'Diposting',
    NomorFakturPajak: null,
    Catatan: 'Minta faktur via WA',
    NamaPembeli: 'CV Sinar Bengawan',
    DibuatPada: '2026-09-27T03:00:00Z',
    DibatalkanPada: null,
    AlasanBatal: null,
};

function SiapkanFetch() {
    const Ambil = vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ Data: pembeli }) });

    vi.stubGlobal('fetch', Ambil);

    return Ambil;
}

describe('PanelFakturPajak (TAX-07)', () => {
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('belum ada faktur: tombol Buat Faktur Pajak hanya untuk pengguna berizin pada penjualan yang berlaku', () => {
        RenderUji(<PanelFakturPajak uuidPenjualan="PJ1" faktur={null} bolehBuat bolehKelola />);
        expect(screen.getByRole('button', { name: 'Buat Faktur Pajak' })).toBeTruthy();
        cleanup();

        RenderUji(<PanelFakturPajak uuidPenjualan="PJ1" faktur={null} bolehBuat bolehKelola={false} />);
        expect(screen.queryByRole('button', { name: 'Buat Faktur Pajak' })).toBeNull();
        expect(screen.getByText(/Mintalah pengguna ber-izin akuntansi/)).toBeTruthy();
        cleanup();

        RenderUji(<PanelFakturPajak uuidPenjualan="PJ1" faktur={null} bolehBuat={false} bolehKelola />);
        expect(screen.queryByRole('button', { name: 'Buat Faktur Pajak' })).toBeNull();
    });

    it('dialog: pembeli tanpa NPWP/NIK tampil tetapi tidak bisa dipilih; memilih yang sah membuka tombol kirim', async () => {
        const Ambil = SiapkanFetch();

        RenderUji(<PanelFakturPajak uuidPenjualan="PJ1" faktur={null} bolehBuat bolehKelola />);
        fireEvent.click(screen.getByRole('button', { name: 'Buat Faktur Pajak' }));

        const kirim = screen.getAllByRole('button', { name: 'Buat Faktur Pajak' }).at(-1) as HTMLButtonElement;

        expect(kirim.disabled).toBe(true);

        BukaPilihan(screen.getByRole('combobox', { name: 'Pembeli' }));
        await waitFor(() => expect(screen.getByRole('option', { name: /CV Sinar Bengawan/ })).toBeTruthy());
        expect(Ambil.mock.calls[0]?.[0]).toBe('/kelola/penjualan/faktur-pajak/pelanggan?kata=');
        expect(screen.getByText('NPWP ••••1234')).toBeTruthy();
        expect(screen.getByText('Belum ada NPWP/NIK. Lengkapi di data pelanggan.')).toBeTruthy();
        expect(screen.getByRole('option', { name: /Bu Wati/ }).getAttribute('aria-disabled')).toBe('true');

        fireEvent.click(screen.getByRole('option', { name: /CV Sinar Bengawan/ }));
        await waitFor(() => {
            const tombol = screen.getAllByRole('button', { name: 'Buat Faktur Pajak' }).at(-1) as HTMLButtonElement;

            expect(tombol.disabled).toBe(false);
        });
    });

    it('faktur aktif: pembeli, catatan, isian nomor Coretax, dan tombol batal; tanpa izin hanya melihat nomor', () => {
        RenderUji(<PanelFakturPajak uuidPenjualan="PJ1" faktur={fakturAktif} bolehBuat bolehKelola />);
        expect(screen.getByText('Faktur Pajak aktif')).toBeTruthy();
        expect(screen.getByText('CV Sinar Bengawan')).toBeTruthy();
        expect(screen.getByText('Minta faktur via WA')).toBeTruthy();
        expect(screen.getByLabelText('Nomor Faktur Pajak')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Simpan nomor' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Batalkan Faktur Pajak' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Buat faktur baru' })).toBeNull();
        cleanup();

        RenderUji(
            <PanelFakturPajak
                uuidPenjualan="PJ1"
                faktur={{ ...fakturAktif, NomorFakturPajak: '0400002600000123' }}
                bolehBuat
                bolehKelola={false}
            />,
        );
        expect(screen.getByText('0400002600000123')).toBeTruthy();
        expect(screen.queryByLabelText('Nomor Faktur Pajak')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Batalkan Faktur Pajak' })).toBeNull();
    });

    it('faktur dibatalkan: alasan tampil, nomor Coretax diingatkan, dan bisa membuat faktur baru', () => {
        RenderUji(
            <PanelFakturPajak
                uuidPenjualan="PJ1"
                faktur={{
                    ...fakturAktif,
                    Status: 'Dibatalkan',
                    NomorFakturPajak: '0400002600000123',
                    AlasanBatal: 'Salah pilih pelanggan',
                }}
                bolehBuat
                bolehKelola
            />,
        );
        expect(screen.getByText('Dibatalkan')).toBeTruthy();
        expect(screen.getByText(/Salah pilih pelanggan/)).toBeTruthy();
        expect(screen.getByText(/harus dibatalkan atau diganti di Coretax juga/)).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Buat faktur baru' })).toBeTruthy();
        expect(screen.queryByLabelText('Nomor Faktur Pajak')).toBeNull();
    });

    it('fungsi bantu: URL pencarian dan keterangan identitas', () => {
        expect(BuatUrlCariPembeli('sinar')).toBe('/kelola/penjualan/faktur-pajak/pelanggan?kata=sinar');
        expect(
            KeteranganIdentitas({ Uuid: 'X', Nama: 'A', JenisIdentitas: 'NIK', IdentitasTersamar: '••••7890' }),
        ).toBe('NIK ••••7890');
    });
});
