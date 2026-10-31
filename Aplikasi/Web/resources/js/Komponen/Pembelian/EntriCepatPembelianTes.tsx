import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { BuatBarisDariProduk } from '@/Komponen/Pembelian/AturanPembelian';
import IsianBarisPembelian from '@/Komponen/Pembelian/IsianBarisPembelian';
import type { BarisIsianBebas, ProdukPembelian } from '@/Tipe/Pembelian';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * Entri cepat barang di form pembelian (belanja stok, penerimaan, PO, permintaan pembelian): barang yang dipilih muncul
 * paling atas di bawah kotak cari, jumlahnya langsung aktif, dan Enter kembali ke kotak cari. Pola yang sama dengan
 * Transfer stok (`EntriCepatTes`), lewat `useEntriCepat`.
 */
// Hasil pencarian pembelian juga membawa stok di lokasi (kolom pemilih produk berstok).
const BuatProduk = (uuid: string, nama: string, sku: string): ProdukPembelian & { SaldoDiGudang: string } => ({
    Uuid: uuid,
    Nama: nama,
    Sku: sku,
    Pelacakan: 'Tidak',
    SimbolSatuan: 'pcs',
    BolehDesimal: false,
    Satuan: [],
    HargaBeliTerakhir: null,
    SaldoDiGudang: '20.0000',
});
const Sasa = BuatProduk('01J9PRD0000000000000000001', 'Micin Sasa 250 gr', 'PRD-000297');
const Ajino = BuatProduk('01J9PRD0000000000000000002', 'Ajino Moto 250 gr', 'PRD-000302');

function Pembungkus() {
    const [baris, AturBaris] = useState<BarisIsianBebas[]>([]);

    return (
        <IsianBarisPembelian
            judul="Barang belanja"
            baris={baris}
            saatBerubah={AturBaris}
            uuidGudang="01J9GDG0000000000000000001"
            pelacakan={false}
            periksa={false}
            galatServer={{}}
            maksimal={500}
        />
    );
}

async function PilihProduk(kata: string, nama: RegExp) {
    await waitFor(() => expect(document.querySelector('[data-slot="popover-content"]')).toBeNull());
    fireEvent.click(screen.getByRole('combobox', { name: 'Tambah produk' }));
    const cari = screen.getByPlaceholderText('Cari nama, SKU, atau barcode');
    fireEvent.change(cari, { target: { value: kata } });
    await waitFor(() => expect(screen.getByRole('option', { name: nama })).toBeTruthy());
    fireEvent.keyDown(cari, { key: 'Enter' });
}

describe('Entri cepat barang (form pembelian)', () => {
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

    it('barang yang dipilih langsung punya isian jumlah yang aktif', async () => {
        RenderUji(<Pembungkus />);

        await PilihProduk('sasa', /Micin Sasa/);

        const jumlah = await screen.findByRole('textbox', { name: 'Jumlah Micin Sasa 250 gr' });
        await waitFor(() => expect(document.activeElement).toBe(jumlah));
    });

    it('barang berikutnya muncul paling atas dan Enter di jumlah membuka kotak cari lagi', async () => {
        RenderUji(<Pembungkus />);

        await PilihProduk('sasa', /Micin Sasa/);
        const jumlahPertama = await screen.findByRole('textbox', { name: 'Jumlah Micin Sasa 250 gr' });
        await waitFor(() => expect(document.activeElement).toBe(jumlahPertama));
        fireEvent.change(jumlahPertama, { target: { value: '3' } });
        await PilihProduk('ajino', /Ajino Moto/);

        const barisTabel = screen.getAllByRole('row').slice(1);
        expect(within(barisTabel.at(0) as HTMLElement).getByText('Ajino Moto 250 gr')).toBeTruthy();
        expect(within(barisTabel.at(1) as HTMLElement).getByText('Micin Sasa 250 gr')).toBeTruthy();

        const jumlahKedua = await screen.findByRole('textbox', { name: 'Jumlah Ajino Moto 250 gr' });
        await waitFor(() => expect(document.activeElement).toBe(jumlahKedua));
        fireEvent.keyDown(jumlahKedua, { key: 'Enter' });

        const kotakCari = await screen.findByPlaceholderText('Cari nama, SKU, atau barcode');
        await waitFor(() => expect(document.activeElement).toBe(kotakCari));
    });

    it('baris dari pabrik tetap lengkap: kunci unik dan jumlah kosong', () => {
        const a = BuatBarisDariProduk(Sasa);
        const b = BuatBarisDariProduk(Ajino);

        expect(a.Kunci).not.toBe(b.Kunci);
        expect(a.Jumlah).toBe('');
    });
});
