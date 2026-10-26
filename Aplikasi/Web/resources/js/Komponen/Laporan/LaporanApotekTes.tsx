import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { AmbilHrefEkspor } from '@/Pengujian/InteraksiRadix';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanLaporanApotek, { SusunOpsiBulan } from '@/Halaman/Kelola/Laporan/Apotek';
import KalkulatorHja from '@/Komponen/Katalog/KalkulatorHja';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel } from '@/Komponen/Persediaan/DataUjiPersediaan';
import { HitungSaranHja } from '@/Pustaka/HargaApotek';
import type { BarisPenjualanObatResep, BarisSipnap, PropsLaporanApotek } from '@/Tipe/Laporan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const denganResep: BarisPenjualanObatResep = {
    Uuid: '01J9APT0000000000000000001',
    UuidPenjualan: '01J9PNJ0000000000000000001',
    Tanggal: '2026-10-02',
    Nomor: 'INV/SLO1/261002/K01-0007',
    Status: 'Lunas',
    NamaProduk: 'Amoxicillin 500 mg Kapsul (strip isi 10)',
    Golongan: 'Keras',
    LabelGolongan: 'Obat keras',
    ObatWajibApotek: false,
    Jumlah: '10.0000',
    SimbolSatuan: 'strip',
    Batch: 'AMX-2601',
    DenganResep: true,
    NomorResep: 'R/2026/10/0042',
    TanggalResep: '2026-10-01',
    NamaDokter: 'dr. Siti Rahmawati, Sp.A',
    NoSipDokter: '503/SIP/2025/0117',
    NamaPasien: 'B*** S***',
    UmurPasien: '34 tahun',
    AlamatPasien: null,
    PasienTersamar: true,
    NamaApoteker: 'apt. Dewi Lestari, S.Farm',
    NamaKasir: 'Rina',
};

const owaTanpaApoteker: BarisPenjualanObatResep = {
    ...denganResep,
    Uuid: '01J9APT0000000000000000002',
    NamaProduk: 'Asam Mefenamat 500 mg Tablet',
    ObatWajibApotek: true,
    Batch: '',
    DenganResep: false,
    NomorResep: null,
    TanggalResep: null,
    NamaDokter: null,
    NoSipDokter: null,
    NamaPasien: null,
    UmurPasien: null,
    PasienTersamar: false,
    NamaApoteker: null,
};

const sipnap: BarisSipnap = {
    UuidProduk: '01J9PRD0000000000000000001',
    NamaProduk: 'Diazepam 2 mg Tablet',
    Sku: 'OBT-0001',
    Golongan: 'Psikotropika',
    LabelGolongan: 'Psikotropika',
    Prekursor: false,
    SimbolSatuan: 'tab',
    StokAwal: '100.0000',
    PemasukanPemasok: '50.0000',
    PemasukanLain: '3.0000',
    PengeluaranPenjualan: '28.0000',
    PengeluaranLain: '5.0000',
    StokAkhir: '120.0000',
};

function Props(timpa: Partial<PropsLaporanApotek> = {}): PropsLaporanApotek {
    return {
        Tab: 'resep',
        Penjualan: BuatHasilTabel([denganResep, owaTanpaApoteker]),
        OpsiGolongan: [
            { Nilai: 'Keras', Label: 'Obat keras' },
            { Nilai: 'Psikotropika', Label: 'Psikotropika' },
            { Nilai: 'Narkotika', Label: 'Narkotika' },
        ],
        LihatPasien: false,
        Sipnap: { Bulan: '2026-09', Outlet: '', Baris: [sipnap] },
        OpsiOutlet: [{ Nilai: '01J9OTL0000000000000000001', Label: 'Apotek Sehat Sentosa Laweyan' }],
        ...timpa,
    };
}

