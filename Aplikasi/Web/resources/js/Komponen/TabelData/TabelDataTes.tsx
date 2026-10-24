import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import type { ReactElement } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';

import { BacaKeadaanDariUrl, BacaUrut, TulisKeadaanKeUrl } from './KeadaanUrl';
import { BuatPresetTanggal, RingkasSaring } from './Saring';
import TabelData from './TabelData';
import type { DefinisiSaring, HasilTabel, KolomTabel } from './Tipe';
import { UbahNilai } from '@/Pengujian/InteraksiPilihan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

type Produk = { Uuid: string; Nama: string; Harga: string; Status: string; Kategori: string };

const kolom: KolomTabel<Produk>[] = [
    { accessorKey: 'Nama', header: 'Nama produk', meta: { label: 'Nama produk', prioritas: 'utama', wajib: true } },
    { accessorKey: 'Harga', header: 'Harga', meta: { label: 'Harga', angka: true, prioritas: 'penting' } },
    { accessorKey: 'Status', header: 'Status', meta: { label: 'Status', prioritas: 'penting' } },
    {
        accessorKey: 'Kategori',
        header: 'Kategori',
        enableSorting: false,
        meta: { label: 'Kategori', prioritas: 'rendah' },
    },
];

const saring: DefinisiSaring[] = [
    {
        id: 'Status',
        label: 'Status',
        jenis: 'pilihanBanyak',
        opsi: [
            { nilai: 'Aktif', label: 'Aktif' },
            { nilai: 'Nonaktif', label: 'Nonaktif' },
        ],
    },
    { id: 'Diskon', label: 'Diskon', jenis: 'ya', labelAktif: 'Sedang diskon' },
];

function BuatProduk(jumlah: number, awalan = 'Kopi Susu Gula Aren Ukuran Besar'): Produk[] {
    return Array.from({ length: jumlah }, (_, i) => ({
        Uuid: `01K5PRODUK${String(i).padStart(16, '0')}`,
        Nama: `${awalan} ${String(i + 1)}`,
        Harga: String(18000 + i * 1000),
        Status: i % 2 === 0 ? 'Aktif' : 'Nonaktif',
        Kategori: 'Minuman',
    }));
}

function Hasil(data: Produk[], halaman = 1, total = data.length, perHalaman = 25): HasilTabel<Produk> {
    return {
        Data: data,
        Meta: {
            Halaman: halaman,
            PerHalaman: perHalaman,
            Total: total,
            JumlahHalaman: Math.max(1, Math.ceil(total / perHalaman)),
        },
    };
}

function Render(elemen: ReactElement) {
    const klien = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(<QueryClientProvider client={klien}>{elemen}</QueryClientProvider>);
}

const panggilanFetch: string[] = [];

function PasangFetch(jawab: (url: string) => HasilTabel<Produk> | number) {
    panggilanFetch.length = 0;
    vi.stubGlobal(
        'fetch',
        vi.fn((url: string) => {
            panggilanFetch.push(url);
            const hasil = jawab(url);

            return Promise.resolve(
                typeof hasil === 'number'
                    ? { ok: false, status: hasil, json: () => Promise.resolve({}) }
                    : { ok: true, status: 200, json: () => Promise.resolve(hasil) },
            );
        }),
    );
}

function AturLebar(lebar: 'hp' | 'tablet' | 'desktop') {
    window.matchMedia = (kueri: string) =>
        ({
            matches:
                (kueri === '(max-width: 639px)' && lebar === 'hp') ||
                (kueri === '(max-width: 1023px)' && lebar !== 'desktop'),
            media: kueri,
            onchange: null,
            addEventListener: () => undefined,
            removeEventListener: () => undefined,
            addListener: () => undefined,
            removeListener: () => undefined,
            dispatchEvent: () => false,
        }) as MediaQueryList;
}

const MatchMediaAsli = window.matchMedia;

