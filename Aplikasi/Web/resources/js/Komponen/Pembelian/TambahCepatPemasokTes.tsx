import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import FormPemasok, { IsianPemasokKosong } from '@/Komponen/Pembelian/FormPemasok';
import TombolPemasokBaru from '@/Komponen/Pembelian/TombolPemasokBaru';
import { IsiFormulirPelanggan } from '@/Komponen/Pelanggan/FormulirPelanggan';
import type { OpsiPemasok } from '@/Tipe/Pembelian';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * Mengurangi bolak-balik saat memasukkan data master: tambah pemasok langsung dari formulir pembelian lewat dialog
 * (tanpa meninggalkan formulir yang sudah terisi) dan tombol "Simpan & tambah lagi" di formulir pemasok dan pelanggan.
 */
const PemasokDibuat: OpsiPemasok = {
    Uuid: '01J9PMS0000000000000000009',
    Kode: 'TANI-MAKMUR',
    Nama: 'CV Tani Makmur',
    Pkp: false,
    TerminHari: 14,
    Aktif: true,
};

describe('Tambah pemasok dari dalam formulir lain', () => {
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    function PasangDalamFormInduk(saatTersimpan: (p: OpsiPemasok) => void, saatKirimInduk: () => void) {
        render(
            <form
                onSubmit={(peristiwa) => {
                    peristiwa.preventDefault();
                    saatKirimInduk();
                }}
            >
                <TombolPemasokBaru saatTersimpan={saatTersimpan} />
            </form>,
        );
    }

    it('menyimpan lewat JSON, mengembalikan pilihan pemasok, dan tidak ikut mengirim formulir induk', async () => {
        const FetchTiruan = vi.fn().mockResolvedValue({
            ok: true,
            status: 201,
            json: () => Promise.resolve({ Data: PemasokDibuat }),
        });
        vi.stubGlobal('fetch', FetchTiruan);
        const Tersimpan = vi.fn();
        const KirimInduk = vi.fn();

        PasangDalamFormInduk(Tersimpan, KirimInduk);
        fireEvent.click(screen.getByRole('button', { name: /Tambah pemasok baru/ }));
        fireEvent.change(screen.getByRole('textbox', { name: /Nama pemasok/ }), {
            target: { value: 'CV Tani Makmur' },
        });
        // Kode disarankan dari nama.
        expect(screen.getByRole<HTMLInputElement>('textbox', { name: /Kode pemasok/ }).value).toBe('TANI-MAKMUR');
        fireEvent.change(screen.getByRole('textbox', { name: 'Termin bayar (hari)' }), { target: { value: '14' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pemasok' }));

        await waitFor(() => expect(Tersimpan).toHaveBeenCalledWith(PemasokDibuat));
        const [alamat, opsi] = FetchTiruan.mock.calls[0] as [string, { method: string; body: string }];
        expect(alamat).toBe('/kelola/pembelian/pemasok/cepat');
        expect(opsi.method).toBe('POST');
        expect(JSON.parse(opsi.body)).toMatchObject({
            Kode: 'TANI-MAKMUR',
            Nama: 'CV Tani Makmur',
            Pkp: false,
            TerminHari: 14,
            NoHp: null,
        });
        expect(KirimInduk).not.toHaveBeenCalled();
        // Dialog menutup dan isiannya dikosongkan untuk pemakaian berikutnya.
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    });

    it('menampilkan penyebab gagal dari server (kode sudah dipakai) dan dialog tetap terbuka', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: false,
                status: 422,
                json: () => Promise.resolve({ message: 'x', errors: { Kode: ['Kode sudah dipakai pemasok lain.'] } }),
            }),
        );
        const Tersimpan = vi.fn();

        PasangDalamFormInduk(Tersimpan, vi.fn());
        fireEvent.click(screen.getByRole('button', { name: /Tambah pemasok baru/ }));
        fireEvent.change(screen.getByRole('textbox', { name: /Nama pemasok/ }), {
            target: { value: 'CV Tani Makmur' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pemasok' }));

        expect(await screen.findByText('Kode sudah dipakai pemasok lain.')).toBeTruthy();
        expect(Tersimpan).not.toHaveBeenCalled();
        expect(screen.getByRole('dialog')).toBeTruthy();
    });

    it('nama dan kode wajib diisi sebelum dikirim', () => {
        const FetchTiruan = vi.fn();
        vi.stubGlobal('fetch', FetchTiruan);

        PasangDalamFormInduk(vi.fn(), vi.fn());
        fireEvent.click(screen.getByRole('button', { name: /Tambah pemasok baru/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pemasok' }));

        expect(screen.getByText('Isi nama dan kode pemasok.')).toBeTruthy();
        expect(FetchTiruan).not.toHaveBeenCalled();
    });

    it('pemasok baru langsung masuk ke pilihan induk', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({ ok: true, status: 201, json: () => Promise.resolve({ Data: PemasokDibuat }) }),
        );

        function Induk() {
            const [daftar, AturDaftar] = useState<OpsiPemasok[]>([]);

            return (
                <div>
                    <ul aria-label="Pemasok">
                        {daftar.map((p) => (
                            <li key={p.Uuid}>{p.Nama}</li>
                        ))}
                    </ul>
                    <TombolPemasokBaru saatTersimpan={(p) => AturDaftar((lama) => [...lama, p])} />
                </div>
            );
        }

        render(<Induk />);
        fireEvent.click(screen.getByRole('button', { name: /Tambah pemasok baru/ }));
        fireEvent.change(screen.getByRole('textbox', { name: /Nama pemasok/ }), {
            target: { value: 'CV Tani Makmur' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pemasok' }));

        expect(await screen.findByText('CV Tani Makmur')).toBeTruthy();
    });
});

describe('Simpan & tambah lagi', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/pembelian/pemasok/buat'));
    afterEach(() => cleanup());

    it('pemasok: mengirim TambahLagi dan memulai formulir bersih, sedangkan Simpan biasa tidak', () => {
        RenderUji(<FormPemasok uuid={null} awal={IsianPemasokKosong} saatBatal={vi.fn()} />);

        fireEvent.change(screen.getByRole('textbox', { name: /Nama pemasok/ }), { target: { value: 'UD Jaya Abadi' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan & tambah lagi' }));

        expect(tiruanRouter.post).toHaveBeenCalledTimes(1);
        const [alamat, data, opsi] = tiruanRouter.post.mock.calls[0] as [
            string,
            Record<string, unknown>,
            { preserveState: boolean; preserveScroll: boolean },
        ];
        expect(alamat).toBe('/kelola/pembelian/pemasok');
        expect(data.TambahLagi).toBe(true);
        expect(opsi.preserveState).toBe(false);
        expect(opsi.preserveScroll).toBe(false);

        fireEvent.click(screen.getByRole('button', { name: 'Simpan pemasok' }));
        const [, dataBiasa, opsiBiasa] = tiruanRouter.post.mock.calls[1] as [
            string,
            Record<string, unknown>,
            { preserveState: boolean },
        ];
        expect(dataBiasa).not.toHaveProperty('TambahLagi');
        expect(opsiBiasa.preserveState).toBe(true);
    });

    it('pemasok yang sedang diubah tidak punya tombol tambah lagi', () => {
        RenderUji(<FormPemasok uuid="01J9PMS0000000000000000001" awal={IsianPemasokKosong} saatBatal={vi.fn()} />);

        expect(screen.queryByRole('button', { name: 'Simpan & tambah lagi' })).toBeNull();
    });

    it('pelanggan: mengirim TambahLagi dan memulai formulir bersih', () => {
        AturHalamanUji({}, '/kelola/pelanggan/buat');
        RenderUji(<IsiFormulirPelanggan pelanggan={null} saatBatal={vi.fn()} />);

        fireEvent.change(screen.getByRole('textbox', { name: /Nama pelanggan/ }), { target: { value: 'Bu Ani' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan & tambah lagi' }));

        const [alamat, data, opsi] = tiruanRouter.post.mock.calls[0] as [
            string,
            Record<string, unknown>,
            { preserveState: boolean },
        ];
        expect(alamat).toBe('/kelola/pelanggan');
        expect(data.TambahLagi).toBe(true);
        expect(opsi.preserveState).toBe(false);
    });
});
