import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDataSaya from '@/Halaman/Kelola/Pengaturan/DataSaya';
import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import type { StatusPenghapusanData } from '@/Komponen/Kelola/PanelTutupAkun';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/* E5: halaman Data saya meminta ekspor data usaha (UU PDP) dan menampilkan riwayatnya; P-07: tutup akun dan hapus data. */

const penghapusan: StatusPenghapusanData = {
    Tersedia: true,
    HariTunggu: 14,
    EksporSudahDiunduh: true,
    Menunggu: null,
    Riwayat: [],
};

const baris = {
    Uuid: '01K5EKSPOR00000000000000A1',
    Status: 'Siap' as const,
    LabelStatus: 'Siap diunduh',
    UkuranByte: 2048,
    JumlahTabel: 12,
    JumlahBaris: 1500,
    DibuatPada: '2026-10-12T03:00:00Z',
    KedaluwarsaPada: '2026-10-19T03:00:00Z',
    DiunduhPada: null,
    Galat: null,
    BisaDiunduh: true,
};

describe('E5 halaman Data saya', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/pengaturan/data-saya');
        kirimanForm.length = 0;
    });
    afterEach(cleanup);

    it('tanpa riwayat menampilkan keadaan kosong dan tombol minta ekspor mengirim POST', () => {
        RenderUji(<HalamanDataSaya Ekspor={[]} Penghapusan={penghapusan} PerluKataSandi />);

        expect(screen.getByText(/Belum ada ekspor/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Minta ekspor data' }));

        expect(kirimanForm.map((kiriman) => [kiriman.metode, kiriman.url])).toEqual([
            ['post', '/kelola/pengaturan/data-saya/ekspor'],
        ]);
    });

    it('ekspor siap menampilkan ringkasan dan tombol unduh. Ekspor berjalan mengunci tombol minta', () => {
        RenderUji(<HalamanDataSaya Ekspor={[baris]} Penghapusan={penghapusan} PerluKataSandi />);

        expect(screen.getByText('Siap diunduh')).toBeTruthy();
        expect(screen.getByText(/12 berkas/)).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Unduh' })).toBeTruthy();
        cleanup();

        RenderUji(
            <HalamanDataSaya
                Ekspor={[{ ...baris, Status: 'Berjalan', LabelStatus: 'Sedang disiapkan', BisaDiunduh: false }]}
                Penghapusan={penghapusan}
                PerluKataSandi
            />,
        );
        const tombol = screen.getByRole('button', { name: 'Minta ekspor data' }) as HTMLButtonElement;

        expect(tombol.disabled).toBe(true);
        expect(screen.queryByRole('button', { name: 'Unduh' })).toBeNull();
    });

    it('tutup akun: tombol terkunci sampai ekspor diunduh. Formulir meminta kata sandi dan frasa konfirmasi lalu mengirim POST', () => {
        RenderUji(
            <HalamanDataSaya Ekspor={[]} Penghapusan={{ ...penghapusan, EksporSudahDiunduh: false }} PerluKataSandi />,
        );
        expect(screen.getByText('Unduh ekspor data dulu')).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Ajukan penghapusan data' }) as HTMLButtonElement).disabled).toBe(
            true,
        );
        cleanup();

        RenderUji(<HalamanDataSaya Ekspor={[]} Penghapusan={penghapusan} PerluKataSandi />);
        fireEvent.click(screen.getByRole('button', { name: 'Ajukan penghapusan data' }));
        fireEvent.change(screen.getByLabelText(/Kata sandi Anda/, { selector: 'input' }), {
            target: { value: 'kata-sandi-uji' },
        });
        fireEvent.change(screen.getByLabelText(/Ketik HAPUS DATA/), { target: { value: 'HAPUS DATA' } });
        const kirim = screen.getAllByRole('button', { name: 'Ajukan penghapusan data' }).at(-1);
        expect(kirim).toBeTruthy();
        fireEvent.click(kirim as HTMLElement);

        const kiriman = kirimanForm.find((item) => item.url === '/kelola/pengaturan/data-saya/penghapusan');
        expect(kiriman?.metode).toBe('post');
        expect(kiriman?.data).toMatchObject({ KataSandi: 'kata-sandi-uji', Konfirmasi: 'HAPUS DATA' });
    });

    it('akun Google tanpa kata sandi tidak diminta kata sandi. Permintaan menunggu menampilkan tanggal dan tombol batal', () => {
        RenderUji(<HalamanDataSaya Ekspor={[]} Penghapusan={penghapusan} PerluKataSandi={false} />);
        fireEvent.click(screen.getByRole('button', { name: 'Ajukan penghapusan data' }));
        expect(screen.queryByLabelText(/Kata sandi Anda/, { selector: 'input' })).toBeNull();
        cleanup();

        RenderUji(
            <HalamanDataSaya
                Ekspor={[]}
                Penghapusan={{
                    ...penghapusan,
                    Menunggu: {
                        Uuid: '01K5PENGHAPUSAN0000000000A1',
                        BerlakuPada: '2026-10-26T06:00:00Z',
                        DiajukanPada: '2026-10-12T06:00:00Z',
                        Alasan: null,
                    },
                }}
                PerluKataSandi
            />,
        );
        expect(screen.getByText('Permintaan penghapusan data sedang menunggu')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Batalkan permintaan' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Ajukan penghapusan data' })).toBeNull();
    });

    it('edisi Lisensi tidak menampilkan panel tutup akun', () => {
        RenderUji(<HalamanDataSaya Ekspor={[]} Penghapusan={{ ...penghapusan, Tersedia: false }} PerluKataSandi />);
        expect(screen.queryByText('Tutup akun dan hapus data')).toBeNull();
    });
});
