import { describe, expect, it } from 'vitest';

import {
    AmbilJenisLabelStatusStokAwal,
    AmbilLabelMetodeHpp,
    AmbilLabelPelacakan,
    BuatUrlKartuStok,
    FormatHppSatuan,
    FormatJumlahStok,
    FormatLabelGudang,
    FormatNilai,
} from './FormatPersediaan';

describe('FormatPersediaan: tampilan Indonesia dari string desimal server (§17.6.7)', () => {
    it('jumlah stok: ribuan titik, koma desimal, nol dibuang, minus U+2212, satuan di belakang', () => {
        expect(FormatJumlahStok('1250.5000', 'kg')).toBe('1.250,5 kg');
        expect(FormatJumlahStok('10.0000', 'pcs')).toBe('10 pcs');
        expect(FormatJumlahStok('-4.0000', 'pcs')).toBe('−4 pcs');
        expect(FormatJumlahStok('-0.0000', 'pcs')).toBe('0 pcs');
        expect(FormatJumlahStok('99999999999999.9999')).toBe('99.999.999.999.999,9999');
        expect(() => FormatJumlahStok('1,5')).toThrow('Jumlah tidak valid');
    });

    it('HPP per satuan: selalu paling banyak 2 desimal (dibulatkan HalfUp), null = —', () => {
        // Permintaan klien: "Rp 3.636,545455" di Saldo stok terlalu panjang, cukup 2 angka di belakang koma.
        expect(FormatHppSatuan('3636.545455')).toBe('Rp 3.636,55');
        expect(FormatHppSatuan('1234.568000')).toBe('Rp 1.234,57');
        expect(FormatHppSatuan('1000.000000')).toBe('Rp 1.000');
        expect(FormatHppSatuan('1234.500000')).toBe('Rp 1.234,50');
        expect(FormatHppSatuan('333.333333')).toBe('Rp 333,33');
        expect(FormatHppSatuan('0.004999')).toBe('Rp 0');
        expect(FormatHppSatuan('0.005000')).toBe('Rp 0,01');
        expect(FormatHppSatuan('-3636.545455')).toBe('−Rp 3.636,55');
        expect(FormatHppSatuan('-0.001000')).toBe('Rp 0');
        expect(FormatHppSatuan(null)).toBe('—');
        expect(() => FormatHppSatuan('x')).toThrow('HPP tidak valid');
    });

    it('nilai persediaan/mutasi bertanda memakai format Rupiah', () => {
        expect(FormatNilai('-3703.70')).toBe('−Rp 3.703,70');
        expect(FormatNilai('1250000000.00')).toBe('Rp 1.250.000.000');
        expect(FormatNilai('0')).toBe('Rp 0');
    });

    it('label status, pelacakan, metode HPP, dan tautan kartu stok', () => {
        expect(AmbilJenisLabelStatusStokAwal('Diposting')).toBe('sukses');
        expect(AmbilJenisLabelStatusStokAwal('Memproses')).toBe('peringatan');
        expect(AmbilJenisLabelStatusStokAwal('Dibatalkan')).toBe('bahaya');
        expect(AmbilJenisLabelStatusStokAwal('Draf')).toBe('netral');
        expect(AmbilLabelPelacakan('Batch')).toBe('Batch & kedaluwarsa');
        expect(AmbilLabelPelacakan('Tidak')).toBeNull();
        expect(AmbilLabelMetodeHpp('Fifo')).toContain('FIFO');
        expect(FormatLabelGudang({ Nama: 'Gudang Utama', NamaOutlet: 'Outlet Solo', Aktif: true })).toBe(
            'Gudang Utama | Outlet Solo',
        );
        expect(FormatLabelGudang({ Nama: 'Gudang Lama', NamaOutlet: null, Aktif: false })).toBe(
            'Gudang Lama (diarsipkan)',
        );
        expect(BuatUrlKartuStok('01PRD', '01GDG')).toBe('/kelola/persediaan/kartu-stok?produk=01PRD&gudang=01GDG');
        expect(BuatUrlKartuStok('01PRD', '01GDG', '2026-09-01', '2026-09-30')).toBe(
            '/kelola/persediaan/kartu-stok?produk=01PRD&gudang=01GDG&dari=2026-09-01&sampai=2026-09-30',
        );
    });
});
