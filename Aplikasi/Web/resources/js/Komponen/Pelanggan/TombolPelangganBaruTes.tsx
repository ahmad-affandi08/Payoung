import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, TambahPropsHalamanUji } from '@/Komponen/Katalog/TiruanInertia';
import TombolPelangganBaru, { type PelangganBaru } from '@/Komponen/Pelanggan/TombolPelangganBaru';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const PelangganDibuat: PelangganBaru = {
    Uuid: '01J9PLG0000000000000000009',
    Nama: 'CV Mitra Sejahtera Abadi',
    NoHp: '0812****7890',
    LimitKredit: null,
    SisaPiutang: '0.00',
    TerminHari: 30,
};

describe('Tambah pelanggan dari dalam pemilih pelanggan (D-272)', () => {
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('menyimpan lewat JSON, mengembalikan pilihan pelanggan, dan tidak ikut mengirim formulir induk', async () => {
        AturHalamanUji();
        const FetchTiruan = vi.fn().mockResolvedValue({
            ok: true,
            status: 201,
            json: () => Promise.resolve({ Data: PelangganDibuat }),
        });
        vi.stubGlobal('fetch', FetchTiruan);
        const Tersimpan = vi.fn();
        const KirimInduk = vi.fn();

        render(
            <form
                onSubmit={(peristiwa) => {
                    peristiwa.preventDefault();
                    KirimInduk();
                }}
            >
                <TombolPelangganBaru saatTersimpan={Tersimpan} />
            </form>,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Pelanggan belum ada? Tambah pelanggan baru' }));
        fireEvent.change(screen.getByRole('textbox', { name: /Nama pelanggan/ }), {
            target: { value: 'CV Mitra Sejahtera Abadi' },
        });
        fireEvent.change(screen.getByRole('textbox', { name: /Nomor HP/ }), { target: { value: '0812-3456-7890' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pelanggan' }));

        await waitFor(() => expect(Tersimpan).toHaveBeenCalledWith(PelangganDibuat));
        const [alamat, opsi] = FetchTiruan.mock.calls[0] as [string, { method: string; body: string }];
        expect(alamat).toBe('/kelola/pelanggan/cepat');
        expect(opsi.method).toBe('POST');
        expect(JSON.parse(opsi.body)).toEqual({
            Nama: 'CV Mitra Sejahtera Abadi',
            NoHp: '0812-3456-7890',
            SetujuPemasaran: false,
        });
        expect(KirimInduk).not.toHaveBeenCalled();
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    });

    it('nama atau nomor HP kosong ditolak di sisi klien tanpa memanggil server', async () => {
        AturHalamanUji();
        const FetchTiruan = vi.fn();
        vi.stubGlobal('fetch', FetchTiruan);

        render(<TombolPelangganBaru saatTersimpan={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: /Tambah pelanggan baru/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pelanggan' }));

        expect(await screen.findByText('Isi nama dan nomor HP pelanggan.')).toBeTruthy();
        expect(FetchTiruan).not.toHaveBeenCalled();
    });

    it('tidak tampil bagi pengguna tanpa izin mengelola pelanggan', () => {
        AturHalamanUji();
        TambahPropsHalamanUji({ Akses: { Pemilik: false, Izin: ['pelanggan.lihat'] } });

        render(<TombolPelangganBaru saatTersimpan={vi.fn()} />);

        expect(screen.queryByRole('button', { name: /Tambah pelanggan baru/ })).toBeNull();
    });
});
