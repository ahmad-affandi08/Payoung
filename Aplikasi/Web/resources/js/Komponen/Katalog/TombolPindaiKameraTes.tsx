import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { SiapkanKameraTiruan } from '@/Pengujian/KameraTiruan';

import BidangBarcode from './BidangBarcode';
import TombolPindaiKamera from './TombolPindaiKamera';

const kamera = vi.hoisted(() => ({
    mulai: vi.fn(),
    daftar: vi.fn(),
}));

vi.mock('@/Pustaka/PemindaiKamera', async (impor) => ({
    ...(await impor<typeof import('@/Pustaka/PemindaiKamera')>()),
    MulaiPemindaian: kamera.mulai,
    DaftarKamera: kamera.daftar,
}));

function Pembungkus({ awal = [] as string[], lain = [] as string[] }) {
    const [nilai, AturNilai] = useState(awal);

    return <BidangBarcode label="Barcode pcs" nilai={nilai} saatBerubah={AturNilai} barcodeLain={lain} />;
}

describe('Komponen/Katalog/TombolPindaiKamera (F-03 BR-03.1)', () => {
    beforeEach(() => {
        kamera.mulai.mockReset();
        kamera.daftar.mockReset();
    });
    afterEach(() => cleanup());

    it('buka dialog, nyalakan kamera, hasil baca diteruskan sekali lalu dialog & kamera tertutup', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);
        const SaatTerbaca = vi.fn();

        render(<TombolPindaiKamera saatTerbaca={SaatTerbaca} />);
        fireEvent.click(screen.getByRole('button', { name: 'Pindai kamera' }));

        expect(screen.getByRole('dialog', { name: 'Pindai barcode dengan kamera' })).toBeTruthy();
        await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());

        tiruan.terbaca('8991234567890');
        tiruan.terbaca('8991234567890');

        expect(SaatTerbaca).toHaveBeenCalledTimes(1);
        expect(SaatTerbaca).toHaveBeenCalledWith('8991234567890');
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(tiruan.Berhenti).toHaveBeenCalled();
    });

    it('menutup dialog mematikan kamera dan tidak memanggil hasil', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);
        const SaatTerbaca = vi.fn();

        render(<TombolPindaiKamera saatTerbaca={SaatTerbaca} />);
        fireEvent.click(screen.getByRole('button', { name: 'Pindai kamera' }));
        await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());
        fireEvent.click(screen.getByRole('button', { name: 'Batal pindai' }));

        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(tiruan.Berhenti).toHaveBeenCalled();
        expect(SaatTerbaca).not.toHaveBeenCalled();
    });

    it('izin kamera ditolak: pesan jelas tampil di dalam dialog, tanpa pembidik', async () => {
        kamera.mulai.mockRejectedValue(new DOMException('ditolak', 'NotAllowedError'));

        render(<TombolPindaiKamera saatTerbaca={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Pindai kamera' }));

        expect(await screen.findByText(/Izin kamera ditolak/)).toBeTruthy();
        expect(screen.queryByText('Membuka kamera…')).toBeNull();
    });

    it('Ganti kamera hanya muncul bila ada lebih dari satu kamera, lalu memulai ulang dengan kamera berikutnya', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);

        render(<TombolPindaiKamera saatTerbaca={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Pindai kamera' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Ganti kamera' }));

        await waitFor(() => expect(tiruan.opsiTerakhir()?.idPerangkat).toBe('KAM-2'));
    });

    it('tombol nonaktif tidak membuka kamera', () => {
        SiapkanKameraTiruan(kamera);
        render(<TombolPindaiKamera saatTerbaca={vi.fn()} disabled />);

        expect((screen.getByRole('button', { name: 'Pindai kamera' }) as HTMLButtonElement).disabled).toBe(true);
        expect(kamera.mulai).not.toHaveBeenCalled();
    });
});

describe('BidangBarcode + kamera', () => {
    beforeEach(() => {
        kamera.mulai.mockReset();
        kamera.daftar.mockReset();
    });
    afterEach(() => cleanup());

    it('barcode dari kamera ditambahkan seperti hasil ketikan/pemindai USB, tanpa duplikat', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);

        render(<Pembungkus awal={['8991234567890']} lain={['2000000000015']} />);
        fireEvent.click(screen.getByRole('button', { name: 'Pindai kamera pcs' }));
        await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());
        tiruan.terbaca('8990000000011');

        await waitFor(() => expect(screen.getByText('8990000000011')).toBeTruthy());
        expect(screen.getByLabelText('Barcode pcs tersimpan').querySelectorAll('li')).toHaveLength(2);

        // Duplikat dari kamera ditolak dengan pesan yang sama dengan ketikan.
        fireEvent.click(screen.getByRole('button', { name: 'Pindai kamera pcs' }));
        await waitFor(() => expect(tiruan.opsiTerakhir()).not.toBeNull());
        await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());
        tiruan.terbaca('2000000000015');

        expect(await screen.findByText('Barcode 2000000000015 sudah dipakai di produk ini.')).toBeTruthy();
        expect(screen.getByLabelText('Barcode pcs tersimpan').querySelectorAll('li')).toHaveLength(2);
    });
});

describe('TombolPindaiKamera: mode ikon dan berkelanjutan', () => {
    beforeEach(() => {
        kamera.mulai.mockReset();
        kamera.daftar.mockReset();
    });
    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
    });

    it('ikonSaja: tombol ikon dengan nama aksesibel dari label, tetap membuka dialog kamera', async () => {
        SiapkanKameraTiruan(kamera);
        render(<TombolPindaiKamera ikonSaja label="Pindai barcode Bahan" saatTerbaca={vi.fn()} />);

        const tombol = screen.getByRole('button', { name: 'Pindai barcode Bahan' });

        expect(tombol.textContent).toBe('');
        fireEvent.click(tombol);
        expect(await screen.findByRole('dialog', { name: 'Pindai barcode dengan kamera' })).toBeTruthy();
    });

    it('berkelanjutan: dialog tetap terbuka, barang berbeda dihitung satu per satu, barcode yang sama di depan kamera sekali', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);
        const SaatTerbaca = vi.fn();
        let sekarang = 1_000_000;

        vi.spyOn(Date, 'now').mockImplementation(() => sekarang);
        render(<TombolPindaiKamera berkelanjutan saatTerbaca={SaatTerbaca} umpanBalik="Susu UHT: +1" />);
        fireEvent.click(screen.getByRole('button', { name: 'Pindai kamera' }));
        await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());

        tiruan.terbaca('8991234500011');
        sekarang += 150;
        tiruan.terbaca('8991234500011');
        sekarang += 150;
        tiruan.terbaca('8991234500011');
        expect(SaatTerbaca).toHaveBeenCalledTimes(1);

        sekarang += 150;
        tiruan.terbaca('8991234500028');
        expect(SaatTerbaca).toHaveBeenCalledTimes(2);
        expect(SaatTerbaca).toHaveBeenLastCalledWith('8991234500028');
        expect(screen.getByRole('dialog')).toBeTruthy();
        expect(await screen.findByText(/Terbaca 2 kali/)).toBeTruthy();
        expect(screen.getByText('Susu UHT: +1')).toBeTruthy();

        // Menjauh lebih dari 2 detik lalu menunjukkan barang yang sama lagi = unit berikutnya.
        sekarang += 2500;
        tiruan.terbaca('8991234500028');
        expect(SaatTerbaca).toHaveBeenCalledTimes(3);
        expect(tiruan.Berhenti).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Selesai' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(tiruan.Berhenti).toHaveBeenCalled();
    });
});
