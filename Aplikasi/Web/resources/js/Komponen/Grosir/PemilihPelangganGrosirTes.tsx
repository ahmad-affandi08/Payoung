import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { BukaPilihan } from '@/Pengujian/InteraksiPilihan';

import PemilihPelangganGrosir, { BuatUrlCariPelanggan, KeteranganKredit } from './PemilihPelangganGrosir';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const pelanggan = [
    {
        Uuid: 'P-MAKMUR',
        Nama: 'Toko Makmur Jaya',
        NoHp: '0813****0005',
        LimitKredit: '50000000.00',
        SisaPiutang: '2000000.00',
    },
    {
        Uuid: 'P-WARUNG',
        Nama: 'Warung Bu Sri',
        NoHp: '0812****1234',
        LimitKredit: null,
        SisaPiutang: '0.00',
        TerminHari: 14,
    },
];

function SiapkanFetch() {
    const Ambil = vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ Data: pelanggan }) });

    vi.stubGlobal('fetch', Ambil);

    return Ambil;
}

describe('PemilihPelangganGrosir (dropdown, PRD v3.14)', () => {
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('berbentuk dropdown bernama Pelanggan tanpa tombol Cari; pilihan aktif tampil di pemicu', () => {
        SiapkanFetch();
        RenderUji(
            <PemilihPelangganGrosir uuidTerpilih="P-MAKMUR" namaTerpilih="Toko Makmur Jaya" saatPilih={vi.fn()} />,
        );

        expect(screen.queryByRole('button', { name: 'Cari' })).toBeNull();
        expect(screen.getByRole('combobox', { name: 'Pelanggan' }).textContent).toContain('Toko Makmur Jaya');
        expect(screen.getByRole('combobox', { name: 'Pelanggan' }).getAttribute('aria-required')).toBe('true');
    });

    it('membuka dropdown tanpa mengetik langsung memuat daftar awal dengan limit & piutang, lalu memilih', async () => {
        const Ambil = SiapkanFetch();
        const SaatPilih = vi.fn();

        RenderUji(<PemilihPelangganGrosir uuidTerpilih="" namaTerpilih="" saatPilih={SaatPilih} />);
        expect(screen.getByRole('combobox', { name: 'Pelanggan' }).textContent).toContain('Pilih pelanggan');

        BukaPilihan(screen.getByRole('combobox', { name: 'Pelanggan' }));

        await waitFor(() => expect(screen.getByRole('option', { name: /Toko Makmur Jaya/ })).toBeTruthy());
        expect(Ambil.mock.calls[0]?.[0]).toBe('/kelola/grosir/pelanggan/cari?kata=');
        expect(screen.getByText(/limit Rp\s?50\.000\.000 \| piutang Rp\s?2\.000\.000/)).toBeTruthy();
        expect(screen.getByText('0812****1234 | tanpa limit kredit')).toBeTruthy();

        fireEvent.click(screen.getByRole('option', { name: /Warung Bu Sri/ }));
        expect(SaatPilih).toHaveBeenCalledWith('P-WARUNG', 'Warung Bu Sri', 14);
    });

    it('mengetik di kotak cari dalam dropdown meminta pelanggan sesuai kata (jeda 300 ms)', async () => {
        const Ambil = SiapkanFetch();

        RenderUji(<PemilihPelangganGrosir uuidTerpilih="" namaTerpilih="" saatPilih={vi.fn()} />);
        BukaPilihan(screen.getByRole('combobox', { name: 'Pelanggan' }));
        fireEvent.change(screen.getByLabelText('Cari Pelanggan'), { target: { value: 'makmur' } });

        await waitFor(() =>
            expect(Ambil.mock.calls.map((panggilan) => panggilan[0])).toContain(
                '/kelola/grosir/pelanggan/cari?kata=makmur',
            ),
        );
    });

    it('masalah server tampil di bawah dropdown', () => {
        SiapkanFetch();
        RenderUji(
            <PemilihPelangganGrosir uuidTerpilih="" namaTerpilih="" saatPilih={vi.fn()} galat="Pilih pelanggan." />,
        );

        expect(screen.getByText('Pilih pelanggan.')).toBeTruthy();
    });

    it('BuatUrlCariPelanggan & KeteranganKredit', () => {
        expect(BuatUrlCariPelanggan('a b')).toBe('/kelola/grosir/pelanggan/cari?kata=a+b');
        expect(KeteranganKredit({ Uuid: 'x', Nama: 'x', NoHp: null, LimitKredit: null })).toBe(
            'Tanpa nomor | tanpa limit kredit',
        );
    });
});
