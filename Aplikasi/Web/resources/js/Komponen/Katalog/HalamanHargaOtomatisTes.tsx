import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanHargaOtomatis from '@/Halaman/Kelola/Produk/HargaOtomatis';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { PilihOpsi } from '@/Pengujian/InteraksiPilihan';
import type { PratinjauHargaOtomatis, PropsHargaOtomatis } from '@/Tipe/Katalog';

const { KirimJsonTiruan } = vi.hoisted(() => ({ KirimJsonTiruan: vi.fn() }));

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);
vi.mock('@/Pustaka/PermintaanJson', async (asli) => ({
    ...(await asli<typeof import('@/Pustaka/PermintaanJson')>()),
    KirimJson: KirimJsonTiruan,
}));

/*
 * D-266 dan D-268 halaman Harga otomatis: tiga langkah (untung, kalau harga beli turun, pengaman salah ketik), untung
 * khusus yang opsional, pratinjau dari server, dan bilah simpan yang hanya muncul bila ada yang diubah.
 */
const Awal: PropsHargaOtomatis = {
    ProdukBerstok: 148,
    Pengaturan: { Aktif: false, BatasPerubahanPersen: '20.00' },
    Umum: { PersenUntung: '20.0000', Pembulatan: 500, Arah: 'NaikTurun' },
    Pengecualian: [],
    Usulan: [],
    OpsiKategori: [{ Uuid: '01J9KTG0000000000000000001', Nama: 'Minuman › Literan' }],
    OpsiCakupan: [],
    OpsiArah: [],
    OpsiPembulatan: [0, 100, 500, 1000],
    Izin: { Ubah: true },
};

const Khusus: PropsHargaOtomatis['Pengecualian'][number] = {
    Uuid: '01J9ATR0000000000000000001',
    Cakupan: 'Kategori',
    Sasaran: 'Minuman › Literan',
    UuidKategori: '01J9KTG0000000000000000001',
    Produk: null,
    PersenUntung: '30.0000',
    Pembulatan: 500,
    Arah: 'NaikTurun',
    Aktif: true,
};

const Usulan: PropsHargaOtomatis['Usulan'][number] = {
    Uuid: '01J9USL0000000000000000001',
    Produk: { Uuid: '01J9PRD0000000000000000001', Nama: 'Gula Pasir Lokal 1 kg', Sku: 'GLP' },
    Modal: '100000.00',
    PersenUntung: '20.0000',
    HargaLama: '12000.00',
    HargaBaru: '120000.00',
    PerubahanPersen: '900.00',
    DibuatPada: '2026-11-11T09:00:00+07:00',
};

const Menyala: Partial<PropsHargaOtomatis> = { Pengaturan: { Aktif: true, BatasPerubahanPersen: '20.00' } };

function Pasang(props: Partial<PropsHargaOtomatis> = {}) {
    AturHalamanUji({}, '/kelola/produk/harga-otomatis');
    RenderUji(<HalamanHargaOtomatis {...Awal} {...props} />);
}

function Isian(nama: RegExp) {
    return screen.getByRole<HTMLInputElement>('textbox', { name: nama });
}

const Pratinjau: PratinjauHargaOtomatis = {
    Ringkasan: { Berubah: 41, Menunggu: 1, Tetap: 106, TanpaHargaBeli: 3 },
    Contoh: [
        {
            Uuid: '01J9PRD0000000000000000002',
            Nama: 'Minyak Goreng 2 L',
            Sku: null,
            Status: 'Menunggu',
            Modal: '340000.00',
            HargaLama: '40000.00',
            HargaBaru: '408000.00',
            PerubahanPersen: '920.00',
        },
        {
            Uuid: '01J9PRD0000000000000000003',
            Nama: 'Teh Botol Sosro 450 ml',
            Sku: null,
            Status: 'Berubah',
            Modal: '3800.00',
            HargaLama: '4500.00',
            HargaBaru: '5000.00',
            PerubahanPersen: '11.11',
        },
        {
            Uuid: '01J9PRD0000000000000000004',
            Nama: 'Gula Pasir 1 kg',
            Sku: null,
            Status: 'Ditahan',
            Modal: '9500.00',
            HargaLama: '12000.00',
            HargaBaru: '11400.00',
            PerubahanPersen: '-5.00',
        },
    ],
};

