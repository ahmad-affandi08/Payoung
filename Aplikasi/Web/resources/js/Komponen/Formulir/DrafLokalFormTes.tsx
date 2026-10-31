import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanFormTransferStok from '@/Halaman/Kelola/Persediaan/Transfer/Form';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { GudangKedua, GudangUtama } from '@/Komponen/Persediaan/DataUjiPersediaan';
import { BuatKunciDraf } from '@/Pustaka/DrafLokal';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * Draf otomatis form dokumen panjang (Transfer stok sebagai contoh): isian tersimpan di peramban, ditawarkan lagi saat form
 * dibuka kosong, bisa dilanjutkan atau dibuang, dan terhapus begitu dokumen berhasil disimpan.
 */
const Sasa = {
    Uuid: '01J9PRD0000000000000000001',
    Nama: 'Micin Sasa 250 gr',
    Sku: 'PRD-000297',
    Jenis: 'Stok',
    Pelacakan: 'Tidak',
    SimbolSatuan: 'pcs',
    BolehDesimal: false,
    SaldoDiGudang: '20.0000',
    HppRataRata: '1000.000000',
    StokAwalSudahAda: false,
};

function PasangForm() {
    AturHalamanUji({}, '/kelola/persediaan/transfer/buat');

    return RenderUji(
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

async function IsiSatuBarang() {
    await waitFor(() => expect(document.querySelector('[data-slot="popover-content"]')).toBeNull());
    fireEvent.click(screen.getByRole('combobox', { name: 'Tambah produk' }));
    const cari = screen.getByPlaceholderText('Cari nama, SKU, atau barcode');
    fireEvent.change(cari, { target: { value: 'sasa' } });
    await waitFor(() => expect(screen.getByRole('option', { name: /Micin Sasa/ })).toBeTruthy());
    fireEvent.keyDown(cari, { key: 'Enter' });
    const jumlah = await screen.findByRole('textbox', { name: 'Jumlah Micin Sasa 250 gr' });
    await waitFor(() => expect(document.activeElement).toBe(jumlah));
    fireEvent.change(jumlah, { target: { value: '12' } });
}

describe('Draf otomatis form dokumen (Transfer stok)', () => {
    beforeEach(() => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({ ok: true, status: 200, json: () => Promise.resolve({ Data: [Sasa] }) }),
        );
    });

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('isian tersimpan otomatis, lalu ditawarkan lagi saat form dibuka kosong dan bisa dilanjutkan', async () => {
        const pertama = PasangForm();

        await IsiSatuBarang();
        // Menutup form (tab ditutup, pindah halaman) menyimpan sisa ketikan terakhir.
        pertama.unmount();

        PasangForm();

        expect(await screen.findByText('Ada isian yang belum selesai')).toBeTruthy();
        // Form masih kosong sampai pengguna memilih.
        expect(screen.queryByRole('textbox', { name: 'Jumlah Micin Sasa 250 gr' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Lanjutkan isian' }));

        const jumlah = await screen.findByRole<HTMLInputElement>('textbox', { name: 'Jumlah Micin Sasa 250 gr' });
        expect(jumlah.value).toBe('12');
        expect(screen.queryByText('Ada isian yang belum selesai')).toBeNull();
    });

    it('Buang menghapus draf dan tawaran tidak muncul lagi', async () => {
        const pertama = PasangForm();

        await IsiSatuBarang();
        pertama.unmount();
        PasangForm();

        fireEvent.click(await screen.findByRole('button', { name: 'Buang' }));

        expect(screen.queryByText('Ada isian yang belum selesai')).toBeNull();
        expect(Object.keys(window.localStorage).filter((k) => k.startsWith('payoung:draf:'))).toEqual([]);
    });

    it('dokumen yang berhasil disimpan menghapus draf, dan menutup form sesudahnya tidak menulisnya lagi', async () => {
        const form = PasangForm();

        await IsiSatuBarang();
        fireEvent.click(screen.getByRole('button', { name: 'Simpan draf' }));

        await waitFor(() => expect(tiruanRouter.post).toHaveBeenCalled());
        const OpsiKirim = (tiruanRouter.post.mock.calls[0] as [string, unknown, { onSuccess?: () => void }])[2];
        expect(OpsiKirim.onSuccess).toBeTypeOf('function');
        OpsiKirim.onSuccess?.();
        form.unmount();

        expect(Object.keys(window.localStorage).filter((k) => k.startsWith('payoung:draf:'))).toEqual([]);
    });

    it('form yang masih kosong tidak menyimpan apa pun', async () => {
        const form = PasangForm();

        form.unmount();

        expect(window.localStorage.getItem(BuatKunciDraf('-', '-', 'transfer-stok'))).toBeNull();
    });
});
