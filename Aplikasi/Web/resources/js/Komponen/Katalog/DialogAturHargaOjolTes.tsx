import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import DialogAturHargaOjol from '@/Komponen/Katalog/DialogAturHargaOjol';
import { PilihOpsi } from '@/Pengujian/InteraksiPilihan';
import type { PratinjauHargaKanal } from '@/Tipe/Katalog';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * "Atur harga ojol": isi harga GoFood/GrabFood/ShopeeFood dari harga biasa dalam satu dialog. Alur: isi pilihan, lihat
 * contoh (pratinjau dari server, tidak menyimpan), baru terapkan. Mengubah isian membatalkan contoh.
 */
const Outlet = [
    { Nilai: '01J9OUT0000000000000000001', Label: 'Kopi Senja Solo Baru' },
    { Nilai: '01J9OUT0000000000000000002', Label: 'Kopi Senja Kartasura' },
];

const Pratinjau: PratinjauHargaKanal = {
    Kanal: [
        { Kanal: 'GoFood', Label: 'GoFood', DaftarAda: false, Diisi: 12, Dilewati: 0 },
        { Kanal: 'GrabFood', Label: 'GrabFood', DaftarAda: true, Diisi: 9, Dilewati: 3 },
        { Kanal: 'ShopeeFood', Label: 'ShopeeFood', DaftarAda: false, Diisi: 12, Dilewati: 0 },
    ],
    Contoh: [
        { Produk: 'Kopi Susu Gula Aren', Sku: 'KSG', Satuan: 'cup', HargaBiasa: '20000.00', HargaBaru: '26000.00' },
        { Produk: 'Roti Bakar Coklat', Sku: 'RBC', Satuan: 'porsi', HargaBiasa: '15000.00', HargaBaru: '19500.00' },
    ],
    TotalSatuan: 12,
};

function Pasang(saatTutup = vi.fn()) {
    AturHalamanUji({}, '/kelola/daftar-harga');
    RenderUji(<DialogAturHargaOjol Outlet={Outlet} saatTutup={saatTutup} />);

    return saatTutup;
}

function IsiPersen(nilai: string) {
    fireEvent.change(screen.getByRole('textbox', { name: /Lebih mahal berapa persen/ }), { target: { value: nilai } });
}

