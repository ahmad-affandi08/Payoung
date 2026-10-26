import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import BannerPengumuman, { BacaDitutup } from '@/Komponen/Umpan/BannerPengumuman';
import type { PengumumanPlatform } from '@/Tipe/Aplikasi';

const pengumuman: PengumumanPlatform[] = [
    {
        Uuid: 'P1',
        Judul: 'Pemeliharaan server Sabtu malam',
        Isi: 'Back-office tidak bisa dibuka sebentar.',
        Jenis: 'Pemeliharaan',
        LabelJenis: 'Pemeliharaan terjadwal',
        Tautan: null,
        BolehDitutup: false,
        PemeliharaanMulai: '2026-10-10T16:00:00Z',
        PemeliharaanSelesai: '2026-10-10T18:00:00Z',
        TampilSampai: '2026-10-10T18:00:00Z',
    },
    {
        Uuid: 'P2',
        Judul: 'Laporan restock baru',
        Isi: 'Lihat tab Saran restock di laporan stok.',
        Jenis: 'YangBaru',
        LabelJenis: 'Yang baru',
        Tautan: 'https://bantuan.payoung.id/restock',
        BolehDitutup: true,
        PemeliharaanMulai: null,
        PemeliharaanSelesai: null,
        TampilSampai: '2026-10-20T00:00:00Z',
    },
];

describe('P-10 PGL-19 banner pengumuman back-office', () => {
    beforeEach(() => window.localStorage.clear());
    afterEach(() => cleanup());

    it('menampilkan jadwal pemeliharaan. Hanya Yang baru/Info bisa ditutup dan diingat di peramban', () => {
        render(<BannerPengumuman pengumuman={pengumuman} />);

        expect(screen.getByRole('region', { name: 'Pengumuman Payoung' })).toBeTruthy();
        expect(screen.getByText('Pemeliharaan terjadwal: Pemeliharaan server Sabtu malam')).toBeTruthy();
        expect(screen.getByText(/^Jadwal:/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Tutup pengumuman Pemeliharaan server Sabtu malam' })).toBeNull();
        expect(screen.getByRole('link', { name: 'Selengkapnya' }).getAttribute('href')).toBe(
            'https://bantuan.payoung.id/restock',
        );

        fireEvent.click(screen.getByRole('button', { name: 'Tutup pengumuman Laporan restock baru' }));
        expect(screen.queryByText('Yang baru: Laporan restock baru')).toBeNull();
        expect(BacaDitutup()).toEqual(['P2']);
        cleanup();

        render(<BannerPengumuman pengumuman={pengumuman} />);
        expect(screen.queryByText('Yang baru: Laporan restock baru')).toBeNull();
        expect(screen.getByText('Pemeliharaan terjadwal: Pemeliharaan server Sabtu malam')).toBeTruthy();
    });

    it('kosong setelah semua yang boleh ditutup ditutup', () => {
        const { container } = render(<BannerPengumuman pengumuman={pengumuman.slice(1)} />);
        fireEvent.click(screen.getByRole('button', { name: 'Tutup pengumuman Laporan restock baru' }));
        expect(container.textContent).toBe('');
    });
});
