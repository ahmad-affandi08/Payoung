import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import AksiMassalInvoice from '@/Komponen/Invoice/AksiMassalInvoice';
import { RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import type { BarisDaftarInvoice } from '@/Tipe/Invoice';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

function Baris(uuid: string, ubah: Partial<BarisDaftarInvoice>): BarisDaftarInvoice {
    return {
        Uuid: uuid,
        Nomor: null,
        Jenis: 'Penjualan',
        LabelJenis: 'Invoice',
        Perihal: null,
        Tanggal: '2026-09-27',
        JatuhTempo: '2026-10-11',
        NamaPelanggan: 'PT Sumber Makmur Sentosa',
        KodeOutlet: 'UTM',
        Status: 'Terbit',
        LabelStatus: 'Terbit',
        StatusBayar: 'BelumDibayar',
        LabelStatusBayar: 'Belum dibayar',
        Total: '10000000.00',
        Tagihan: '10000000.00',
        Sisa: '10000000.00',
        TerkirimPada: null,
        DilihatPada: null,
        ...ubah,
    };
}

function Konteks(terpilih: BarisDaftarInvoice[]) {
    return {
        terpilih,
        semuaHasil: false,
        total: terpilih.length,
        keadaan: { cari: '', urut: [], halaman: 1, perHalaman: 25, saring: {} },
        bersihkan: vi.fn(),
    };
}

describe('Aksi massal invoice', () => {
    beforeEach(() => tiruanRouter.post.mockClear());
    afterEach(() => cleanup());

    const draf = Baris('01J9INV0000000000000000001', { Status: 'Draf', Sisa: null, StatusBayar: 'Draf' });
    const belumLunas = Baris('01J9INV0000000000000000002', {});
    const lunas = Baris('01J9INV0000000000000000003', { Sisa: '0.00', StatusBayar: 'Lunas' });

    it('menghitung tiap aksi dari status baris terpilih: draf diterbitkan, terbit dikirim, hanya yang bersisa diingatkan', () => {
        RenderUji(<AksiMassalInvoice konteks={Konteks([draf, belumLunas, lunas])} />);

        expect(screen.getByRole('button', { name: 'Terbitkan draf (1)' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Kirim lewat WhatsApp (2)' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Kirim pengingat (1)' })).toBeTruthy();
    });

    it('mengirim hanya invoice yang sesuai ke rute massal', () => {
        RenderUji(<AksiMassalInvoice konteks={Konteks([draf, belumLunas, lunas])} />);

        fireEvent.click(screen.getByRole('button', { name: 'Kirim pengingat (1)' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/invoice/massal/ingatkan',
            { Uuid: ['01J9INV0000000000000000002'] },
            expect.anything(),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Terbitkan draf (1)' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            '/kelola/invoice/massal/terbitkan',
            { Uuid: ['01J9INV0000000000000000001'] },
            expect.anything(),
        );
    });

    it('tombol nonaktif bila tidak ada baris yang sesuai', () => {
        RenderUji(<AksiMassalInvoice konteks={Konteks([draf])} />);

        expect((screen.getByRole('button', { name: 'Kirim lewat WhatsApp (0)' }) as HTMLButtonElement).disabled).toBe(
            true,
        );
        expect((screen.getByRole('button', { name: 'Kirim pengingat (0)' }) as HTMLButtonElement).disabled).toBe(true);
    });
});
