import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanPengaturanPajak from '@/Halaman/Kelola/Kasir/Pajak';
import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import type { PropsPengaturanPajak } from '@/Tipe/Kasir';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-263: halaman Pajak & biaya layanan per outlet di Atur kasir. Menampilkan nilai tersimpan, biaya layanan yang aktif bisa
 * dimatikan, dan hanya tombol Simpan yang mengirim ke server.
 */

const props: PropsPengaturanPajak = {
    UuidOutlet: '01K5OUTLET0000000000000001',
    OpsiOutlet: [{ Uuid: '01K5OUTLET0000000000000001', Nama: 'Es Teh Seger Solo Baru' }],
    Pkp: false,
    Kota: { Kode: '33.72', Nama: 'Kota Surakarta' },
    Nilai: { PungutPbjt: false, BiayaLayananAktif: true, PersenBiayaLayanan: '2.00', HargaTermasukPajak: false },
    TarifPbjt: null,
    TarifPpn: null,
};

describe('Pajak & biaya layanan (D-263)', () => {
    beforeEach(() => AturHalamanUji());
    afterEach(() => cleanup());

    it('menampilkan biaya layanan yang sedang aktif beserta persennya', () => {
        RenderUji(<HalamanPengaturanPajak {...props} />);

        expect(screen.getByRole('checkbox', { name: 'Kenakan biaya layanan' }).getAttribute('aria-checked')).toBe(
            'true',
        );
        expect(screen.getByLabelText<HTMLInputElement>(/Persentase biaya layanan/).value).toBe('2,00');
        expect(screen.getByText('Usaha Anda bukan PKP, jadi tidak memungut PPN.', { exact: false })).toBeTruthy();
    });

    it('mematikan biaya layanan lalu menyimpan mengirim PUT ke halaman yang sama dengan outlet terpilih', () => {
        RenderUji(<HalamanPengaturanPajak {...props} />);

        fireEvent.click(screen.getByRole('checkbox', { name: 'Kenakan biaya layanan' }));
        expect(screen.queryByLabelText(/Persentase biaya layanan/)).toBeNull();
        expect(kirimanForm).toHaveLength(0);

        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan pajak' }));

        expect(kirimanForm).toHaveLength(1);
        expect(kirimanForm[0]).toMatchObject({
            metode: 'put',
            url: '/kelola/kasir/pajak',
            data: { Outlet: props.UuidOutlet, BiayaLayananAktif: false, PungutPbjt: false, HargaTermasukPajak: false },
        });
    });

    it('memilih harga belum termasuk pajak dan sudah termasuk pajak mengubah isian yang dikirim', () => {
        RenderUji(<HalamanPengaturanPajak {...props} />);

        fireEvent.click(screen.getByRole('radio', { name: /Sudah termasuk pajak/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan pajak' }));

        expect(kirimanForm[0]?.data).toMatchObject({ HargaTermasukPajak: true });
    });
});
