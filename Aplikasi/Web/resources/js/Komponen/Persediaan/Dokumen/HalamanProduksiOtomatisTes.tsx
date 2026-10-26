import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanFormOrderProduksi from '@/Halaman/Kelola/Persediaan/Produksi/Form';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { GudangUtama } from '@/Komponen/Persediaan/DataUjiPersediaan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

// Pemilih produk diganti satu tombol per produk supaya tes fokus pada perilaku formulir setelah produk dipilih.
vi.mock('@/Komponen/Persediaan/PemilihProdukStok', () => ({
    default: ({ label, saatPilih }: { label: string; saatPilih: (p: unknown) => void }) => (
        <button
            type="button"
            onClick={() =>
                saatPilih({
                    Uuid: '01J9ROTI000000000000000001',
                    Nama: 'Roti Tawar Gandum Utuh',
                    Sku: 'ROT-001',
                    Jenis: 'Produksi',
                    SimbolSatuan: 'pcs',
                    BolehDesimal: false,
                    Pelacakan: 'Tidak',
                })
            }
        >
            {`Pilih: ${label}`}
        </button>
    ),
}));

beforeEach(() => AturHalamanUji({}, '/kelola/persediaan/produksi/buat'));

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('F-05e order produksi: bahan langsung muncul setelah produk dipilih', () => {
    it('memilih produk memuat bahan dari resep tanpa menekan tombol. Jumlah hasil kosong memakai takaran satu satuan', async () => {
        const AmbilUji = vi.fn().mockResolvedValue({
            ok: true,
            json: () =>
                Promise.resolve({
                    VersiResep: 3,
                    Bahan: [
                        {
                            UuidProduk: '01J9BAHAN0000000000000001',
                            NamaProduk: 'Tepung Terigu Protein Tinggi',
                            Sku: null,
                            SimbolSatuan: 'kg',
                            BolehDesimal: true,
                            JumlahStandar: '0.5',
                            Jumlah: '0.5',
                        },
                    ],
                }),
        });
        vi.stubGlobal('fetch', AmbilUji);

        RenderUji(
            <HalamanFormOrderProduksi
                Mode="Buat"
                Order={null}
                Awal={null}
                OpsiGudang={[GudangUtama]}
                HariIni="2026-10-14"
                MaksBahan={100}
                WajibKedaluwarsaBatch
            />,
        );
        expect(screen.queryByRole('button', { name: 'Isi bahan dari resep' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Pilih: Produk yang diproduksi' }));

        await waitFor(() => expect(screen.getAllByText('Tepung Terigu Protein Tinggi').length).toBeGreaterThan(0));
        expect(AmbilUji).toHaveBeenCalledTimes(1);
        expect(String(AmbilUji.mock.calls[0]?.[0])).toBe(
            '/kelola/persediaan/produksi/resep?produk=01J9ROTI000000000000000001&jumlah=1',
        );

        // Jumlah hasil diisi: takaran bahan dihitung ulang sendiri.
        fireEvent.change(screen.getByLabelText(/Jumlah hasil/), { target: { value: '12' } });
        await waitFor(() => expect(AmbilUji).toHaveBeenCalledTimes(2));
        expect(String(AmbilUji.mock.calls[1]?.[0])).toContain('jumlah=12');
    });

    it('resep yang tidak bisa dimuat: pesan tampil dan tombol Isi bahan dari resep tersedia untuk mencoba lagi', async () => {
        const AmbilUji = vi.fn().mockResolvedValue({
            ok: false,
            json: () => Promise.resolve({ Galat: { Pesan: 'Produk ini belum punya resep.' } }),
        });
        vi.stubGlobal('fetch', AmbilUji);

        RenderUji(
            <HalamanFormOrderProduksi
                Mode="Buat"
                Order={null}
                Awal={null}
                OpsiGudang={[GudangUtama]}
                HariIni="2026-10-14"
                MaksBahan={100}
                WajibKedaluwarsaBatch
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Pilih: Produk yang diproduksi' }));

        await waitFor(() => expect(screen.getByText('Produk ini belum punya resep.')).toBeTruthy());
        expect(screen.getByRole('button', { name: 'Isi bahan dari resep' })).toBeTruthy();
    });
});
