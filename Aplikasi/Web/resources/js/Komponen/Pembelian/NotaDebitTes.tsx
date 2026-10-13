import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanBuatNotaDebit from '@/Halaman/Kelola/Pembelian/NotaDebit/Buat';
import HalamanDaftarNotaDebit from '@/Halaman/Kelola/Pembelian/NotaDebit/Daftar';
import HalamanDetailNotaDebit from '@/Halaman/Kelola/Pembelian/NotaDebit/Detail';
import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel } from '@/Komponen/Persediaan/DataUjiPersediaan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const Izin = { Kelola: true, Setujui: false, LihatJurnal: true, Persediaan: true };
const Faktur = {
    Uuid: '01J9FKT0000000000000000001',
    Nomor: 'FB/2610/0001',
    NomorFakturPemasok: 'INV/SPN/77',
    Tanggal: '2026-10-01',
    Status: 'BelumDibayar',
    Total: '1100000.00',
    SisaHutang: '1100000.00',
    PpnDikreditkan: true,
    NamaPemasok: 'PT Sumber Pangan Nusantara',
    Baris: [
        { Id: 11, NamaProduk: 'Beras Premium Rojolele 5 kg', Nilai: '600000.00', SisaKlaim: '600000.00' },
        { Id: 12, NamaProduk: 'Gula Pasir Kristal 1 kg', Nilai: '500000.00', SisaKlaim: '0.00' },
    ],
};
const OpsiJenis = [
    { Nilai: 'SelisihHarga' as const, Label: 'Selisih harga' },
    { Nilai: 'KlaimKerusakan' as const, Label: 'Klaim barang rusak/cacat' },
];
const OpsiAkun = [{ Uuid: '01J9AKN0000000000000000001', Kode: '1-1100', Nama: 'Kas Outlet' }];