describe('Apotek (§9.5): saran HJA dari HNA + margin', () => {
    it('HJA = HNA × (1 + margin) dibulatkan HalfUp ke Rp 100, tanpa float', () => {
        expect(HitungSaranHja('11500', '20')).toBe('13800.00');
        expect(HitungSaranHja('11549', '20')).toBe('13900.00');
        expect(HitungSaranHja('1234.56', '12.5')).toBe('1400.00');
        expect(HitungSaranHja('1000', '0')).toBe('1000.00');
        expect(HitungSaranHja('1050', '0')).toBe('1100.00');
        // 2.499.999,99 × 1,3333 = 3.333.249,99 → Rp 3.333.200 (di bawah setengah ratusan).
        expect(HitungSaranHja('2499999.99', '33.33')).toBe('3333200.00');
    });

    it('masukan tidak valid = tanpa saran', () => {
        expect(HitungSaranHja('', '20')).toBeNull();
        expect(HitungSaranHja('0', '20')).toBeNull();
        expect(HitungSaranHja('11500', '')).toBeNull();
        expect(HitungSaranHja('11500', '-5')).toBeNull();
        expect(HitungSaranHja('11500', '1000.01')).toBeNull();
        expect(HitungSaranHja('1e3', '20')).toBeNull();
        expect(HitungSaranHja('11500.123', '20')).toBeNull();
    });

    it('kalkulator menampilkan saran dan mengisikannya sebagai harga', () => {
        const SaatPakai = vi.fn();
        render(<KalkulatorHja simbolSatuan="strip" saatPakai={SaatPakai} />);
        const tombol = screen.getByRole('button', { name: 'Pakai sebagai harga' });
        expect((tombol as HTMLButtonElement).disabled).toBe(true);

        fireEvent.change(screen.getByLabelText('HNA per strip'), { target: { value: '11500' } });
        fireEvent.change(screen.getByLabelText('Margin (%)'), { target: { value: '20' } });
        expect(screen.getByText('Rp 13.800')).toBeTruthy();
        fireEvent.click(tombol);
        expect(SaatPakai).toHaveBeenCalledWith('13800.00');
        cleanup();
    });
});

describe('Laporan › Laporan apotek', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/laporan/apotek');
        window.history.replaceState({}, '', '/kelola/laporan/apotek');
    });
    afterEach(() => cleanup());

    it('tab obat wajib resep: obat, golongan, batch, resep, pasien tersamar, OWA & tanpa apoteker bertulisan', () => {
        RenderUji(<HalamanLaporanApotek {...Props()} />);

        expect(screen.getAllByText('Amoxicillin 500 mg Kapsul (strip isi 10)').length).toBeGreaterThan(0);
        expect(screen.getAllByText('AMX-2601').length).toBeGreaterThan(0);
        expect(screen.getAllByText('R/2026/10/0042').length).toBeGreaterThan(0);
        expect(screen.getAllByText(/B\*\*\* S\*\*\*/).length).toBeGreaterThan(0);
        expect(screen.getAllByText('Tanpa resep (OWA)').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Tanpa apoteker').length).toBeGreaterThan(0);
        expect(screen.getByText(/Nama pasien disamarkan/)).toBeTruthy();
        const nomor = screen.getAllByRole('link', { name: 'INV/SLO1/261002/K01-0007' })[0];
        expect(nomor?.getAttribute('href')).toBe('/kelola/penjualan/01J9PNJ0000000000000000001');
        const kedaluwarsa = screen.getByRole('link', { name: 'Laporan stok › Kedaluwarsa' });
        expect(kedaluwarsa.getAttribute('href')).toBe('/kelola/laporan/stok?tab=kedaluwarsa');
    });

    it('pemegang izin resep tidak melihat catatan penyamaran. Daftar kosong menjelaskan', () => {
        RenderUji(<HalamanLaporanApotek {...Props({ LihatPasien: true, Penjualan: BuatHasilTabel([]) })} />);

        expect(screen.queryByText(/Nama pasien disamarkan/)).toBeNull();
        expect(screen.getByText('Belum ada penjualan obat keras, psikotropika, atau narkotika.')).toBeTruthy();
    });

    it('tab SIPNAP: angka per kolom, penjelasan bukan laporan resmi, ekspor membawa bulan', () => {
        RenderUji(<HalamanLaporanApotek {...Props({ Tab: 'sipnap' })} />);

        expect(screen.getAllByText('Diazepam 2 mg Tablet').length).toBeGreaterThan(0);
        expect(screen.getAllByText('120').length).toBeGreaterThan(0);
        expect(screen.getByText(/bukan laporan resmi/)).toBeTruthy();
        const ekspor = AmbilHrefEkspor('csv', 'Ekspor');
        expect(ekspor).toContain('/kelola/laporan/apotek/sipnap/ekspor?');
        expect(ekspor).toContain('bulan=2026-09');
    });

    it('opsi bulan: 24 bulan mundur melewati pergantian tahun', () => {
        const opsi = SusunOpsiBulan('2026-02');
        expect(opsi[0]).toEqual({ Nilai: '2026-02', Label: 'Februari 2026' });
        expect(opsi[2]).toEqual({ Nilai: '2025-12', Label: 'Desember 2025' });
        expect(opsi).toHaveLength(24);
    });
});
