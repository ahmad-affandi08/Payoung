import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import TampilanKuitansiInvoice from '@/Komponen/Invoice/TampilanKuitansiInvoice';
import type { DokumenKuitansiInvoice } from '@/Tipe/Invoice';

function Dokumen(ubah: Partial<DokumenKuitansiInvoice['Kuitansi']> = {}): DokumenKuitansiInvoice {
    return {
        Kuitansi: {
            Uuid: '01J9KUITANSI00000000000001',
            Nomor: 'BP/2609/0001',
            Tanggal: '2026-09-29',
            Status: 'Diposting',
            LabelStatus: 'Diposting',
            Jumlah: '4000000.00',
            PotonganPph: '0.00',
            UangDiterima: '4000000.00',
            NomorBuktiPotong: null,
            SisaSetelah: '6000000.00',
            AlasanBatal: null,
            KodePph: 'PPh 23',
            TarifPph: '2.000000',
            ...ubah,
        },
        Invoice: {
            Nomor: 'INV/UTM/2609/0001',
            Tanggal: '2026-09-27',
            Perihal: 'Pelatihan manajemen proyek',
            Tagihan: '10000000.00',
            Status: 'Terbit',
        },
        Penjual: { Nama: 'Konsultan Kuitansi Prima', Alamat: null, KodeOutlet: 'UTM', Npwp: null, Pkp: false },
        Pembeli: { Nama: 'PT Pembayar Setia Abadi', Alamat: null, NoHp: '', Email: null, Npwp: null },
    };
}

describe('Tampilan kuitansi invoice', () => {
    afterEach(() => cleanup());

    it('menampilkan nomor, pembayar, uang diterima beserta terbilang, invoice asal, dan sisa tagihan', () => {
        render(<TampilanKuitansiInvoice dokumen={Dokumen()} />);

        expect(screen.getByText('BP/2609/0001')).toBeTruthy();
        expect(screen.getByText('PT Pembayar Setia Abadi')).toBeTruthy();
        expect(screen.getByText(/empat juta rupiah/i)).toBeTruthy();
        expect(screen.getByText('INV/UTM/2609/0001')).toBeTruthy();
        expect(screen.getByText('Sisa tagihan setelah pembayaran ini')).toBeTruthy();
        expect(screen.queryByText('Lunas')).toBeNull();
        expect(screen.queryByText(/bea meterai/)).toBeNull();
    });

    it('PPh dipotong pembeli: uang diterima = dilunasi dikurangi PPh, nomor bukti potong tampil, lunas bila sisa nol, meterai diingatkan di atas Rp 5 juta', () => {
        render(
            <TampilanKuitansiInvoice
                dokumen={Dokumen({
                    Jumlah: '6000000.00',
                    PotonganPph: '200000.00',
                    UangDiterima: '5800000.00',
                    NomorBuktiPotong: '0007-BP23-2026',
                    SisaSetelah: '0.00',
                })}
            />,
        );

        expect(screen.getByText(/PPh 23 .*dipotong pembeli/)).toBeTruthy();
        expect(screen.getByText('0007-BP23-2026')).toBeTruthy();
        expect(screen.getByText(/lima juta delapan ratus ribu rupiah/i)).toBeTruthy();
        expect(screen.getByText('Lunas')).toBeTruthy();
        expect(screen.getByText(/bea meterai/)).toBeTruthy();
    });

    it('pembayaran dibatalkan ditandai jelas dengan alasannya dan tidak ditandai lunas', () => {
        render(
            <TampilanKuitansiInvoice
                dokumen={Dokumen({
                    Status: 'Dibatalkan',
                    LabelStatus: 'Dibatalkan',
                    AlasanBatal: 'Salah catat',
                    SisaSetelah: '0.00',
                })}
            />,
        );

        expect(screen.getByText(/Pembayaran dibatalkan/)).toBeTruthy();
        expect(screen.getByText('Salah catat')).toBeTruthy();
        expect(screen.queryByText('Lunas')).toBeNull();
    });
});