describe('KeadaanUrl (D-16 kontrak parameter TabelData)', () => {
    it('membaca & menulis cari, urut bertingkat, halaman, ukuran halaman, dan filter; bawaan tidak ditulis', () => {
        const bawaan = BacaUrut('-DibuatPada');
        const keadaan = BacaKeadaanDariUrl(
            '?cari=kopi&urut=Nama,-Harga&halaman=3&perHalaman=50&saring%5BStatus%5D=Aktif,Nonaktif&tab=stok',
            bawaan,
        );

        expect(keadaan).toEqual({
            cari: 'kopi',
            urut: [
                { id: 'Nama', desc: false },
                { id: 'Harga', desc: true },
            ],
            halaman: 3,
            perHalaman: 50,
            saring: { Status: 'Aktif,Nonaktif' },
        });
        expect(TulisKeadaanKeUrl(keadaan, bawaan, 'tab=stok&halaman=9')).toBe(
            'tab=stok&cari=kopi&urut=Nama%2C-Harga&halaman=3&perHalaman=50&saring%5BStatus%5D=Aktif%2CNonaktif',
        );
        expect(
            TulisKeadaanKeUrl({ ...keadaan, cari: '', urut: bawaan, halaman: 1, perHalaman: 25, saring: {} }, bawaan),
        ).toBe('');
    });

    it('nilai tidak sah jatuh ke bawaan', () => {
        const keadaan = BacaKeadaanDariUrl(
            '?halaman=-2&perHalaman=1000&urut=-&saring%5B%3Cx%3E%5D=1',
            BacaUrut('Nama'),
        );

        expect(keadaan.halaman).toBe(1);
        expect(keadaan.perHalaman).toBe(25);
        expect(keadaan.urut).toEqual([{ id: 'Nama', desc: false }]);
        expect(keadaan.saring).toEqual({});
    });

    it('preset & ringkasan rentang tanggal', () => {
        const preset = BuatPresetTanggal(new Date(2026, 8, 24));
        const definisi: DefinisiSaring = { id: 'Tanggal', label: 'Tanggal', jenis: 'rentangTanggal' };

        expect(preset.map((p) => p.nilai)).toEqual([
            '2026-09-24..2026-09-24',
            '2026-09-23..2026-09-23',
            '2026-09-18..2026-09-24',
            '2026-08-26..2026-09-24',
            '2026-09-01..2026-09-30',
            '2026-08-01..2026-08-31',
            '2026-01-01..2026-12-31',
        ]);
        expect(RingkasSaring(definisi, '2026-01-02..2026-01-05')).toBe('2 Jan 2026 – 5 Jan 2026');
        expect(RingkasSaring(saring[0] as DefinisiSaring, 'Aktif,Nonaktif')).toBe('Aktif, Nonaktif');
    });
});