describe('Nota debit pembelian (v6.07)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/pembelian/nota-debit');
        window.history.replaceState({}, '', '/kelola/pembelian/nota-debit');
    });
    afterEach(() => cleanup());

    it('daftar: total, uang kembali, dan tautan rincian', () => {
        RenderUji(
            <HalamanDaftarNotaDebit
                Nota={BuatHasilTabel([
                    {
                        Uuid: '01J9ND00000000000000000001',
                        Nomor: 'ND/2610/0001',
                        NomorNotaPemasok: 'NK-0007',
                        Tanggal: '2026-10-02',
                        Jenis: 'SelisihHarga',
                        LabelJenis: 'Selisih harga',
                        NomorFaktur: Faktur.Nomor,
                        NamaPemasok: 'PT Sumber Pangan Nusantara',
                        Total: '150000.00',
                        NilaiRefund: '50000.00',
                        Status: 'Diposting',
                    },
                ])}
                OpsiJenis={OpsiJenis}
                OpsiStatus={[{ Nilai: 'Diposting', Label: 'Diposting' }]}
                Izin={Izin}
            />,
        );
        expect(screen.getAllByText('Rp 150.000').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Rp 50.000').length).toBeGreaterThan(0);
        expect(screen.getAllByRole('link', { name: 'ND/2610/0001' })[0]?.getAttribute('href')).toBe(
            '/kelola/pembelian/nota-debit/01J9ND00000000000000000001',
        );
    });

    it('buat: mengirim nilai, baris terpilih, dan alasan; akun uang kembali hanya bila melebihi sisa hutang', () => {
        RenderUji(
            <HalamanBuatNotaDebit
                Faktur={{ ...Faktur, SisaHutang: '100000.00' }}
                BatasPersetujuan="5000000.00"
                OpsiJenis={OpsiJenis}
                OpsiAkun={OpsiAkun}
                HariIni="2026-10-02"
                BolehMelebihiBatas
            />,
        );
        // Baris tanpa sisa nilai tidak bisa dipilih.
        expect(screen.getByRole('checkbox', { name: 'Pilih Gula Pasir Kristal 1 kg' }).hasAttribute('disabled')).toBe(
            true,
        );
        fireEvent.click(screen.getByRole('checkbox', { name: 'Pilih Beras Premium Rojolele 5 kg' }));
        fireEvent.change(screen.getByLabelText(/^Nilai nota debit/), { target: { value: '150000' } });
        fireEvent.change(screen.getByLabelText(/^Alasan/), { target: { value: 'Harga faktur lebih tinggi' } });

        // 150.000 melebihi sisa hutang 100.000 → pilihan akun uang kembali muncul.
        expect(screen.getByText('Melebihi sisa hutang faktur')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Simpan nota debit' }));
        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'post',
            url: '/kelola/pembelian/nota-debit',
            data: {
                UuidFaktur: Faktur.Uuid,
                Jenis: 'SelisihHarga',
                NilaiKlaim: '150000',
                Baris: [11],
                Alasan: 'Harga faktur lebih tinggi',
                NomorNotaPemasok: '',
                UuidAkunRefund: '01J9AKN0000000000000000001',
            },
        });
    });

    it('buat: tanpa kelebihan sisa hutang tidak meminta akun; peringatan batas persetujuan untuk non-penyetuju', () => {
        RenderUji(
            <HalamanBuatNotaDebit
                Faktur={Faktur}
                BatasPersetujuan="500000.00"
                OpsiJenis={OpsiJenis}
                OpsiAkun={OpsiAkun}
                HariIni="2026-10-02"
                BolehMelebihiBatas={false}
            />,
        );
        fireEvent.change(screen.getByLabelText(/^Nilai nota debit/), { target: { value: '600000' } });
        expect(screen.getByText('Di atas batas persetujuan pembelian')).toBeTruthy();
        expect(screen.queryByText('Melebihi sisa hutang faktur')).toBeNull();
        fireEvent.change(screen.getByLabelText(/^Alasan/), { target: { value: 'Rabat susulan pemasok' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan nota debit' }));
        expect(kirimanForm.at(-1)).toMatchObject({ data: { NilaiKlaim: '600000', Baris: [] } });
    });

    it('rincian: tombol batal hanya bila diizinkan', () => {
        const props = {
            Nota: {
                Uuid: '01J9ND00000000000000000001',
                Nomor: 'ND/2610/0001',
                NomorNotaPemasok: 'NK-0007',
                Tanggal: '2026-10-02',
                Jenis: 'SelisihHarga' as const,
                LabelJenis: 'Selisih harga',
                NilaiKlaim: '1000.00',
                Pajak: '0.00',
                Total: '1000.00',
                PotongHutang: '1000.00',
                NilaiRefund: '0.00',
                KePersediaan: '400.00',
                KeHpp: '600.00',
                Alasan: 'Harga faktur lebih tinggi',
                Status: 'Diposting' as const,
                AlasanBatal: null,
                NamaPemasok: 'PT Sumber Pangan Nusantara',
                UuidFaktur: Faktur.Uuid,
                NomorFaktur: Faktur.Nomor,
            },
            Baris: [
                {
                    Id: 1,
                    NamaProduk: 'Beras Premium Rojolele 5 kg',
                    NilaiKlaim: '1000.00',
                    Pajak: '0.00',
                    KePersediaan: '400.00',
                    KeHpp: '600.00',
                },
            ],
            Jurnal: [],
            Izin,
        };
        RenderUji(<HalamanDetailNotaDebit {...props} Tindakan={{ Batalkan: true }} />);
        expect(screen.getByRole('button', { name: 'Batalkan nota debit' })).toBeTruthy();
        expect(screen.getAllByText('Rp 400').length).toBeGreaterThan(0);
        cleanup();
        RenderUji(<HalamanDetailNotaDebit {...props} Tindakan={{ Batalkan: false }} />);
        expect(screen.queryByRole('button', { name: 'Batalkan nota debit' })).toBeNull();
    });
});
