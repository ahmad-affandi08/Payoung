import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import PencarianBarisLabel from '@/Komponen/Katalog/Label/PencarianBarisLabel';
import { RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { SiapkanKameraTiruan } from '@/Pengujian/KameraTiruan';

import PemilihProdukStok from './PemilihProdukStok';

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

const KODE = '8991234500011';

/** Server tiruan: hanya kata `KODE` yang menghasilkan satu barang; kata lain tidak menghasilkan apa pun. */
function PasangServer(buatData: () => unknown[]) {
    vi.stubGlobal(
        'fetch',
        vi.fn((url: string) => {
            const kata = new URL(url, 'http://localhost').searchParams.get('kata') ?? '';

            return Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ Data: kata === KODE ? buatData() : [] }),
            });
        }),
    );
}

async function Pindai(tiruan: ReturnType<typeof SiapkanKameraTiruan>, namaTombol: string, kode: string) {
    fireEvent.click(screen.getByRole('button', { name: namaTombol }));
    await waitFor(() => expect(tiruan.opsiTerakhir()).not.toBeNull());
    await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());
    tiruan.terbaca(kode);
}

describe('Pemilih produk lain + pindai kamera', () => {
    beforeEach(() => {
        kamera.mulai.mockReset();
        kamera.daftar.mockReset();
    });
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('PemilihProdukStok: barcode yang cocok dengan satu produk langsung dipilih', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);
        const SaatPilih = vi.fn();

        PasangServer(() => [
            {
                Uuid: 'P-SUSU',
                Nama: 'Susu UHT 1 L',
                Sku: 'SUSU-1L',
                Jenis: 'Stok',
                Pelacakan: 'Tidak',
                SimbolSatuan: 'pcs',
                BolehDesimal: false,
                SaldoDiGudang: '10.0000',
                HppRataRata: '15000.00',
                StokAwalSudahAda: false,
            },
        ]);
        RenderUji(<PemilihProdukStok label="Tambah barang" uuidGudang={null} saatPilih={SaatPilih} />);
        await Pindai(tiruan, 'Pindai barcode Tambah barang', KODE);

        await waitFor(() => expect(SaatPilih).toHaveBeenCalledWith(expect.objectContaining({ Uuid: 'P-SUSU' })), {
            timeout: 3000,
        });
    });

    it('PemilihProdukStok: produk yang ditolak (stok awal sudah ada) tidak dipilih walau cocok', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);
        const SaatPilih = vi.fn();

        PasangServer(() => [
            {
                Uuid: 'P-SUSU',
                Nama: 'Susu UHT 1 L',
                Sku: null,
                Jenis: 'Stok',
                Pelacakan: 'Tidak',
                SimbolSatuan: 'pcs',
                BolehDesimal: false,
                SaldoDiGudang: null,
                HppRataRata: null,
                StokAwalSudahAda: true,
            },
        ]);
        RenderUji(<PemilihProdukStok label="Tambah barang" uuidGudang={null} saatPilih={SaatPilih} tolakStokAwalAda />);
        await Pindai(tiruan, 'Pindai barcode Tambah barang', KODE);

        expect(await screen.findByRole('option', { name: /Susu UHT 1 L/ }, { timeout: 3000 })).toBeTruthy();
        expect(SaatPilih).not.toHaveBeenCalled();
    });

    it('PencarianBarisLabel: barcode satu satuan langsung masuk ke daftar label', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);
        const SaatPilih = vi.fn();

        PasangServer(() => [
            {
                Uuid: 'PS-1',
                UuidProduk: 'P-SUSU',
                Nama: 'Susu UHT 1 L',
                Sku: null,
                NamaSatuan: 'Pieces',
                SimbolSatuan: 'pcs',
                KonversiKeDasar: '1.0000',
                Barcode: [KODE],
                Harga: '18500.00',
                Jumlah: 1,
            },
        ]);
        RenderUji(<PencarianBarisLabel saatPilih={SaatPilih} />);
        await Pindai(tiruan, 'Pindai barcode Tambah barang', KODE);

        await waitFor(() => expect(SaatPilih).toHaveBeenCalledWith(expect.objectContaining({ Uuid: 'PS-1' })), {
            timeout: 3000,
        });
    });
});