describe('TabelData (D-16, PRD §17.4.3)', () => {
    beforeEach(() => {
        window.history.replaceState({}, '', '/kelola/produk');
        window.localStorage.clear();
        AturLebar('desktop');
    });

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
        window.matchMedia = MatchMediaAsli;
    });

    it('memakai tabel awal dari props tanpa memanggil server; urut lewat kepala kolom memperbarui URL & mengambil data', async () => {
        PasangFetch(() => Hasil(BuatProduk(2, 'Teh Tarik')));
        Render(
            <TabelData
                id="produk"
                label="Daftar produk"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: '/kelola/produk', awal: Hasil(BuatProduk(3)) }}
                ambilIdBaris={(p) => p.Uuid}
                urutBawaan="Nama"
                kosong={{ judul: 'Belum ada produk.' }}
            />,
        );

        expect(screen.getByText('Kopi Susu Gula Aren Ukuran Besar 1')).toBeTruthy();
        expect(panggilanFetch).toEqual([]);
        expect(screen.getByRole('columnheader', { name: /Nama produk/ }).getAttribute('aria-sort')).toBe('ascending');

        fireEvent.click(within(screen.getByRole('columnheader', { name: /Harga/ })).getByRole('button'));

        await waitFor(() => expect(screen.getByText('Teh Tarik 1')).toBeTruthy());
        expect(window.location.search).toBe('?urut=Harga');
        expect(panggilanFetch).toEqual(['/kelola/produk?urut=Harga']);
    });

    it('cari ditahan 300 ms lalu kembali ke halaman 1; filter ya memunculkan chip yang bisa dihapus', async () => {
        window.history.replaceState({}, '', '/kelola/produk?halaman=2');
        PasangFetch(() => Hasil(BuatProduk(1)));
        Render(
            <TabelData
                id="produk"
                label="Daftar produk"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: '/kelola/produk' }}
                ambilIdBaris={(p) => p.Uuid}
                cari="Cari nama atau SKU"
                saring={saring}
                kosong={{ judul: 'Belum ada produk.' }}
            />,
        );

        await waitFor(() => expect(panggilanFetch).toEqual(['/kelola/produk?halaman=2']));
        UbahNilai(screen.getByRole('searchbox', { name: 'Cari di Daftar produk' }), 'aren');
        expect(panggilanFetch).toHaveLength(1);

        await waitFor(() => expect(panggilanFetch.at(-1)).toBe('/kelola/produk?cari=aren'));
        fireEvent.click(screen.getByRole('button', { name: 'Sedang diskon' }));

        await waitFor(() => expect(window.location.search).toBe('?cari=aren&saring%5BDiskon%5D=1'));
        const chip = screen.getByRole('button', { name: 'Hapus filter Sedang diskon' });
        fireEvent.click(chip);
        await waitFor(() => expect(window.location.search).toBe('?cari=aren'));
    });

    it('paginasi server: rentang baris, halaman berikutnya, dan ukuran halaman', async () => {
        PasangFetch((url) =>
            url.includes('halaman=2') ? Hasil(BuatProduk(25, 'Roti'), 2, 60) : Hasil(BuatProduk(50, 'Susu'), 1, 60, 50),
        );
        Render(
            <TabelData
                id="produk"
                label="Daftar produk"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: '/kelola/produk', awal: Hasil(BuatProduk(25), 1, 60) }}
                ambilIdBaris={(p) => p.Uuid}
                kosong={{ judul: 'Belum ada produk.' }}
            />,
        );

        expect(screen.getByText(/Menampilkan/).textContent).toBe('Menampilkan 1–25 dari 60');
        fireEvent.click(screen.getByRole('button', { name: 'Halaman berikutnya' }));
        await waitFor(() => expect(screen.getByText('Roti 1')).toBeTruthy());
        expect(screen.getByText(/Menampilkan/).textContent).toBe('Menampilkan 26–50 dari 60');

        UbahNilai(screen.getByLabelText('Baris per halaman'), '50');
        await waitFor(() => expect(panggilanFetch.at(-1)).toBe('/kelola/produk?perHalaman=50'));
    });

    it('keadaan kosong membedakan "belum ada data" dan "tidak ada hasil filter"; galat menawarkan coba lagi', async () => {
        PasangFetch(() => 500);
        const { unmount: Lepas } = Render(
            <TabelData
                id="produk"
                label="Daftar produk"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: '/kelola/produk', awal: Hasil([]) }}
                ambilIdBaris={(p) => p.Uuid}
                kosong={{ judul: 'Belum ada produk. Tambahkan produk pertama Anda.', ilustrasi: true }}
            />,
        );
        expect(screen.getByText('Belum ada produk. Tambahkan produk pertama Anda.')).toBeTruthy();
        // D-18: daftar yang belum berisi data menampilkan ilustrasi keadaan kosong (satu untuk semua daftar).
        expect(document.querySelector('img[src*="Kosong.webp"]')).not.toBeNull();
        // Desktop: kepala kolom tetap tampil, keadaan kosong jadi satu baris selebar tabel (bukan kotak terpisah).
        expect(screen.getByRole('columnheader', { name: /Nama produk/ })).toBeTruthy();
        expect(screen.getByRole('status').getAttribute('colspan')).toBe(String(kolom.length));
        Lepas();

        window.history.replaceState({}, '', '/kelola/produk?saring%5BStatus%5D=Aktif');
        Render(
            <TabelData
                id="produk-2"
                label="Daftar produk"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: '/kelola/produk', awal: Hasil([]) }}
                ambilIdBaris={(p) => p.Uuid}
                saring={saring}
                kosong={{ judul: 'Belum ada produk.', ilustrasi: true }}
            />,
        );
        expect(screen.getByText('Tidak ada hasil untuk pencarian atau filter ini.')).toBeTruthy();
        // D-18: hasil cari/saring kosong tetap ringkas, tanpa ilustrasi.
        expect(document.querySelector('img[src*="Kosong.webp"]')).toBeNull();

        fireEvent.click(screen.getAllByRole('button', { name: 'Hapus pencarian & filter' })[0] as HTMLElement);
        // Galat 5xx dicoba ulang 2 kali (jeda bawaan TanStack) sebelum ditampilkan.
        await waitFor(() => expect(screen.getByText('Data belum bisa dimuat')).toBeTruthy(), { timeout: 6000 });
        expect(screen.getByRole('button', { name: 'Coba lagi' })).toBeTruthy();
    });

    it('HP (< 640px): baris tampil bertumpuk dengan kolom utama sebagai judul dan kolom penting berlabel', () => {
        AturLebar('hp');
        Render(
            <TabelData
                id="produk"
                label="Daftar produk"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: BuatProduk(2) }}
                ambilIdBaris={(p) => p.Uuid}
                saring={saring}
                kosong={{ judul: 'Belum ada produk.' }}
            />,
        );

        expect(screen.queryByRole('table')).toBeNull();
        const daftar = screen.getByRole('list', { name: 'Daftar produk' });
        const pertama = within(daftar).getAllByRole('listitem')[0] as HTMLElement;
        expect(within(pertama).getByText('Kopi Susu Gula Aren Ukuran Besar 1')).toBeTruthy();
        expect(within(pertama).getByText('Harga')).toBeTruthy();
        expect(within(pertama).queryByText('Minuman')).toBeNull();
        expect(screen.getByRole('button', { name: 'Filter' })).toBeTruthy();
    });

    it('HP: sembunyiBilaKosong melewati label nilai null (baris judul laporan); tanpa filter & urut tidak ada tombol Filter', () => {
        AturLebar('hp');
        type BarisLaporan = { Id: string; Label: string; Nilai: string | null };
        const kolomLaporan: KolomTabel<BarisLaporan>[] = [
            {
                id: 'Label',
                accessorKey: 'Label',
                enableSorting: false,
                meta: { label: 'Keterangan', prioritas: 'utama' },
            },
            {
                id: 'Nilai',
                accessorKey: 'Nilai',
                enableSorting: false,
                meta: { label: 'Periode ini', prioritas: 'penting', angka: true, sembunyiBilaKosong: true },
            },
        ];
        Render(
            <TabelData
                id="laporan"
                label="Laba rugi"
                kolom={kolomLaporan}
                sumber={{
                    mode: 'lokal',
                    data: [
                        { Id: 'k', Label: 'Pendapatan', Nilai: null },
                        { Id: 'a', Label: 'Penjualan', Nilai: '115500.00' },
                    ],
                }}
                ambilIdBaris={(b) => b.Id}
                cari={false}
                kosong={{ judul: 'Kosong.' }}
            />,
        );

        const [judul, akun] = within(screen.getByRole('list', { name: 'Laba rugi' })).getAllByRole('listitem') as [
            HTMLElement,
            HTMLElement,
        ];
        expect(within(judul).queryByText('Periode ini')).toBeNull();
        expect(within(akun).getByText('Periode ini')).toBeTruthy();
        expect(within(akun).getByText('115500.00')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Filter' })).toBeNull();
    });

    it('tablet menyembunyikan kolom prioritas rendah; pilihan kolom pengguna disimpan per tabel', () => {
        AturLebar('tablet');
        const { unmount: Lepas } = Render(
            <TabelData
                id="produk"
                label="Daftar produk"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: BuatProduk(2) }}
                ambilIdBaris={(p) => p.Uuid}
                kosong={{ judul: 'Belum ada produk.' }}
            />,
        );
        expect(screen.queryByRole('columnheader', { name: 'Kategori' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Atur kolom' }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'Kategori' }));
        expect(screen.getByRole('columnheader', { name: 'Kategori' })).toBeTruthy();
        fireEvent.click(screen.getByRole('checkbox', { name: 'Harga' }));
        expect(screen.queryByRole('columnheader', { name: /Harga/ })).toBeNull();
        Lepas();

        Render(
            <TabelData
                id="produk"
                label="Daftar produk"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: BuatProduk(2) }}
                ambilIdBaris={(p) => p.Uuid}
                kosong={{ judul: 'Belum ada produk.' }}
            />,
        );
        expect(screen.getByRole('columnheader', { name: 'Kategori' })).toBeTruthy();
        expect(screen.queryByRole('columnheader', { name: /Harga/ })).toBeNull();
    });

    it('mode lokal: urut, cari, dan filter di peramban tanpa URL maupun server', async () => {
        PasangFetch(() => Hasil([]));
        Render(
            <TabelData
                id="baris"
                label="Baris dokumen"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: BuatProduk(4) }}
                ambilIdBaris={(p) => p.Uuid}
                cari="Cari baris"
                saring={saring}
                kosong={{ judul: 'Belum ada baris.' }}
            />,
        );

        fireEvent.click(within(screen.getByRole('columnheader', { name: /Harga/ })).getByRole('button'));
        fireEvent.click(within(screen.getByRole('columnheader', { name: /Harga/ })).getByRole('button'));
        const AmbilBarisPertama = () => screen.getAllByRole('row')[1] as HTMLElement;
        expect(within(AmbilBarisPertama()).getByText('Kopi Susu Gula Aren Ukuran Besar 4')).toBeTruthy();

        UbahNilai(screen.getByRole('searchbox'), 'Besar 2');
        await waitFor(() => expect(screen.getAllByRole('row')).toHaveLength(2));
        expect(panggilanFetch).toEqual([]);
        expect(window.location.search).toBe('');
        expect(screen.getByText('1 baris')).toBeTruthy();
    });

    it('pilih baris: bilah aksi massal, pilih semua hasil filter, dan aksi baris per baris', async () => {
        const AksiMassal = vi.fn();
        Render(
            <TabelData
                id="produk"
                label="Daftar produk"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: '/kelola/produk', awal: Hasil(BuatProduk(25), 1, 132) }}
                ambilIdBaris={(p) => p.Uuid}
                aksiMassal={(konteks) => {
                    AksiMassal(konteks.terpilih.length, konteks.semuaHasil, konteks.total);

                    return <button type="button">Nonaktifkan terpilih</button>;
                }}
                aksiBaris={(p) => <DropdownMenuItem>Ubah {p.Nama}</DropdownMenuItem>}
                kosong={{ judul: 'Belum ada produk.' }}
            />,
        );

        fireEvent.click(screen.getByRole('checkbox', { name: 'Pilih semua baris di halaman ini' }));
        expect(screen.getByText('25 dipilih')).toBeTruthy();
        // Sel menempel harus ikut warna barisnya: `bg-permukaan` di sel membuat baris terpilih terbelah dua warna.
        const barisTerpilih = document.querySelectorAll('tbody tr[data-state="selected"]');
        expect(barisTerpilih.length).toBe(25);
        barisTerpilih.forEach((baris) =>
            baris
                .querySelectorAll('td')
                .forEach((sel) => expect(sel.className).not.toMatch(/(^|\s)bg-permukaan(\s|$)/)),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Pilih semua 132 hasil' }));
        expect(screen.getByText('Semua 132 hasil dipilih')).toBeTruthy();
        expect(AksiMassal).toHaveBeenLastCalledWith(25, true, 132);

        fireEvent.click(screen.getByRole('button', { name: 'Batal pilih' }));
        expect(screen.queryByRole('region', { name: 'Aksi untuk baris terpilih' })).toBeNull();
        expect(screen.getAllByRole('button', { name: 'Aksi baris' })).toHaveLength(25);
        await act(async () => Promise.resolve());
    });
});
