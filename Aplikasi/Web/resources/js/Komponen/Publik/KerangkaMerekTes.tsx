import { cleanup, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import HalamanInvoiceDigital from '@/Halaman/Publik/InvoiceDigital';
import HalamanKuitansiInvoiceDigital from '@/Halaman/Publik/KuitansiInvoice';
import HalamanPenawaranDigital from '@/Halaman/Publik/PenawaranDigital';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import KerangkaMerek from '@/Komponen/Publik/KerangkaMerek';
import type { DokumenKuitansiInvoice } from '@/Tipe/Invoice';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-241/D-242: kerangka bermerek tenant untuk halaman pelanggan. Kepala dan kaki tidak ikut tercetak (dokumen A4 tetap
 * bersih), nama toko jadi inisial bila logo belum ada, dan keadaan tidak ditemukan tidak punya dua `<h1>`.
 */

const kuitansi: DokumenKuitansiInvoice = {
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
        KodePph: null,
        TarifPph: null,
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

describe('KerangkaMerek', () => {
    afterEach(() => cleanup());

    it('kepala dan kaki disembunyikan saat cetak, isi tidak menjorok saat cetak', () => {
        const { container } = RenderUji(
            <KerangkaMerek toko={{ Nama: 'Konsultan Kuitansi Prima' }} label="Kuitansi" judul="BP/2609/0001">
                <p>isi</p>
            </KerangkaMerek>,
        );

        expect(container.querySelector('header')?.className).toContain('print:hidden');
        expect(container.querySelector('footer')?.className).toContain('print:hidden');
        expect(container.querySelector('main')?.className).toContain('print:mt-0');
        expect(screen.getByRole('heading', { level: 1, name: 'BP/2609/0001' })).toBeTruthy();
        expect(screen.getByText('KK')).toBeTruthy();
    });

    it('lebar dokumen A4 memakai kolom 4xl, bawaan satu kolom sempit', () => {
        const { container, unmount: Lepas } = RenderUji(
            <KerangkaMerek label="Invoice" judul="INV-1" lebar="4xl">
                <p>isi</p>
            </KerangkaMerek>,
        );
        expect(container.querySelector('main')?.className).toContain('max-w-4xl');
        Lepas();

        const { container: lain } = RenderUji(
            <KerangkaMerek label="Invoice" judul="INV-1">
                <p>isi</p>
            </KerangkaMerek>,
        );
        expect(lain.querySelector('main')?.className).toContain('max-w-xl');
    });
});

describe('Halaman dokumen pelanggan (D-242)', () => {
    afterEach(() => cleanup());

    it('kuitansi: judul nomor, nama penjual, logo bila ada, tombol kembali ke invoice dan cetak', () => {
        AturHalamanUji({}, '/i/KODE/kuitansi/U1');
        RenderUji(
            <HalamanKuitansiInvoiceDigital Dokumen={kuitansi} KodeInvoice="KODE" UrlLogo="/toko/logo?outlet=O1" />,
        );

        expect(screen.getByRole('heading', { level: 1, name: 'BP/2609/0001' })).toBeTruthy();
        expect(screen.getByAltText('Logo Konsultan Kuitansi Prima').getAttribute('src')).toBe('/toko/logo?outlet=O1');
        expect(screen.getByRole('link', { name: 'Kembali ke invoice' }).getAttribute('href')).toBe('/i/KODE');
        expect(screen.getByRole('button', { name: /Cetak atau simpan PDF/ })).toBeTruthy();
    });

    it('tidak ditemukan: satu judul utama saja untuk invoice, penawaran, dan kuitansi', () => {
        AturHalamanUji({}, '/i/SALAH');
        RenderUji(<HalamanInvoiceDigital Dokumen={null} KodeInvoice={null} />);
        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(screen.getByRole('heading', { level: 2, name: 'Invoice tidak ditemukan' })).toBeTruthy();
        cleanup();

        RenderUji(<HalamanPenawaranDigital Dokumen={null} KodePenawaran={null} />);
        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(screen.getByRole('heading', { level: 2, name: 'Penawaran tidak ditemukan' })).toBeTruthy();
        cleanup();

        RenderUji(<HalamanKuitansiInvoiceDigital Dokumen={null} KodeInvoice="SALAH" />);
        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(screen.getByRole('heading', { level: 2, name: 'Kuitansi tidak ditemukan' })).toBeTruthy();
    });
});
