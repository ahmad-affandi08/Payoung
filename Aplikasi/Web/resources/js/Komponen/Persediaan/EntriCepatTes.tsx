import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanFormTransferStok from '@/Halaman/Kelola/Persediaan/Transfer/Form';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { GudangKedua, GudangUtama } from '@/Komponen/Persediaan/DataUjiPersediaan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * Entri cepat barang di Transfer stok (permintaan klien: kotak cari di atas dan isian jumlah di bawah membuat
 * pengguna naik-turun halaman bila barangnya banyak). Barang yang baru dipilih muncul paling atas, jumlahnya langsung
 * aktif, dan Enter mengembalikan fokus ke kotak cari. Form Belanja stok/PO memakai pola yang sama lewat `useEntriCepat`.
 */
const BuatProduk = (uuid: string, nama: string, sku: string) => ({
    Uuid: uuid,
    Nama: nama,
    Sku: sku,
    Jenis: 'Stok',
    Pelacakan: 'Tidak',
    SimbolSatuan: 'pcs',
    BolehDesimal: false,
    SaldoDiGudang: '20.0000',
    HppRataRata: '1000.000000',
    StokAwalSudahAda: false,
});
const Sasa = BuatProduk('01J9PRD0000000000000000001', 'Micin Sasa 250 gr', 'PRD-000297');
const Ajino = BuatProduk('01J9PRD0000000000000000002', 'Ajino Moto 250 gr', 'PRD-000302');

function PasangForm() {
    AturHalamanUji({}, '/kelola/persediaan/transfer/buat');
    RenderUji(
        <HalamanFormTransferStok
            Mode="Buat"
            Transfer={null}
            OpsiGudangAsal={[GudangUtama, GudangKedua]}
            OpsiGudangTujuan={[GudangUtama, GudangKedua]}
            Awal={{ UuidGudangAsal: GudangUtama.Uuid, UuidGudangTujuan: GudangKedua.Uuid }}
            HariIni="2026-09-24"
            BatasBaris={500}
        />,
    );
}

async function PilihProduk(kata: string, nama: RegExp) {
    // Daftar dari pilihan sebelumnya harus sudah benar-benar tertutup sebelum yang berikutnya dibuka.
    await waitFor(() => expect(document.querySelector('[data-slot="popover-content"]')).toBeNull());
    fireEvent.click(screen.getByRole('combobox', { name: 'Tambah produk' }));
    const cari = screen.getByPlaceholderText('Cari nama, SKU, atau barcode');
    fireEvent.change(cari, { target: { value: kata } });
    await waitFor(() => expect(screen.getByRole('option', { name: nama })).toBeTruthy());
    fireEvent.keyDown(cari, { key: 'Enter' });
}

describe('Entri cepat barang (Transfer stok)', () => {
    beforeEach(() => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockImplementation((url: string) =>
                Promise.resolve({
                    ok: true,
                    status: 200,
                    json: () => Promise.resolve({ Data: url.includes('ajino') ? [Ajino] : [Sasa] }),
                }),
            ),
        );
    });

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('barang yang dipilih langsung punya isian jumlah yang aktif, tanpa mengetuk lagi', async () => {
        PasangForm();

        await PilihProduk('sasa', /Micin Sasa/);

        const jumlah = await screen.findByRole('textbox', { name: 'Jumlah Micin Sasa 250 gr' });
        await waitFor(() => expect(document.activeElement).toBe(jumlah));
    });

    it('barang berikutnya muncul paling atas, tepat di bawah kotak cari, bukan di dasar daftar', async () => {
        PasangForm();

        await PilihProduk('sasa', /Micin Sasa/);
        // Seperti pengguna sungguhan: jumlah barang pertama diketik dulu, baru barang berikutnya dicari.
        const jumlahPertama = await screen.findByRole('textbox', { name: 'Jumlah Micin Sasa 250 gr' });
        await waitFor(() => expect(document.activeElement).toBe(jumlahPertama));
        fireEvent.change(jumlahPertama, { target: { value: '5' } });
        await PilihProduk('ajino', /Ajino Moto/);

        const baris = within(screen.getByRole('list', { name: 'Barang yang dikirim' })).getAllByRole('listitem');
        expect(baris).toHaveLength(2);
        expect(within(baris.at(0) as HTMLElement).getByText('Ajino Moto 250 gr')).toBeTruthy();
        expect(within(baris.at(1) as HTMLElement).getByText('Micin Sasa 250 gr')).toBeTruthy();
    });

    it('Enter di jumlah membuka kotak cari lagi dengan fokus di sana, dan tidak mengirim formulir', async () => {
        PasangForm();
        await PilihProduk('sasa', /Micin Sasa/);

        const jumlah = await screen.findByRole('textbox', { name: 'Jumlah Micin Sasa 250 gr' });
        await waitFor(() => expect(document.activeElement).toBe(jumlah));
        fireEvent.change(jumlah, { target: { value: '12' } });
        fireEvent.keyDown(jumlah, { key: 'Enter' });

        // Daftar produk terbuka lagi dan kotak carinya aktif: barang berikutnya bisa langsung diketik atau dipindai.
        const kotakCari = await screen.findByPlaceholderText('Cari nama, SKU, atau barcode');
        await waitFor(() => expect(document.activeElement).toBe(kotakCari));
        expect((jumlah as HTMLInputElement).value).toBe('12');
        expect(tiruanRouter.post).not.toHaveBeenCalled();
    });

    it('menutup daftar dengan Esc tanpa memilih barang mengembalikan fokus ke kotak cari seperti biasa', async () => {
        PasangForm();

        const cari = screen.getByRole('combobox', { name: 'Tambah produk' });
        fireEvent.click(cari);
        fireEvent.keyDown(screen.getByPlaceholderText('Cari nama, SKU, atau barcode'), { key: 'Escape' });

        await waitFor(() => expect(document.querySelector('[data-slot="popover-content"]')).toBeNull());
        await waitFor(() => expect(document.activeElement).toBe(cari));
    });
});
