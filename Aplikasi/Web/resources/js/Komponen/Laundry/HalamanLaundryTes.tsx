import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarLaundry from '@/Halaman/Kelola/Laundry/Daftar';
import { BuatHasilTabel } from '@/Komponen/Katalog/DataUjiKatalog';
import { AturHalamanUji, kirimanForm, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import { RingkasIsiLaundry, type PropsDaftarLaundry, type TiketLaundry } from '@/Tipe/Laundry';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * Laundry (§9.9): daftar cucian back-office (aksi status maju sesuai transisi, penanda terlambat berteks) dan
 * pengaturan (parfum dipisah koma).
 */

const tiket: TiketLaundry = {
    Uuid: '01JLAUNDRY0000000000000001',
    Nomor: 'INV/SLO/261013/K01-0001',
    DibuatPada: '2026-10-13T02:25:00Z',
    EstimasiSelesaiPada: '2026-10-14T02:25:00Z',
    SiapPada: null,
    DiambilPada: null,
    NamaPelanggan: 'Ratna Sari Dewi Kusumawardhani',
    NoHp: '0812-3456-7890',
    JenisLayanan: 'Express',
    Berat: '3.50',
    Item: [{ Nama: 'Bed cover king', Jumlah: 1 }],
    Parfum: 'Lavender',
    Catatan: 'Kemeja putih dipisah',
    Outlet: { Uuid: '01JOUTLET00000000000000001', Nama: 'Outlet Solo' },
    Status: 'Dicuci',
    LabelStatus: 'Dicuci',
    LewatEstimasi: true,
    TerlambatDiambil: false,
    NotifikasiTerkirim: false,
    StatusBerikutnya: ['Dikeringkan', 'Disetrika', 'Siap'],
};

function Props(ubah: Partial<PropsDaftarLaundry> = {}): PropsDaftarLaundry {
    return {
        Tiket: BuatHasilTabel([tiket]),
        OpsiStatus: [{ Nilai: 'Dicuci', Label: 'Dicuci' }],
        OpsiOutlet: [{ Uuid: '01JOUTLET00000000000000001', Nama: 'Outlet Solo' }],
        Pengaturan: {
            Aktif: false,
            JamReguler: 48,
            JamExpress: 24,
            Parfum: ['Lavender', 'Sakura'],
            NotifikasiSiap: true,
            HariBelumDiambil: 7,
        },
        ZonaWaktu: 'Asia/Jakarta',
        Izin: { Pengaturan: true },
        ...ubah,
    };
}

describe('Laundry (§9.9)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/laundry');
        kirimanForm.length = 0;
        tiruanRouter.post.mockClear();
    });
    afterEach(() => cleanup());

    it('ringkasan isi cucian: berat berkoma & item', () => {
        expect(RingkasIsiLaundry({ Berat: '3.50', Item: [{ Nama: 'Jas', Jumlah: 2 }] })).toBe('3,5 kg | Jas ×2');
        expect(RingkasIsiLaundry({ Berat: '4.00', Item: [] })).toBe('4 kg');
        expect(RingkasIsiLaundry({ Berat: null, Item: [] })).toBe('-');
    });

    it('baris menampilkan lewat perkiraan (berteks). Aksi mengikuti status berikutnya dan mengirim status', () => {
        RenderUji(<HalamanDaftarLaundry {...Props()} />);
        expect(screen.getAllByText('Ratna Sari Dewi Kusumawardhani').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Lewat perkiraan').length).toBeGreaterThan(0);
        expect(screen.getByText(/Isian laundry di aplikasi kasir belum aktif/)).toBeTruthy();

        BukaMenu(screen.getAllByRole('button', { name: /Aksi/ })[0] as HTMLElement);
        expect(screen.getByRole('menuitem', { name: 'Masuk pengeringan' })).toBeTruthy();
        expect(screen.queryByRole('menuitem', { name: 'Sudah diambil pelanggan' })).toBeNull();
        fireEvent.click(screen.getByRole('menuitem', { name: 'Tandai siap diambil' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/laundry/01JLAUNDRY0000000000000001/status',
            { Status: 'Siap' },
            { preserveScroll: true },
        );
    });

    it('pengaturan: parfum dipisah koma dikirim sebagai daftar, tanpa izin tidak ada tombol', () => {
        RenderUji(<HalamanDaftarLaundry {...Props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Pengaturan' }));
        const parfum = screen.getByRole('textbox', { name: /Pilihan parfum/ });
        expect((parfum as HTMLInputElement).value).toBe('Lavender, Sakura');
        fireEvent.change(parfum, { target: { value: 'Lavender, , Melati ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan' }));
        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'put',
            url: '/kelola/laundry/pengaturan',
            data: { Parfum: ['Lavender', 'Melati'], JamExpress: 24 },
        });
        cleanup();

        RenderUji(<HalamanDaftarLaundry {...Props({ Izin: { Pengaturan: false } })} />);
        expect(screen.queryByRole('button', { name: 'Pengaturan' })).toBeNull();
    });
});