describe('Dialog Atur harga ojol', () => {
    let FetchTiruan: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        FetchTiruan = vi
            .fn()
            .mockResolvedValue({ ok: true, status: 200, json: () => Promise.resolve({ Data: Pratinjau }) });
        vi.stubGlobal('fetch', FetchTiruan);
    });

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('ketiga platform tercentang bawaan, dan "Lihat contoh" baru aktif setelah besar perubahan diisi', () => {
        Pasang();

        for (const nama of ['GoFood', 'GrabFood', 'ShopeeFood']) {
            expect(screen.getByRole('checkbox', { name: nama }).getAttribute('data-state')).toBe('checked');
        }

        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Lihat contoh' }).disabled).toBe(true);
        IsiPersen('30');
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Lihat contoh' }).disabled).toBe(false);
    });

    it('lihat contoh: mengirim pilihan ke server, menampilkan jumlah per platform dan contoh harga lama → baru', async () => {
        Pasang();
        IsiPersen('30');
        fireEvent.click(screen.getByRole('button', { name: 'Lihat contoh' }));

        expect(await screen.findAllByText(/12 produk akan diisi/)).toHaveLength(2);
        expect(screen.getByText(/9 produk akan diisi, 3 dilewati karena sudah punya harga/)).toBeTruthy();
        expect(screen.getAllByText(/daftar harganya dibuat otomatis/)).toHaveLength(2);
        expect(screen.getByText('Kopi Susu Gula Aren')).toBeTruthy();
        expect(screen.getByText('Rp 26.000')).toBeTruthy();
        expect(screen.getByText(/Dan 10 produk lainnya/)).toBeTruthy();

        const [alamat, opsi] = FetchTiruan.mock.calls[0] as [string, { body: string }];
        expect(alamat).toBe('/kelola/daftar-harga/atur-kanal/pratinjau');
        expect(JSON.parse(opsi.body)).toEqual({
            Kanal: ['GoFood', 'GrabFood', 'ShopeeFood'],
            Mode: 'NaikPersen',
            Nilai: '30',
            Pembulatan: 500,
            Timpa: false,
            UuidOutlet: [],
        });
        // Menyimpan baru terjadi saat "Terapkan".
        expect(tiruanRouter.post).not.toHaveBeenCalled();
    });

    it('terapkan: mengirim pilihan yang sama lewat Inertia dan menutup dialog bila berhasil', async () => {
        const SaatTutup = Pasang();
        IsiPersen('30');
        fireEvent.click(screen.getByRole('button', { name: 'Lihat contoh' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Terapkan harga ojol' }));

        const [alamat, data, opsi] = tiruanRouter.post.mock.calls[0] as [
            string,
            Record<string, unknown>,
            { onSuccess: () => void },
        ];
        expect(alamat).toBe('/kelola/daftar-harga/atur-kanal');
        expect(data).toMatchObject({ Mode: 'NaikPersen', Nilai: '30', Pembulatan: 500, Timpa: false, UuidOutlet: [] });
        opsi.onSuccess();
        expect(SaatTutup).toHaveBeenCalled();
    });

    it('mengubah isian apa pun membatalkan contoh, jadi yang diterapkan selalu yang terakhir dilihat', async () => {
        Pasang();
        IsiPersen('30');
        fireEvent.click(screen.getByRole('button', { name: 'Lihat contoh' }));
        await screen.findByRole('button', { name: 'Terapkan harga ojol' });

        IsiPersen('40');

        expect(screen.queryByRole('button', { name: 'Terapkan harga ojol' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Lihat contoh' })).toBeTruthy();
        expect(screen.queryByText('Contoh harga baru')).toBeNull();
    });

    it('cara lain: turun rupiah mengganti label isian dan ikut terkirim, timpa dikirim sebagai true', async () => {
        Pasang();
        PilihOpsi(screen.getByRole('combobox', { name: /Harga di platform dibanding harga biasa/ }), 'TurunNominal');
        fireEvent.change(screen.getByRole('textbox', { name: /Lebih murah berapa rupiah/ }), {
            target: { value: '1000' },
        });
        await waitFor(() => expect(document.querySelector('[data-slot="popover-content"]')).toBeNull());
        PilihOpsi(screen.getByRole('combobox', { name: /Produk yang sudah punya harga/ }), 'Timpa');
        fireEvent.click(screen.getByRole('button', { name: 'Lihat contoh' }));

        await screen.findByText('Contoh harga baru');
        const [, opsi] = FetchTiruan.mock.calls[0] as [string, { body: string }];
        expect(JSON.parse(opsi.body)).toMatchObject({ Mode: 'TurunNominal', Nilai: '1000', Timpa: true });
    });

    it('hanya outlet tertentu: wajib memilih outlet, lalu Uuid outlet ikut terkirim', async () => {
        Pasang();
        IsiPersen('30');
        PilihOpsi(screen.getByRole('combobox', { name: 'Berlaku di' }), 'Pilih');
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Lihat contoh' }).disabled).toBe(true);

        fireEvent.click(screen.getByRole('checkbox', { name: 'Kopi Senja Kartasura' }));
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Lihat contoh' }).disabled).toBe(false);
        fireEvent.click(screen.getByRole('button', { name: 'Lihat contoh' }));

        await screen.findByText('Contoh harga baru');
        const [, opsi] = FetchTiruan.mock.calls[0] as [string, { body: string }];
        expect(JSON.parse(opsi.body).UuidOutlet).toEqual(['01J9OUT0000000000000000002']);
    });

    it('tidak ada platform terpilih: tombol mati', () => {
        Pasang();
        IsiPersen('30');
        for (const nama of ['GoFood', 'GrabFood', 'ShopeeFood']) {
            fireEvent.click(screen.getByRole('checkbox', { name: nama }));
        }

        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Lihat contoh' }).disabled).toBe(true);
    });

    it('penolakan dari server tampil di dalam dialog dan tidak ada contoh', async () => {
        FetchTiruan.mockResolvedValue({
            ok: false,
            status: 422,
            json: () =>
                Promise.resolve({
                    Galat: {
                        Kode: 'HargaNegatif',
                        Pesan: 'Harga Gelas Plastik akan menjadi negatif. Kurangi nilai penurunan.',
                    },
                }),
        });
        Pasang();
        IsiPersen('30');
        fireEvent.click(screen.getByRole('button', { name: 'Lihat contoh' }));

        expect(await screen.findByText(/akan menjadi negatif/)).toBeTruthy();
        expect(screen.queryByText('Contoh harga baru')).toBeNull();
    });
});
