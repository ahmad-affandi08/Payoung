import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import PemindaiWajah from '@/Komponen/Absensi/PemindaiWajah';
import { PindaiWajah, type HasilPindai } from '@/Fitur/Absensi/MesinWajah';

vi.mock('@/Fitur/Absensi/MesinWajah', () => ({
    MuatMesinWajah: vi.fn(() => Promise.resolve({})),
    PindaiWajah: vi.fn(),
    AmbilSwafoto: vi.fn(() => 'FOTO-BASE64'),
    AmbilStatusMesin: () => STATUS_SIAP,
    LanggananStatusMesin: () => () => undefined,
}));

const STATUS_SIAP = { Tahap: 'siap', Persen: 100 } as const;

/*
 * D-186: lingkaran kamera hijau + tombol Ambil foto aktif hanya bila wajah memenuhi syarat (mode absen: cocok dengan
 * wajah terdaftar menurut server); merah + tombol mati bila tidak cocok; foto tidak pernah diambil otomatis.
 */
const bingkaiBaik: HasilPindai = { Penilaian: { Lolos: true }, Sidik: [1, 2, 3] };

function SiapkanKamera() {
    const trek = { stop: vi.fn() };
    Object.defineProperty(navigator, 'mediaDevices', {
        configurable: true,
        value: { getUserMedia: vi.fn(() => Promise.resolve({ getTracks: () => [trek] })) },
    });

    return trek;
}

function TombolFoto(): HTMLButtonElement {
    return screen.getByRole('button', { name: /Ambil foto/ }) as HTMLButtonElement;
}

function Lingkaran(): HTMLElement {
    return document.querySelector('[data-keadaan]') as HTMLElement;
}

beforeEach(() => {
    HTMLMediaElement.prototype.play = vi.fn(() => Promise.resolve());
    vi.mocked(PindaiWajah).mockImplementation((_human, _video, denganSidik) =>
        Promise.resolve(denganSidik ? bingkaiBaik : { Penilaian: { Lolos: true }, Sidik: null }),
    );
});

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

const dasar = { alamatModel: '/model-wajah', judul: 'Absen masuk', jumlah: 1 } as const;

