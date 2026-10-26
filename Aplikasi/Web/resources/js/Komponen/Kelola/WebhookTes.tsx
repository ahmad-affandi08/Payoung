import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanWebhook from '@/Halaman/Kelola/Pengaturan/Webhook';
import { AturHalamanUji, kirimanForm, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import type { PropsHalamanWebhook } from '@/Tipe/ApiPublik';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/* X7 bagian 2: halaman Webhook menampilkan rahasia sekali, membuat webhook, dan log kiriman dengan kirim ulang. */

const props: PropsHalamanWebhook = {
    Webhook: [
        {
            Uuid: '01K5WEBHOOK000000000000AA1',
            Nama: 'Sistem gudang',
            Url: 'https://gudang.contoh.co.id/payoung',
            Peristiwa: ['penjualan.selesai'],
            Aktif: true,
            DibuatPada: '2026-10-05T03:00:00Z',
        },
    ],
    Kiriman: [
        {
            Uuid: '01K5KIRIMAN000000000000AA1',
            NamaWebhook: 'Sistem gudang',
            Peristiwa: 'penjualan.selesai',
            Status: 'Gagal',
            Percobaan: 6,
            KodeRespons: 503,
            CuplikanRespons: 'Layanan sedang pemeliharaan',
            BerikutnyaPada: null,
            TerkirimPada: null,
            DibuatPada: '2026-10-05T04:00:00Z',
            BisaKirimUlang: true,
        },
    ],
    OpsiPeristiwa: [
        { Nilai: 'penjualan.selesai', Label: 'Penjualan selesai (diterima server)' },
        { Nilai: 'penjualan.divoid', Label: 'Penjualan di-void' },
    ],
    RahasiaBaru: null,
};

describe('X7 halaman Webhook', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/pengaturan/webhook');
        kirimanForm.length = 0;
    });
    afterEach(cleanup);

    it('rahasia baru tampil sekali dengan tombol salin. Log kiriman menampilkan kode respons', () => {
        RenderUji(<HalamanWebhook {...props} RahasiaBaru={{ Nama: 'Sistem gudang', Rahasia: 'r'.repeat(48) }} />);

        expect(screen.getByText('r'.repeat(48))).toBeTruthy();
        expect(screen.getByRole('button', { name: /Salin rahasia/ })).toBeTruthy();
        expect(screen.getAllByText(/HTTP 503/).length).toBeGreaterThan(0);
        expect(screen.getAllByText('https://gudang.contoh.co.id/payoung').length).toBeGreaterThan(0);
    });

    it('formulir tambah webhook mengirim nama, URL, dan peristiwa terpilih', () => {
        RenderUji(<HalamanWebhook {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Tambah webhook' }));
        fireEvent.change(screen.getByLabelText(/Nama webhook/), { target: { value: 'Akuntansi' } });
        fireEvent.change(screen.getByLabelText(/Alamat URL/), { target: { value: 'https://akun.contoh.id/hook' } });
        fireEvent.click(screen.getByLabelText('Penjualan di-void'));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan webhook' }));

        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'post',
            url: '/kelola/pengaturan/webhook',
            data: { Nama: 'Akuntansi', Url: 'https://akun.contoh.id/hook', Peristiwa: ['penjualan.divoid'] },
        });
    });

    it('kirim ulang memanggil rute kirim-ulang kiriman', () => {
        RenderUji(<HalamanWebhook {...props} />);
        BukaMenu(screen.getAllByRole('button', { name: /Aksi/ }).at(-1) as HTMLElement);
        fireEvent.click(screen.getByRole('menuitem', { name: 'Kirim ulang' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/pengaturan/webhook/kiriman/01K5KIRIMAN000000000000AA1/kirim-ulang',
            {},
            expect.anything(),
        );
    });
});
