import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

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

type OpsiMulai = { idPerangkat?: string | null; saatTerbaca: (nilai: string) => void };

/** Kamera tiruan: `terbaca(...)` meniru barcode yang masuk ke pemindai terakhir yang dibuka. */
function SiapkanKameraTiruan() {
    const Berhenti = vi.fn();
    let opsi: OpsiMulai | null = null;

    kamera.mulai.mockImplementation((o: OpsiMulai) => {
        opsi = o;

        return Promise.resolve({ Berhenti, IdPerangkat: o.idPerangkat ?? 'KAM-1' });
    });
    kamera.daftar.mockResolvedValue([
        { deviceId: 'KAM-1', kind: 'videoinput', label: 'Belakang' },
        { deviceId: 'KAM-2', kind: 'videoinput', label: 'Depan' },
    ]);

    return { Berhenti, terbaca: (nilai: string) => opsi?.saatTerbaca(nilai), opsiTerakhir: () => opsi };
}

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
        const kamera = SiapkanKameraTiruan();
        const SaatTerbaca = vi.fn();

        render(<TombolPindaiKamera saatTerbaca={SaatTerbaca} />);
        fireEvent.click(screen.getByRole('button', { name: 'Pindai kamera' }));

        expect(screen.getByRole('dialog', { name: 'Pindai barcode dengan kamera' })).toBeTruthy();
        await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());

        kamera.terbaca('8991234567890');
        kamera.terbaca('8991234567890');

        expect(SaatTerbaca).toHaveBeenCalledTimes(1);
        expect(SaatTerbaca).toHaveBeenCalledWith('8991234567890');
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(kamera.Berhenti).toHaveBeenCalled();
    });

    it('menutup dialog mematikan kamera dan tidak memanggil hasil', async () => {
        const kamera = SiapkanKameraTiruan();
        const SaatTerbaca = vi.fn();

        render(<TombolPindaiKamera saatTerbaca={SaatTerbaca} />);
        fireEvent.click(screen.getByRole('button', { name: 'Pindai kamera' }));
        await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());
        fireEvent.click(screen.getByRole('button', { name: 'Batal pindai' }));

        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(kamera.Berhenti).toHaveBeenCalled();
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
        const kamera = SiapkanKameraTiruan();

        render(<TombolPindaiKamera saatTerbaca={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Pindai kamera' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Ganti kamera' }));

        await waitFor(() => expect(kamera.opsiTerakhir()?.idPerangkat).toBe('KAM-2'));
    });

    it('tombol nonaktif tidak membuka kamera', () => {
        SiapkanKameraTiruan();
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
        const kamera = SiapkanKameraTiruan();

        render(<Pembungkus awal={['8991234567890']} lain={['2000000000015']} />);
        fireEvent.click(screen.getByRole('button', { name: 'Pindai kamera pcs' }));
        await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());
        kamera.terbaca('8990000000011');

        await waitFor(() => expect(screen.getByText('8990000000011')).toBeTruthy());
        expect(screen.getByLabelText('Barcode pcs tersimpan').querySelectorAll('li')).toHaveLength(2);

        // Duplikat dari kamera ditolak dengan pesan yang sama dengan ketikan.
        fireEvent.click(screen.getByRole('button', { name: 'Pindai kamera pcs' }));
        await waitFor(() => expect(kamera.opsiTerakhir()).not.toBeNull());
        await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());
        kamera.terbaca('2000000000015');

        expect(await screen.findByText('Barcode 2000000000015 sudah dipakai di produk ini.')).toBeTruthy();
        expect(screen.getByLabelText('Barcode pcs tersimpan').querySelectorAll('li')).toHaveLength(2);
    });
});
