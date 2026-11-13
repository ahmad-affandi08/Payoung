import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, RenderUji, TambahPropsHalamanUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import AksiMassalPiutang from '@/Komponen/Piutang/AksiMassalPiutang';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

function Konteks(terpilih: { Uuid: string; UuidPelanggan: string | null }[]) {
    return {
        terpilih,
        semuaHasil: false,
        total: terpilih.length,
        keadaan: { cari: '', urut: [], halaman: 1, perHalaman: 25, saring: {} },
        bersihkan: vi.fn(),
    };
}

describe('Aksi massal piutang (pengingat WhatsApp)', () => {
    beforeEach(() => {
        AturHalamanUji();
        TambahPropsHalamanUji({ WhatsappTersedia: true });
        tiruanRouter.post.mockClear();
    });
    afterEach(() => cleanup());

    it('mengirim hanya piutang berpelanggan dan menyebut yang tidak ikut', () => {
        RenderUji(
            <AksiMassalPiutang
                konteks={Konteks([
                    { Uuid: '01J9PTG0000000000000000001', UuidPelanggan: '01J9PLG0000000000000000001' },
                    { Uuid: '01J9PTG0000000000000000002', UuidPelanggan: null },
                    { Uuid: '01J9PTG0000000000000000003', UuidPelanggan: '01J9PLG0000000000000000002' },
                ])}
            />,
        );

        expect(screen.getByText(/1 piutang tanpa pelanggan tidak ikut dikirim/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Kirim pengingat WhatsApp (2)' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/piutang/pengingat-massal',
            { Uuid: ['01J9PTG0000000000000000001', '01J9PTG0000000000000000003'] },
            expect.anything(),
        );
    });

    it('tombol nonaktif bila tidak ada piutang berpelanggan', () => {
        RenderUji(
            <AksiMassalPiutang konteks={Konteks([{ Uuid: '01J9PTG0000000000000000002', UuidPelanggan: null }])} />,
        );

        expect(
            (screen.getByRole('button', { name: 'Kirim pengingat WhatsApp (0)' }) as HTMLButtonElement).disabled,
        ).toBe(true);
    });

    it('D-299: tanpa layanan WhatsApp, aksi pengingat hilang seluruhnya', () => {
        TambahPropsHalamanUji({ WhatsappTersedia: false });
        const { container } = RenderUji(
            <AksiMassalPiutang
                konteks={Konteks([{ Uuid: '01J9PTG0000000000000000001', UuidPelanggan: '01J9PLG0000000000000000001' }])}
            />,
        );

        expect(screen.queryByRole('button', { name: /WhatsApp/ })).toBeNull();
        expect(container.querySelector('button')).toBeNull();
    });
});
