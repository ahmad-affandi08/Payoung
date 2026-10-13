import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import HalamanStrukDigital, { type StrukDigital } from '@/Halaman/Publik/StrukDigital';
import { AturGalatBalasanUji, AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const struk: StrukDigital = {
    NamaUsaha: 'Kopi Senja Solo',
    TeksKepala: ['@kopisenja'],
    NamaOutlet: 'Outlet Solo Baru',
    Alamat: 'Jl. Slamet Riyadi 10',
    Npwp: null,
    Nomor: 'INV/SLO/260920/K01-0001',
    Waktu: '2026-09-20T03:15:00Z',
    NamaKasir: 'Rina Wulandari',
    NamaPelanggan: null,
    Dibatalkan: false,
    Baris: [
        {
            NamaProduk: 'Kopi Susu Gula Aren',
            Pilihan: ['Less sugar'],
            Jumlah: '2.000',
            HargaSatuan: '18000.00',
            Diskon: '2000.00',
            Total: '36000.00',
            NomorSeri: [],
            GaransiSampai: null,
        },
    ],
    Subtotal: '36000.00',
    TotalDiskon: '2000.00',
    BiayaLayanan: '0.00',
    BiayaKirim: '0.00',
    DiskonKirim: '0.00',
    Pajak: [{ Kode: 'PB1', Tarif: '10.00', Jumlah: '3400.00' }],
    Pembulatan: '0.00',
    TotalAkhir: '37400.00',
    Pembayaran: [{ NamaMetode: 'Tunai', Jumlah: '50000.00' }],
    Kembalian: '12600.00',
    TotalRetur: null,
    CatatanKaki: null,
    TeksPenutup: null,
};

describe('Struk digital publik (POS-11)', () => {
    afterEach(() => cleanup());

    it('menampilkan isi struk: nomor, baris, pajak, total, kembalian, penutup bawaan', () => {
        RenderUji(<HalamanStrukDigital Struk={struk} />);
        expect(screen.getByRole('article', { name: 'Struk INV/SLO/260920/K01-0001' })).toBeTruthy();
        expect(screen.getByText('Kopi Susu Gula Aren')).toBeTruthy();
        expect(screen.getByText('+ Less sugar')).toBeTruthy();
        expect(screen.getByText('PB1 10%')).toBeTruthy();
        expect(screen.getByText('Rp 37.400')).toBeTruthy();
        expect(screen.getByText('Rp 12.600')).toBeTruthy();
        expect(screen.getByText('Terima kasih atas kunjungan Anda')).toBeTruthy();
        expect(screen.queryByText('TRANSAKSI DIBATALKAN')).toBeNull();
    });

    it('F-17 bagian 3: ongkir dan diskon ongkir tampil hanya bila ada', () => {
        RenderUji(<HalamanStrukDigital Struk={{ ...struk, BiayaKirim: '15000.00', DiskonKirim: '15000.00' }} />);
        expect(screen.getByText('Ongkir')).toBeTruthy();
        expect(screen.getByText('Diskon ongkir')).toBeTruthy();
        expect(screen.getAllByText('Rp 15.000').length).toBe(1);
        expect(screen.getByText('-Rp 15.000')).toBeTruthy();
        cleanup();

        RenderUji(<HalamanStrukDigital Struk={struk} />);
        expect(screen.queryByText('Ongkir')).toBeNull();
    });

    it('F-05h: nomor seri dan garansi sampai tampil di baris produk bernomor seri', () => {
        RenderUji(
            <HalamanStrukDigital
                Struk={{
                    ...struk,
                    Baris: struk.Baris.map((b) => ({
                        ...b,
                        NomorSeri: ['IMEI-0001', 'IMEI-0002'],
                        GaransiSampai: '2027-09-20',
                    })),
                }}
            />,
        );
        expect(screen.getByText('No. seri: IMEI-0001, IMEI-0002')).toBeTruthy();
        expect(screen.getByText(/^Garansi sampai 20 \w+ 2027$/)).toBeTruthy();
        cleanup();

        RenderUji(<HalamanStrukDigital Struk={struk} />);
        expect(screen.queryByText(/No\. seri/)).toBeNull();
        expect(screen.queryByText(/Garansi sampai/)).toBeNull();
    });

    it('F-16c bagian 4a: poin diperoleh tampil bila ada', () => {
        RenderUji(<HalamanStrukDigital Struk={{ ...struk, PoinDiperoleh: 1250 }} />);
        expect(screen.getByText('Poin diperoleh')).toBeTruthy();
        expect(screen.getByText('1.250')).toBeTruthy();
    });

    it('laundry: status cucian & tahap proses tampil di atas struk (lacak dari QR nota)', () => {
        RenderUji(
            <HalamanStrukDigital
                Struk={{
                    ...struk,
                    Laundry: {
                        Status: 'Dicuci',
                        LabelStatus: 'Dicuci',
                        Tahap: [
                            { Status: 'Diterima', Label: 'Diterima', Selesai: true },
                            { Status: 'Dicuci', Label: 'Dicuci', Selesai: true },
                            { Status: 'Siap', Label: 'Siap diambil', Selesai: false },
                        ],
                        JenisLayanan: 'Express',
                        Berat: '3.50',
                        Item: [{ Nama: 'Bed cover king', Jumlah: 1 }],
                        Parfum: 'Lavender',
                        EstimasiSelesaiPada: '2026-10-14T02:25:00Z',
                        SiapPada: null,
                        DiambilPada: null,
                    },
                }}
            />,
        );
        expect(screen.getByRole('heading', { name: 'Status cucian: Dicuci' })).toBeTruthy();
        expect(screen.getByText('Express | 3,5 kg | Bed cover king ×1 | parfum Lavender')).toBeTruthy();
        expect(screen.getByText(/Perkiraan selesai/)).toBeTruthy();
        expect(screen.getAllByText('(sudah)')).toHaveLength(2);
        expect(screen.getAllByText('(belum)')).toHaveLength(1);
    });

    it('F7: struk yang meminta penilaian mengirim bintang dan komentar ke alamat struk', () => {
        AturHalamanUji({}, '/s/KODE123');
        RenderUji(
            <HalamanStrukDigital Struk={{ ...struk, UmpanBalik: { Diminta: true, Nilai: null, Komentar: null } }} />,
        );
        const kirim = screen.getByRole('button', { name: 'Kirim penilaian' });
        expect((kirim as HTMLButtonElement).disabled).toBe(true);

        fireEvent.click(screen.getByRole('radio', { name: /4 dari 5 bintang/ }));
        fireEvent.change(screen.getByLabelText('Komentar (opsional)'), { target: { value: 'Enak dan cepat' } });
        fireEvent.click(screen.getByRole('button', { name: 'Kirim penilaian' }));

        expect(kirimanForm).toEqual([
            { metode: 'post', url: '/s/KODE123/umpan-balik', data: { Nilai: 4, Komentar: 'Enak dan cepat' } },
        ]);
    });

    it('F7: galat umum dari server (batas waktu lewat) ditampilkan', () => {
        AturHalamanUji({}, '/s/KODE123');
        AturGalatBalasanUji({ Umum: 'Batas waktu memberi penilaian untuk transaksi ini sudah lewat.' });
        RenderUji(
            <HalamanStrukDigital Struk={{ ...struk, UmpanBalik: { Diminta: true, Nilai: null, Komentar: null } }} />,
        );
        fireEvent.click(screen.getByRole('radio', { name: /2 dari 5 bintang/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Kirim penilaian' }));

        expect(screen.getByRole('alert').textContent).toContain('Batas waktu memberi penilaian');
    });

    it('F7: penilaian yang sudah dikirim tampil tanpa formulir; tanpa permintaan tidak ada bagian penilaian', () => {
        RenderUji(
            <HalamanStrukDigital Struk={{ ...struk, UmpanBalik: { Diminta: false, Nilai: 5, Komentar: 'Mantap' } }} />,
        );
        expect(screen.getByRole('region', { name: 'Penilaian Anda' })).toBeTruthy();
        expect(screen.getByRole('img', { name: '5 dari 5 bintang' })).toBeTruthy();
        expect(screen.getByText('Mantap')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Kirim penilaian' })).toBeNull();
        cleanup();

        RenderUji(<HalamanStrukDigital Struk={{ ...struk, UmpanBalik: null }} />);
        expect(screen.queryByRole('region', { name: 'Penilaian Anda' })).toBeNull();
        expect(screen.queryByRole('form', { name: 'Penilaian transaksi' })).toBeNull();
    });

    it('void ditandai dan struk tidak dikenal menampilkan keadaan belum tersedia', () => {
        RenderUji(<HalamanStrukDigital Struk={{ ...struk, Dibatalkan: true, TotalRetur: null }} />);
        expect(screen.getByRole('status').textContent).toBe('TRANSAKSI DIBATALKAN');
        cleanup();
        RenderUji(<HalamanStrukDigital Struk={null} />);
        expect(screen.getByRole('heading', { name: 'Struk belum tersedia' })).toBeTruthy();
    });
});