describe('Halaman Harga otomatis', () => {
    beforeEach(() => {
        tiruanRouter.post.mockClear();
        tiruanRouter.put.mockClear();
        tiruanRouter.delete.mockClear();
        KirimJsonTiruan.mockReset();
        KirimJsonTiruan.mockResolvedValue({ Data: Pratinjau });
    });

    afterEach(() => cleanup());

    const Saklar = () => screen.getByRole('switch', { name: 'Harga jual mengikuti harga beli' });
    const Bilah = () => screen.queryByRole('region', { name: 'Simpan perubahan' });
    const Tunggu = { timeout: 3000 };

    it('awalnya mati: isian tetap bisa diatur, tidak ada bilah simpan, banner persetujuan, atau daftar usulan', () => {
        Pasang();

        expect(Saklar().getAttribute('aria-checked')).toBe('false');
        expect(screen.getByText('Mati')).toBeTruthy();
        expect(screen.getByText(/Saat ini mati/)).toBeTruthy();
        expect(Isian(/Untung dari harga beli/).disabled).toBe(false);
        expect(Isian(/Untung dari harga beli/).value).toBe('20');
        expect(Bilah()).toBeNull();
        expect(screen.queryByText(/harga menunggu persetujuan Anda/)).toBeNull();
        expect(screen.queryByRole('region', { name: 'Menunggu persetujuan Anda' })).toBeNull();
    });

    it('menyalakan memunculkan bilah simpan, Simpan saja mengirim semua isian dalam satu permintaan', () => {
        Pasang();
        fireEvent.click(Saklar());

        expect(Saklar().getAttribute('aria-checked')).toBe('true');
        expect(screen.getByText(/148 produk berstok akan mengikuti aturan ini/)).toBeTruthy();
        expect(within(Bilah() as HTMLElement).getByText('Ada perubahan yang belum disimpan')).toBeTruthy();

        fireEvent.change(Isian(/Untung dari harga beli/), { target: { value: '25' } });
        fireEvent.change(Isian(/Minta persetujuan kalau harga berubah lebih dari/), { target: { value: '30' } });
        fireEvent.click(screen.getByRole('radio', { name: /Harga jual tetap/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan saja' }));

        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/produk/harga-otomatis/pengaturan',
            {
                Aktif: true,
                BatasPerubahanPersen: '30',
                PersenUntung: '25',
                Pembulatan: 500,
                Arah: 'HanyaNaik',
                Khusus: [],
            },
            expect.anything(),
        );
        expect(tiruanRouter.put.mock.calls[0]?.[1]).not.toHaveProperty('HitungUlang');
    });

    it('Simpan dan hitung ulang sekarang mengirim HitungUlang bersama pengaturan yang sama', () => {
        Pasang();
        fireEvent.click(Saklar());
        fireEvent.click(screen.getByRole('button', { name: 'Simpan dan hitung ulang sekarang' }));

        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/produk/harga-otomatis/pengaturan',
            expect.objectContaining({ Aktif: true, PersenUntung: '20', HitungUlang: true }),
            expect.anything(),
        );
    });

    it('saklar dimatikan dari keadaan menyala: hanya satu tombol Simpan, tanpa tawaran hitung ulang', () => {
        Pasang(Menyala);
        fireEvent.click(Saklar());

        const bilah = within(Bilah() as HTMLElement);
        expect(bilah.getByRole('button', { name: 'Simpan' })).toBeTruthy();
        expect(bilah.queryByRole('button', { name: 'Simpan dan hitung ulang sekarang' })).toBeNull();
    });

    it('pilihan cepat mengisi untung dan batas, yang cocok tampil terpilih', () => {
        Pasang(Menyala);
        const untung = within(screen.getByRole('group', { name: /Pilihan cepat untung dari harga beli/i }));

        expect(untung.getByRole('button', { name: '20%' }).getAttribute('aria-pressed')).toBe('true');
        fireEvent.click(untung.getByRole('button', { name: '30%' }));

        expect(Isian(/Untung dari harga beli/).value).toBe('30');
        expect(untung.getByRole('button', { name: '30%' }).getAttribute('aria-pressed')).toBe('true');
        expect(untung.getByRole('button', { name: '20%' }).getAttribute('aria-pressed')).toBe('false');

        const batas = within(screen.getByRole('group', { name: /Pilihan cepat minta persetujuan/i }));
        fireEvent.click(batas.getByRole('button', { name: '50%' }));
        expect(Isian(/Minta persetujuan kalau harga berubah lebih dari/).value).toBe('50');
    });

    it('contoh hitungan mengikuti untung dan pembulatan: Rp 10.000 dengan 7,5% dibulatkan ke Rp 500 menjadi Rp 11.000', () => {
        Pasang(Menyala);
        expect(screen.getByText(/Contoh: beli Rp 10\.000, untung 20%, harga jual/)).toBeTruthy();
        expect(screen.getByText('Rp 12.000')).toBeTruthy();

        fireEvent.change(Isian(/Untung dari harga beli/), { target: { value: '7,5' } });

        expect(screen.getByText(/Contoh: beli Rp 10\.000, untung 7,5%/)).toBeTruthy();
        expect(screen.getByText('Rp 11.000')).toBeTruthy();

        PilihOpsi(screen.getByRole('combobox', { name: 'Bulatkan harga jual ke' }), '0');
        expect(screen.getByText('Rp 10.750')).toBeTruthy();
    });

    it('Batalkan mengembalikan semua isian ke yang tersimpan dan menutup bilah', () => {
        Pasang(Menyala);
        fireEvent.change(Isian(/Untung dari harga beli/), { target: { value: '40' } });
        fireEvent.click(screen.getByRole('radio', { name: /Harga jual tetap/ }));
        expect(Bilah()).not.toBeNull();
        // Mengetuk Batalkan di peramban memindahkan fokus dari isian, dan isian yang tidak lagi fokus menampilkan nilai tersimpan.
        fireEvent.blur(Isian(/Untung dari harga beli/));

        fireEvent.click(screen.getByRole('button', { name: 'Batalkan' }));

        expect(Isian(/Untung dari harga beli/).value).toBe('20');
        expect(screen.getByRole('radio', { name: /Harga jual ikut turun/ }).getAttribute('aria-checked')).toBe('true');
        expect(Bilah()).toBeNull();
        expect(tiruanRouter.put).not.toHaveBeenCalled();
    });

    it('untung kosong menahan Simpan dan pratinjau, dengan petunjuk yang jelas', async () => {
        Pasang(Menyala);
        await waitFor(() => expect(KirimJsonTiruan).toHaveBeenCalled(), Tunggu);
        KirimJsonTiruan.mockClear();

        fireEvent.change(Isian(/Untung dari harga beli/), { target: { value: '' } });

        const bilah = within(Bilah() as HTMLElement);
        expect(bilah.getByRole<HTMLButtonElement>('button', { name: 'Simpan saja' }).disabled).toBe(true);
        expect(bilah.getByText('Lengkapi isian yang masih kosong supaya bisa disimpan.')).toBeTruthy();
        expect(screen.getByText(/Isi untung dan batas persetujuan dengan angka/)).toBeTruthy();
        await new Promise((selesai) => setTimeout(selesai, 700));
        expect(KirimJsonTiruan).not.toHaveBeenCalled();
    });

    it('pratinjau: meminta server dengan isian layar, lalu menampilkan ringkasan dan contoh', async () => {
        Pasang(Menyala);

        expect(await screen.findByText('harga akan berubah', {}, Tunggu)).toBeTruthy();
        expect(KirimJsonTiruan).toHaveBeenCalledWith('/kelola/produk/harga-otomatis/pratinjau', {
            BatasPerubahanPersen: '20',
            PersenUntung: '20',
            Pembulatan: 500,
            Arah: 'NaikTurun',
            Khusus: [],
        });
        const panel = within(screen.getByRole('region', { name: 'Pratinjau, kalau disimpan sekarang' }));
        expect(panel.getByText('41')).toBeTruthy();
        expect(panel.getByText('106')).toBeTruthy();
        expect(panel.getByText('Minyak Goreng 2 L')).toBeTruthy();
        expect(panel.getByText('Menunggu persetujuan')).toBeTruthy();
        expect(panel.getByText('+11,11%')).toBeTruthy();
        expect(panel.getByText('Tetap, harga beli turun')).toBeTruthy();
        expect(panel.getByText(/3 produk belum pernah dibeli/)).toBeTruthy();
    });

    it('pratinjau dihitung ulang setelah isian berubah, dan tidak menyimpan apa pun', async () => {
        Pasang(Menyala);
        await screen.findByText('harga akan berubah', {}, Tunggu);

        fireEvent.change(Isian(/Untung dari harga beli/), { target: { value: '35' } });

        await waitFor(
            () =>
                expect(KirimJsonTiruan).toHaveBeenLastCalledWith(
                    '/kelola/produk/harga-otomatis/pratinjau',
                    expect.objectContaining({ PersenUntung: '35' }),
                ),
            Tunggu,
        );
        expect(tiruanRouter.put).not.toHaveBeenCalled();
        expect(tiruanRouter.post).not.toHaveBeenCalled();
    });

    it('pratinjau saat mati menjelaskan bahwa ini hanya kalau dinyalakan', () => {
        Pasang();

        expect(screen.getByText(/Harga otomatis belum menyala/)).toBeTruthy();
    });

    it('pratinjau gagal: pesan dari server dan tombol Coba lagi meminta ulang', async () => {
        KirimJsonTiruan.mockRejectedValueOnce(new Error('Isi untung berupa angka.'));
        Pasang(Menyala);

        expect(await screen.findByText('Isi untung berupa angka.', {}, Tunggu)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Coba lagi' }));

        expect(await screen.findByText('harga akan berubah', {}, Tunggu)).toBeTruthy();
        expect(KirimJsonTiruan).toHaveBeenCalledTimes(2);
    });

    it('pratinjau tanpa produk yang punya harga beli menjelaskan apa yang perlu dilakukan', async () => {
        KirimJsonTiruan.mockResolvedValue({
            Data: { Ringkasan: { Berubah: 0, Menunggu: 0, Tetap: 0, TanpaHargaBeli: 5 }, Contoh: [] },
        });
        Pasang(Menyala);

        expect(await screen.findByText(/Belum ada produk yang punya harga beli/, {}, Tunggu)).toBeTruthy();
    });

    it('untung khusus tampil ringkas, Ubah membukanya, dan Simpan mengirim seluruh daftar', () => {
        Pasang({ ...Menyala, Pengecualian: [Khusus] });
        const daftar = within(screen.getByRole('list', { name: 'Untung khusus' }));

        expect(daftar.getByText('Minuman › Literan')).toBeTruthy();
        expect(daftar.getByText('Untung 30%')).toBeTruthy();
        expect(Bilah()).toBeNull();

        fireEvent.click(daftar.getByRole('button', { name: /Ubah untung khusus/ }));
        fireEvent.change(
            within(screen.getByRole('list', { name: 'Untung khusus' })).getByRole('textbox', { name: /Untung/ }),
            {
                target: { value: '35' },
            },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Selesai' }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan saja' }));

        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/produk/harga-otomatis/pengaturan',
            expect.objectContaining({
                Khusus: [
                    {
                        Cakupan: 'Kategori',
                        UuidKategori: '01J9KTG0000000000000000001',
                        UuidProduk: null,
                        PersenUntung: '35',
                    },
                ],
            }),
            expect.anything(),
        );
    });

    it('Hapus mengeluarkan untung khusus dari daftar dan baru berlaku saat disimpan', () => {
        Pasang({ ...Menyala, Pengecualian: [Khusus] });

        fireEvent.click(screen.getByRole('button', { name: /Hapus untung khusus/ }));

        expect(screen.queryByRole('list', { name: 'Untung khusus' })).toBeNull();
        expect(screen.getByText('Belum ada. Semua produk memakai untung utama.')).toBeTruthy();
        expect(tiruanRouter.delete).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Simpan saja' }));
        expect(tiruanRouter.put.mock.calls[0]?.[1]).toMatchObject({ Khusus: [] });
    });

    it('Tambah untung khusus: Selesai dan Simpan baru aktif setelah sasaran dan untung terisi', () => {
        Pasang(Menyala);
        fireEvent.click(screen.getByRole('button', { name: 'Tambah untung khusus' }));
        const daftar = within(screen.getByRole('list', { name: 'Untung khusus' }));

        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Selesai' }).disabled).toBe(true);
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Simpan saja' }).disabled).toBe(true);

        PilihOpsi(daftar.getByRole('combobox', { name: 'Kategori' }), '01J9KTG0000000000000000001');
        fireEvent.change(daftar.getByRole('textbox', { name: /Untung/ }), { target: { value: '35' } });
        fireEvent.click(screen.getByRole('button', { name: 'Selesai' }));

        expect(screen.getByText('Untung 35%')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Simpan saja' }));
        expect(tiruanRouter.put.mock.calls[0]?.[1]).toMatchObject({
            Khusus: [
                {
                    Cakupan: 'Kategori',
                    UuidKategori: '01J9KTG0000000000000000001',
                    UuidProduk: null,
                    PersenUntung: '35',
                },
            ],
        });
    });

    it('harga yang menunggu persetujuan: banner di atas, daftar lama menjadi baru, Setujui dan Tolak mengirim POST', () => {
        Pasang({ ...Menyala, Usulan: [Usulan] });

        expect(screen.getByText('1 harga menunggu persetujuan Anda')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Lihat dan putuskan' }).getAttribute('href')).toBe('#usulan');
        expect(screen.getByText('Gula Pasir Lokal 1 kg')).toBeTruthy();
        expect(screen.getByText(/Rp 12\.000 menjadi Rp 120\.000 \(\+900%\)/)).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Setujui' }));
        expect(tiruanRouter.post.mock.calls[0]?.[0]).toBe(
            '/kelola/produk/harga-otomatis/usulan/01J9USL0000000000000000001/terapkan',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Tolak' }));
        expect(tiruanRouter.post.mock.calls[1]?.[0]).toBe(
            '/kelola/produk/harga-otomatis/usulan/01J9USL0000000000000000001/tolak',
        );
    });

    it('Hitung ulang semua harga hanya tampil saat menyala dan belum ada perubahan, lalu meminta konfirmasi', () => {
        Pasang();
        expect(screen.queryByRole('button', { name: 'Hitung ulang semua harga sekarang' })).toBeNull();
        cleanup();

        Pasang(Menyala);
        fireEvent.click(screen.getByRole('button', { name: 'Hitung ulang semua harga sekarang' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Hitung ulang' }));

        expect(tiruanRouter.post.mock.calls[0]?.[0]).toBe('/kelola/produk/harga-otomatis/jalankan');
    });

    it('tanpa izin ubah harga: semua tombol disembunyikan dan isian serta saklar terkunci', () => {
        Pasang({ ...Menyala, Izin: { Ubah: false }, Pengecualian: [Khusus], Usulan: [Usulan] });

        for (const nama of [
            'Simpan saja',
            'Tambah untung khusus',
            'Setujui',
            'Tolak',
            'Hitung ulang semua harga sekarang',
        ]) {
            expect(screen.queryByRole('button', { name: nama })).toBeNull();
        }

        expect(screen.queryByRole('button', { name: /Ubah untung khusus|Hapus untung khusus/ })).toBeNull();
        expect(Isian(/Untung dari harga beli/).disabled).toBe(true);
        expect(Saklar().hasAttribute('disabled')).toBe(true);
        expect(Bilah()).toBeNull();
    });
});
