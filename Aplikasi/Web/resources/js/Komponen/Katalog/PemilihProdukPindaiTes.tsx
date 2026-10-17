import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { SiapkanKameraTiruan } from '@/Pengujian/KameraTiruan';

import PemilihProduk from './PemilihProduk';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const kamera = vi.hoisted(() => ({
    mulai: vi.fn(),
    daftar: vi.fn(),
}));

vi.mock('@/Pustaka/PemindaiKamera', async (impor) => ({
    ...(await impor<typeof import('@/Pustaka/PemindaiKamera')>()),
    MulaiPemindaian: kamera.mulai,
    DaftarKamera: kamera.daftar,
}));

const Produk = (uuid: string, nama: string) => ({
    Uuid: uuid,
    Nama: nama,
    Sku: null,
    Jenis: 'Stok',
    UuidSatuanDasar: 'SAT-PCS',
    Satuan: [],
});

/** Pencarian server tiruan: barcode `8991234500011` hanya milik satu produk; kata `kopi` cocok dengan dua. */
function PasangPencarian() {
    const Ambil = vi.fn((url: string) => {
        const kata = new URL(url, 'http://localhost').searchParams.get('kata') ?? '';
        const data =
            kata === '8991234500011'
                ? [Produk('P-SUSU', 'Susu UHT 1 L')]
                : kata === 'kopi'
                  ? [Produk('P-KOPI-1', 'Kopi Arabika'), Produk('P-KOPI-2', 'Kopi Robusta')]
                  : [];

        return Promise.resolve({ ok: true, json: () => Promise.resolve({ Data: data }) });
    });

    vi.stubGlobal('fetch', Ambil);

    return Ambil;
}

async function PindaiKode(tiruan: ReturnType<typeof SiapkanKameraTiruan>, kode: string) {
    fireEvent.click(screen.getByRole('button', { name: 'Pindai barcode Cari bahan' }));
    await waitFor(() => expect(tiruan.opsiTerakhir()).not.toBeNull());
    await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());
    tiruan.terbaca(kode);
}

describe('PemilihProduk + pindai kamera', () => {
    beforeEach(() => {
        kamera.mulai.mockReset();
        kamera.daftar.mockReset();
    });
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('barcode yang hanya cocok dengan satu produk langsung memilih produk itu, sama dengan pemindai USB + Enter', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);
        PasangPencarian();
        const SaatPilih = vi.fn();

        RenderUji(<PemilihProduk label="Cari bahan" jenis={['Stok']} saatPilih={SaatPilih} />);
        await PindaiKode(tiruan, '8991234500011');

        await waitFor(() => expect(SaatPilih).toHaveBeenCalledWith(expect.objectContaining({ Uuid: 'P-SUSU' })), {
            timeout: 3000,
        });
        expect(SaatPilih).toHaveBeenCalledTimes(1);
    });

    it('hasil lebih dari satu: daftar terbuka dengan kata terisi dan pengguna memilih sendiri', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);
        PasangPencarian();
        const SaatPilih = vi.fn();

        RenderUji(<PemilihProduk label="Cari bahan" jenis={['Stok']} saatPilih={SaatPilih} />);
        await PindaiKode(tiruan, 'kopi');

        expect(await screen.findByRole('option', { name: /Kopi Robusta/ }, { timeout: 3000 })).toBeTruthy();
        expect((screen.getByPlaceholderText('Cari nama, SKU, atau barcode') as HTMLInputElement).value).toBe('kopi');
        expect(SaatPilih).not.toHaveBeenCalled();
    });

    it('barcode tak dikenal: tidak memilih apa pun dan daftar menjelaskan tidak ada yang cocok', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);
        PasangPencarian();
        const SaatPilih = vi.fn();

        RenderUji(<PemilihProduk label="Cari bahan" jenis={['Stok']} saatPilih={SaatPilih} />);
        await PindaiKode(tiruan, '0000000000000');

        expect(
            await screen.findByText(/Tidak ada produk yang cocok dengan "0000000000000"/, {}, { timeout: 3000 }),
        ).toBeTruthy();
        expect(SaatPilih).not.toHaveBeenCalled();
    });

    it('pemilih nonaktif: tombol kamera ikut nonaktif', () => {
        SiapkanKameraTiruan(kamera);
        PasangPencarian();

        RenderUji(<PemilihProduk label="Cari bahan" jenis={['Stok']} saatPilih={vi.fn()} disabled />);

        expect((screen.getByRole('button', { name: 'Pindai barcode Cari bahan' }) as HTMLButtonElement).disabled).toBe(
            true,
        );
    });
});
