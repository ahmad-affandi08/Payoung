import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import FormMintaKoreksi from '@/Komponen/Absensi/FormMintaKoreksi';
import { AturHalamanUji, RenderUji, TambahPropsHalamanUji } from '@/Komponen/Katalog/TiruanInertia';
import KartuAbsenSaya from '@/Komponen/Kelola/KartuAbsenSaya';

const KirimJsonTiruan = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);
vi.mock('@/Pustaka/PermintaanJson', () => ({ KirimJson: KirimJsonTiruan }));

/*
 * F-18 (D-180): jalan keluar bila absen terus gagal (permintaan koreksi ke manajer) dan pintasan Absen di Beranda.
 */
beforeEach(() => {
    AturHalamanUji({}, '/kelola/absen');
    KirimJsonTiruan.mockReset();
});

afterEach(() => {
    cleanup();
});

describe('Form minta koreksi absen', () => {
    it('alasan terlalu pendek ditolak tanpa mengirim apa pun', () => {
        const Selesai = vi.fn();
        RenderUji(<FormMintaKoreksi jenis="Masuk" saatSelesai={Selesai} saatBatal={vi.fn()} />);

        fireEvent.change(screen.getByLabelText(/Alasan/), { target: { value: 'ab' } });
        fireEvent.click(screen.getByRole('button', { name: 'Kirim permintaan' }));

        expect(screen.getByText(/Tulis alasannya singkat/)).not.toBeNull();
        expect(KirimJsonTiruan).not.toHaveBeenCalled();
        expect(Selesai).not.toHaveBeenCalled();
    });

    it('mengirim jenis dan alasan ke jalur minta-koreksi lalu menutup formulir', async () => {
        KirimJsonTiruan.mockResolvedValue({});
        const Selesai = vi.fn();
        RenderUji(<FormMintaKoreksi jenis="Keluar" saatSelesai={Selesai} saatBatal={vi.fn()} />);

        fireEvent.change(screen.getByLabelText(/Alasan/), { target: { value: 'Kamera depan rusak' } });
        fireEvent.click(screen.getByRole('button', { name: 'Kirim permintaan' }));

        await waitFor(() => expect(Selesai).toHaveBeenCalled());
        expect(KirimJsonTiruan).toHaveBeenCalledWith('/kelola/absen/minta-koreksi', {
            Jenis: 'Keluar',
            Alasan: 'Kamera depan rusak',
        });
    });

    it('masalah server tampil dan formulir tetap terbuka', async () => {
        KirimJsonTiruan.mockRejectedValue(new Error('Anda sudah mengirim 3 permintaan hari ini.'));
        const Selesai = vi.fn();
        RenderUji(<FormMintaKoreksi jenis="Masuk" saatSelesai={Selesai} saatBatal={vi.fn()} />);

        fireEvent.change(screen.getByLabelText(/Alasan/), { target: { value: 'Wajah tidak dikenali' } });
        fireEvent.click(screen.getByRole('button', { name: 'Kirim permintaan' }));

        await waitFor(() => expect(screen.getByText(/sudah mengirim 3 permintaan/)).not.toBeNull());
        expect(Selesai).not.toHaveBeenCalled();
    });
});

describe('Kartu Absen di Beranda', () => {
    it('muncul hanya bagi karyawan yang wajib absen dan menuju halaman Absen', () => {
        TambahPropsHalamanUji({ AbsenSaya: true });
        RenderUji(<KartuAbsenSaya />);

        expect(screen.getByRole('link', { name: /Buka halaman Absen/ }).getAttribute('href')).toBe('/kelola/absen');
    });

    it('tidak muncul bagi yang tidak ikut absensi', () => {
        TambahPropsHalamanUji({ AbsenSaya: false });
        RenderUji(<KartuAbsenSaya />);

        expect(screen.queryByRole('link', { name: /Buka halaman Absen/ })).toBeNull();
    });
});