describe('PemindaiWajah', () => {
    it('absen, wajah cocok: lingkaran hijau, tombol Ambil foto aktif, dan foto baru diambil saat tombol diketuk', async () => {
        const SaatSelesai = vi.fn();
        const trek = SiapkanKamera();
        render(
            <PemindaiWajah
                {...dasar}
                mode="absen"
                cekKecocokan={() => Promise.resolve(true)}
                saatSelesai={SaatSelesai}
                saatBatal={vi.fn()}
                saatGalat={vi.fn()}
            />,
        );

        await waitFor(() => expect(Lingkaran().dataset['keadaan']).toBe('cocok'), { timeout: 4000 });
        expect(Lingkaran().className).toContain('border-sukses');
        expect(TombolFoto().disabled).toBe(false);
        expect(screen.getByText(/Wajah cocok/)).toBeTruthy();
        // Belum ada foto sebelum tombol diketuk.
        expect(SaatSelesai).not.toHaveBeenCalled();

        fireEvent.click(TombolFoto());

        expect(SaatSelesai).toHaveBeenCalledTimes(1);
        expect(SaatSelesai).toHaveBeenCalledWith([{ Sidik: [1, 2, 3], Swafoto: 'FOTO-BASE64' }]);
        expect(trek.stop).toHaveBeenCalled();
    });

    it('absen, wajah tidak cocok: lingkaran merah, tombol mati, dan klik tidak menghasilkan foto', async () => {
        const SaatSelesai = vi.fn();
        SiapkanKamera();
        render(
            <PemindaiWajah
                {...dasar}
                mode="absen"
                cekKecocokan={() => Promise.resolve(false)}
                saatSelesai={SaatSelesai}
                saatBatal={vi.fn()}
                saatGalat={vi.fn()}
            />,
        );

        await waitFor(() => expect(Lingkaran().dataset['keadaan']).toBe('tidak-cocok'), { timeout: 4000 });
        expect(Lingkaran().className).toContain('border-bahaya');
        expect(screen.getByText(/tidak cocok/)).toBeTruthy();
        expect(TombolFoto().disabled).toBe(true);

        fireEvent.click(TombolFoto());
        expect(SaatSelesai).not.toHaveBeenCalled();
    });

    it('absen, jawaban server belum datang: kuning "Memeriksa" dan tombol tetap mati', async () => {
        SiapkanKamera();
        render(
            <PemindaiWajah
                {...dasar}
                mode="absen"
                cekKecocokan={() => new Promise<boolean>(() => undefined)}
                saatSelesai={vi.fn()}
                saatBatal={vi.fn()}
                saatGalat={vi.fn()}
            />,
        );

        await waitFor(() => expect(Lingkaran().dataset['keadaan']).toBe('memeriksa'), { timeout: 4000 });
        expect(Lingkaran().className).toContain('border-peringatan');
        expect(TombolFoto().disabled).toBe(true);
    });

    it('tidak ada wajah: netral dan tombol mati; wajah terlalu jauh: petunjuk posisi', async () => {
        SiapkanKamera();
        vi.mocked(PindaiWajah).mockResolvedValue({ Penilaian: { Lolos: false, Alasan: 'TidakAdaWajah' }, Sidik: null });
        const hasil = render(
            <PemindaiWajah
                {...dasar}
                mode="absen"
                cekKecocokan={() => Promise.resolve(true)}
                saatSelesai={vi.fn()}
                saatBatal={vi.fn()}
                saatGalat={vi.fn()}
            />,
        );

        await waitFor(() => expect(Lingkaran().dataset['keadaan']).toBe('tidak-ada-wajah'), { timeout: 4000 });
        expect(TombolFoto().disabled).toBe(true);

        vi.mocked(PindaiWajah).mockResolvedValue({ Penilaian: { Lolos: false, Alasan: 'TerlaluJauh' }, Sidik: null });
        await waitFor(() => expect(hasil.getByText(/Dekatkan wajah/)).toBeTruthy(), { timeout: 4000 });
        expect(TombolFoto().disabled).toBe(true);
    });

    it('daftar: hijau begitu wajah terbaca (tanpa cek server); foto dikumpulkan satu per ketukan dengan jeda antar-foto', async () => {
        const SaatSelesai = vi.fn();
        SiapkanKamera();
        render(
            <PemindaiWajah
                alamatModel="/model-wajah"
                mode="daftar"
                judul="Daftarkan wajah"
                jumlah={2}
                saatSelesai={SaatSelesai}
                saatBatal={vi.fn()}
                saatGalat={vi.fn()}
            />,
        );

        await waitFor(() => expect(Lingkaran().dataset['keadaan']).toBe('siap'), { timeout: 4000 });
        expect(screen.getByText('Foto 1 dari 2 | Hadap lurus ke kamera.')).toBeTruthy();

        fireEvent.click(TombolFoto());

        expect(SaatSelesai).not.toHaveBeenCalled();
        expect(screen.getByText(/Foto 1 tersimpan/)).toBeTruthy();
        expect(TombolFoto().disabled).toBe(true);

        await waitFor(() => expect(Lingkaran().dataset['keadaan']).toBe('siap'), { timeout: 5000 });
        await waitFor(() => expect(TombolFoto().disabled).toBe(false), { timeout: 5000 });
        fireEvent.click(TombolFoto());

        expect(SaatSelesai).toHaveBeenCalledWith([
            { Sidik: [1, 2, 3], Swafoto: 'FOTO-BASE64' },
            { Sidik: [1, 2, 3], Swafoto: 'FOTO-BASE64' },
        ]);
    });

    it('kamera ditolak: melapor galat dan tidak menampilkan tombol aktif', async () => {
        Object.defineProperty(navigator, 'mediaDevices', {
            configurable: true,
            value: { getUserMedia: vi.fn(() => Promise.reject(new Error('ditolak'))) },
        });
        const SaatGalat = vi.fn();
        render(
            <PemindaiWajah
                {...dasar}
                mode="absen"
                cekKecocokan={() => Promise.resolve(true)}
                saatSelesai={vi.fn()}
                saatBatal={vi.fn()}
                saatGalat={SaatGalat}
            />,
        );

        await waitFor(() =>
            expect(SaatGalat).toHaveBeenCalledWith(expect.stringContaining('Kamera tidak bisa dibuka')),
        );
        expect(TombolFoto().disabled).toBe(true);
    });

    it('Batal menghentikan kamera dan memanggil saatBatal', async () => {
        const SaatBatal = vi.fn();
        const trek = SiapkanKamera();
        render(
            <PemindaiWajah
                {...dasar}
                mode="absen"
                cekKecocokan={() => Promise.resolve(true)}
                saatSelesai={vi.fn()}
                saatBatal={SaatBatal}
                saatGalat={vi.fn()}
            />,
        );

        await waitFor(() => expect(Lingkaran().dataset['keadaan']).not.toBe('menyiapkan'), { timeout: 4000 });
        fireEvent.click(screen.getByRole('button', { name: /Batal/ }));

        expect(SaatBatal).toHaveBeenCalledTimes(1);
        expect(trek.stop).toHaveBeenCalled();
    });
});
