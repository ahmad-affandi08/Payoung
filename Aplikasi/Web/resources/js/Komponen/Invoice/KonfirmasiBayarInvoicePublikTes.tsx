import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import KonfirmasiBayarInvoicePublik from '@/Komponen/Invoice/KonfirmasiBayarInvoicePublik';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import type { KonfirmasiPublikInvoice } from '@/Tipe/Invoice';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const kode = '1.abcdefghijklmnopqrstuvwxyz012345';

function Konfirmasi(ubah: Partial<KonfirmasiPublikInvoice> = {}): KonfirmasiPublikInvoice {
    return { Bisa: true, Sisa: '10000000.00', HariIni: '2026-09-27', Menunggu: [], ...ubah };
}

describe('Konfirmasi pembayaran invoice oleh pelanggan (halaman publik)', () => {
    beforeEach(() => AturHalamanUji());
    afterEach(() => cleanup());

    it('jumlah awal sisa tagihan; tombol aktif hanya bila nama diisi dan jumlah sah', () => {
        RenderUji(<KonfirmasiBayarInvoicePublik kode={kode} konfirmasi={Konfirmasi()} />);

        const kirim = screen.getByRole('button', { name: 'Saya sudah bayar' }) as HTMLButtonElement;
        expect(kirim.disabled).toBe(true);

        fireEvent.change(screen.getByLabelText(/Nama pengirim/), { target: { value: 'Budi Santoso' } });
        expect(kirim.disabled).toBe(false);

        fireEvent.change(screen.getByLabelText(/Jumlah dibayar/), { target: { value: '' } });
        expect(kirim.disabled).toBe(true);
    });

    it('jumlah melebihi sisa ditandai dan tidak bisa dikirim', () => {
        RenderUji(<KonfirmasiBayarInvoicePublik kode={kode} konfirmasi={Konfirmasi({ Sisa: '4000000.00' })} />);

        fireEvent.change(screen.getByLabelText(/Nama pengirim/), { target: { value: 'Budi Santoso' } });
        fireEvent.change(screen.getByLabelText(/Jumlah dibayar/), { target: { value: '5000000' } });

        expect(screen.getByText('Jumlah melebihi sisa tagihan.')).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Saya sudah bayar' }) as HTMLButtonElement).disabled).toBe(true);
    });

    it('mengirim klaim ke rute publik dengan nama, tanggal, jumlah, dan catatan', () => {
        RenderUji(<KonfirmasiBayarInvoicePublik kode={kode} konfirmasi={Konfirmasi({ Sisa: '4000000.00' })} />);

        fireEvent.change(screen.getByLabelText(/Nama pengirim/), { target: { value: ' Budi Santoso ' } });
        fireEvent.change(screen.getByLabelText(/Catatan/), { target: { value: 'Transfer BCA' } });
        fireEvent.click(screen.getByRole('button', { name: 'Saya sudah bayar' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/i/${kode}/konfirmasi-bayar`,
            {
                Nama: 'Budi Santoso',
                TanggalBayar: '2026-09-27',
                Jumlah: '4000000',
                Catatan: 'Transfer BCA',
                Bukti: null,
            },
            expect.objectContaining({ forceFormData: true }),
        );
    });

    it('foto bukti yang dipilih ikut terkirim sebagai berkas (multipart) dan bisa dihapus lagi', () => {
        const { container } = RenderUji(
            <KonfirmasiBayarInvoicePublik kode={kode} konfirmasi={Konfirmasi({ Sisa: '4000000.00' })} />,
        );
        const foto = new File(['x'], 'bukti.png', { type: 'image/png' });

        fireEvent.change(screen.getByLabelText(/Nama pengirim/), { target: { value: 'Budi Santoso' } });
        fireEvent.change(container.querySelector('input[type="file"]') as HTMLInputElement, {
            target: { files: [foto] },
        });
        expect(screen.getByText(/bukti\.png/)).toBeTruthy();
        expect(screen.getByText(/Maksimal 1 berkas/)).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Saya sudah bayar' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            `/i/${kode}/konfirmasi-bayar`,
            expect.objectContaining({ Bukti: foto }),
            expect.objectContaining({ forceFormData: true }),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Hapus' }));
        expect(screen.queryByText(/bukti\.png/)).toBeNull();
    });

    it('klaim yang menunggu diperiksa tampil sebagai info tanpa nama pengirim; galat server tampil terbaca', () => {
        AturHalamanUji({ Umum: 'Sudah ada 3 konfirmasi yang menunggu verifikasi penjual.' });
        RenderUji(
            <KonfirmasiBayarInvoicePublik
                kode={kode}
                konfirmasi={Konfirmasi({ Menunggu: [{ TanggalBayar: '2026-09-26', Jumlah: '2500000.00' }] })}
            />,
        );

        expect(screen.getByText('Menunggu diperiksa penjual')).toBeTruthy();
        expect(screen.getByText(/2\.500\.000/)).toBeTruthy();
        expect(screen.getByText('Sudah ada 3 konfirmasi yang menunggu verifikasi penjual.')).toBeTruthy();
    });
});
