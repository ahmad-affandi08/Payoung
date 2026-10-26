import { cleanup, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarQrisTanpaPenjualan from '@/Halaman/Kelola/Akuntansi/QrisTanpaPenjualan/Daftar';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel } from '@/Komponen/Persediaan/DataUjiPersediaan';
import type { BarisQrisTanpaPenjualan } from '@/Tipe/Akuntansi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const Menunggu: BarisQrisTanpaPenjualan = {
    Uuid: '01J9QRS0000000000000000001',
    NomorPesanan: 'PY01J9QRS0000000000TERLAMBAT',
    KodeOutlet: 'UTAMA',
    LunasPada: '2026-10-12T03:00:00+07:00',
    Jumlah: '125000.00',
    AlasanTinjauan: 'Uang QRIS diterima setelah tagihan berstatus Kedaluwarsa.',
    Ditutup: false,
    HasilPenutupan: null,
    LabelHasil: null,
    CatatanPenutupan: null,
    DitutupPada: null,
};

const props = {
    OpsiAkun: [{ Uuid: '01J9AKN0000000000000000001', Kode: '1-1200', Nama: 'Bank BCA' }],
    OpsiHasil: [
        { Nilai: 'Dikembalikan' as const, Label: 'Dikembalikan ke pelanggan' },
        { Nilai: 'DiakuiPendapatan' as const, Label: 'Diakui sebagai pendapatan lain' },
    ],
    HariIni: '2026-10-12',
};

describe('Uang QRIS tanpa penjualan (E8)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/akuntansi/qris-tanpa-penjualan');
        window.history.replaceState({}, '', '/kelola/akuntansi/qris-tanpa-penjualan');
        vi.clearAllMocks();
    });
    afterEach(() => cleanup());

    it('menampilkan tagihan yang menunggu keputusan dan yang sudah ditutup dengan hasilnya', () => {
        const ditutup: BarisQrisTanpaPenjualan = {
            ...Menunggu,
            Uuid: '01J9QRS0000000000000000002',
            NomorPesanan: 'PY01J9QRS0000000000SELESAI',
            Ditutup: true,
            HasilPenutupan: 'Dikembalikan',
            LabelHasil: 'Dikembalikan ke pelanggan',
            CatatanPenutupan: 'Refund lewat dasbor DOKU',
        };
        RenderUji(
            <HalamanDaftarQrisTanpaPenjualan
                {...props}
                Tagihan={BuatHasilTabel([Menunggu, ditutup])}
                Izin={{ Kelola: true }}
            />,
        );

        expect(screen.getAllByText('PY01J9QRS0000000000TERLAMBAT').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Menunggu keputusan').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Dikembalikan ke pelanggan').length).toBeGreaterThan(0);
        expect(screen.getAllByText(/Refund lewat dasbor DOKU/).length).toBeGreaterThan(0);
    });

    it('tanpa izin akuntansi.kelola tampil pesan hanya-lihat. Daftar kosong memakai keadaan kosong', () => {
        RenderUji(<HalamanDaftarQrisTanpaPenjualan {...props} Tagihan={BuatHasilTabel([])} Izin={{ Kelola: false }} />);

        expect(screen.getByText('Tidak ada uang QRIS yang masuk tanpa penjualan.')).toBeTruthy();
        expect(screen.getByText(/akses untuk mengelola pembukuan/)).toBeTruthy();
    });
});
