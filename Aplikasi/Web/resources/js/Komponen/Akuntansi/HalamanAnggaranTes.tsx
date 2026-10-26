import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanLaporanAnggaran from '@/Halaman/Kelola/Akuntansi/Anggaran/Laporan';
import HalamanSusunAnggaran from '@/Halaman/Kelola/Akuntansi/Anggaran/Susun';
import { AturHalamanUji, kirimanForm, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { PilihOpsi } from '@/Pengujian/InteraksiPilihan';
import type { AkunAnggaran, PropsLaporanAnggaran, PropsSusunAnggaran } from '@/Tipe/Akuntansi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const outlet = [{ Uuid: '01J9ZC5V7Q8R2T4W6Y8A0B2C4D', Nama: 'Toko Sembako Berkah Jaya' }];

function PropsLaporan(ubah: Partial<PropsLaporanAnggaran> = {}): PropsLaporanAnggaran {
    return {
        Saring: { Tahun: 2026, BulanDari: 9, BulanSampai: 9, Outlet: '' },
        Laporan: {
            Periode: { Tahun: 2026, BulanDari: 9, BulanSampai: 9, Dari: '2026-09-01', Sampai: '2026-09-30' },
            AdaAnggaran: true,
            Baris: [
                {
                    Id: 'Kepala|Beban',
                    Jenis: 'Kepala',
                    Kelompok: 'Beban',
                    Kode: null,
                    Label: 'Beban',
                    Anggaran: null,
                    Realisasi: null,
                    Selisih: null,
                    Capaian: null,
                    Status: null,
                },
                {
                    Id: 'Akun|a1',
                    Jenis: 'Akun',
                    Kelompok: 'Beban',
                    Kode: '6-2000',
                    Label: 'Beban Listrik',
                    Anggaran: '1000000.00',
                    Realisasi: '1250000.00',
                    Selisih: '250000.00',
                    Capaian: '125.00',
                    Status: 'Buruk',
                },
                {
                    Id: 'Akun|a2',
                    Jenis: 'Akun',
                    Kelompok: 'Pendapatan',
                    Kode: '4-1000',
                    Label: 'Penjualan',
                    Anggaran: '5000000.00',
                    Realisasi: '3000000.00',
                    Selisih: '-2000000.00',
                    Capaian: '60.00',
                    Status: 'Buruk',
                },
                {
                    Id: 'Akun|a3',
                    Jenis: 'Akun',
                    Kelompok: 'Beban',
                    Kode: '6-3000',
                    Label: 'Beban Kemasan',
                    Anggaran: '500000.00',
                    Realisasi: '300000.00',
                    Selisih: '-200000.00',
                    Capaian: '60.00',
                    Status: 'Baik',
                },
            ],
            Ringkasan: {
                Pendapatan: { Anggaran: '5000000.00', Realisasi: '3000000.00' },
                Hpp: { Anggaran: '0.00', Realisasi: '0.00' },
                LabaKotor: { Anggaran: '5000000.00', Realisasi: '3000000.00' },
                Beban: { Anggaran: '1500000.00', Realisasi: '1550000.00' },
                LabaBersih: { Anggaran: '3500000.00', Realisasi: '1450000.00' },
            },
        },
        OpsiOutlet: outlet,
        SeluruhUsahaBoleh: true,
        Izin: { Kelola: true },
        ...ubah,
    };
}

function Akun(
    kode: string,
    nama: string,
    jenis: AkunAnggaran['Jenis'],
    perBulan: string[] = Array<string>(12).fill(''),
): AkunAnggaran {
    return { Uuid: `uuid-${kode}`, Kode: kode, Nama: nama, Jenis: jenis, Aktif: true, PerBulan: perBulan };
}

function PropsSusun(ubah: Partial<PropsSusunAnggaran> = {}): PropsSusunAnggaran {
    return {
        Tahun: 2026,
        Outlet: '',
        OpsiOutlet: outlet,
        SeluruhUsahaBoleh: true,
        Akun: [
            Akun('4-1000', 'Penjualan', 'Pendapatan'),
            Akun('6-2000', 'Beban Listrik', 'Beban', ['1000000.00', ...Array<string>(11).fill('')]),
        ],
        ...ubah,
    };
}

beforeEach(() => AturHalamanUji({}, '/kelola/akuntansi/anggaran'));
afterEach(cleanup);

describe('F8 laporan anggaran vs realisasi', () => {
    it('menampilkan anggaran, realisasi, selisih, capaian, dan status berteks sesuai arah kelompok', () => {
        RenderUji(<HalamanLaporanAnggaran {...PropsLaporan()} />);

        const tabel = screen.getByRole('table', { name: 'Anggaran vs realisasi' });
        const listrik = within(tabel).getByText('Beban Listrik').closest('tr') as HTMLElement;
        expect(within(listrik).getByText('Rp 1.000.000')).toBeTruthy();
        expect(within(listrik).getByText('Rp 1.250.000')).toBeTruthy();
        expect(within(listrik).getByText('Rp 250.000')).toBeTruthy();
        expect(within(listrik).getByText('125%')).toBeTruthy();
        expect(within(listrik).getByText('Melebihi anggaran')).toBeTruthy();
        const penjualan = within(tabel).getByText('Penjualan').closest('tr') as HTMLElement;
        expect(within(penjualan).getByText('−Rp 2.000.000')).toBeTruthy();
        expect(within(penjualan).getByText('Di bawah target')).toBeTruthy();
        const kemasan = within(tabel).getByText('Beban Kemasan').closest('tr') as HTMLElement;
        expect(within(kemasan).getByText('Hemat')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Susun anggaran' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/anggaran/susun?tahun=2026',
        );
    });

    it('tanpa anggaran: pengelola diajak menyusun, pembaca saja tidak punya tombol susun', () => {
        const kosong = PropsLaporan();
        kosong.Laporan = { ...kosong.Laporan, AdaAnggaran: false };
        RenderUji(<HalamanLaporanAnggaran {...kosong} />);
        expect(screen.getByRole('status').textContent).toContain('Susun anggarannya dulu');
        cleanup();

        RenderUji(<HalamanLaporanAnggaran {...kosong} Izin={{ Kelola: false }} />);
        expect(screen.getByRole('status').textContent).toContain('Minta pengelola keuangan menyusunnya');
        expect(screen.queryByRole('link', { name: 'Susun anggaran' })).toBeNull();
    });

    it('mengganti filter memuat ulang laporan dengan filter di URL. Ekspor membawa filter', () => {
        RenderUji(<HalamanLaporanAnggaran {...PropsLaporan()} />);

        PilihOpsi(screen.getByRole('combobox', { name: /Sampai bulan/ }), '12');
        expect(tiruanRouter.get).toHaveBeenCalledWith(
            '/kelola/akuntansi/anggaran?tahun=2026&bulanDari=9&bulanSampai=12',
            {},
            { preserveScroll: true, preserveState: true },
        );

        PilihOpsi(screen.getByRole('combobox', { name: /Outlet/ }), outlet[0]?.Uuid ?? '');
        expect(tiruanRouter.get).toHaveBeenLastCalledWith(
            `/kelola/akuntansi/anggaran?tahun=2026&bulanDari=9&bulanSampai=9&outlet=${outlet[0]?.Uuid ?? ''}`,
            {},
            { preserveScroll: true, preserveState: true },
        );
    });
});

describe('F8 susun anggaran', () => {
    it('menjumlah setahun, mengisi semua bulan sekaligus, dan hanya mengirim akun yang berubah', () => {
        RenderUji(<HalamanSusunAnggaran {...PropsSusun()} />);

        const simpan = screen.getByRole('button', { name: 'Simpan anggaran' });
        expect((simpan as HTMLButtonElement).disabled).toBe(true);

        // Listrik: Januari 1.000.000 → setahun Rp 1.000.000; isi semua bulan 500.000 → 6.000.000.
        const barisListrik = screen.getByText('Beban Listrik').closest('tr') as HTMLElement;
        expect(within(barisListrik).getByText('Rp 1.000.000')).toBeTruthy();
        fireEvent.change(screen.getByLabelText('Semua bulan Beban Listrik'), { target: { value: '500000' } });
        expect(within(barisListrik).getByText('Rp 6.000.000')).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Simpan anggaran' }) as HTMLButtonElement).disabled).toBe(false);
        expect(screen.getByRole('status', { name: '' }).textContent ?? '').toContain('1 akun diubah');

        fireEvent.click(screen.getByRole('button', { name: 'Simpan anggaran' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/akuntansi/anggaran',
            {
                Tahun: 2026,
                Outlet: null,
                Baris: [{ UuidAkun: 'uuid-6-2000', PerBulan: Array<string>(12).fill('500000') }],
            },
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(kirimanForm).toEqual([]);
    });

    it('mengubah satu bulan tidak mengisi bulan lain. Mengganti tahun dikunci selama ada perubahan belum disimpan', () => {
        RenderUji(<HalamanSusunAnggaran {...PropsSusun()} />);

        fireEvent.change(screen.getByLabelText('Penjualan Maret'), { target: { value: '2500000' } });
        const barisPenjualan = screen.getByText('Penjualan').closest('tr') as HTMLElement;
        expect(within(barisPenjualan).getAllByText('Rp 2.500.000').length).toBeGreaterThan(0);
        expect(screen.getByRole('status', { name: '' }).textContent ?? '').toContain('belum disimpan');
        expect(screen.getByRole('combobox', { name: /Tahun/ }).hasAttribute('disabled')).toBe(true);
    });

    it('anggaran kosong menawarkan salin dari tahun lalu. Sudah berisi tidak', () => {
        RenderUji(<HalamanSusunAnggaran {...PropsSusun({ Akun: [Akun('6-2000', 'Beban Listrik', 'Beban')] })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Salin dari 2025' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/akuntansi/anggaran/salin?tahun=2026',
            {},
            { preserveScroll: true },
        );
        cleanup();

        RenderUji(<HalamanSusunAnggaran {...PropsSusun()} />);
        expect(screen.queryByRole('button', { name: /Salin dari/ })).toBeNull();
    });

    it('pelaku berbatas outlet tidak melihat pilihan seluruh usaha dan mengirim outlet terpilih', () => {
        RenderUji(
            <HalamanSusunAnggaran {...PropsSusun({ SeluruhUsahaBoleh: false, Outlet: outlet[0]?.Uuid ?? '' })} />,
        );

        fireEvent.change(screen.getByLabelText('Penjualan Januari'), { target: { value: '100' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan anggaran' }));

        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/akuntansi/anggaran',
            expect.objectContaining({ Outlet: outlet[0]?.Uuid ?? '' }),
            expect.anything(),
        );
    });
});
