import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanBuatJurnalUmum from '@/Halaman/Kelola/Akuntansi/JurnalUmum/Buat';
import HalamanDetailJurnalUmum from '@/Halaman/Kelola/Akuntansi/JurnalUmum/Detail';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { PilihOpsi } from '@/Pengujian/InteraksiPilihan';
import type { PropsBuatJurnalUmum, PropsDetailJurnalUmum } from '@/Tipe/Akuntansi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const akunBeban = '01K5AKUNBEBAN000000000001';
const akunAkrual = '01K5AKUNAKRUAL00000000001';

const propsBuat: PropsBuatJurnalUmum = {
    UuidKlien: '01K5KLIEN00000000000000001',
    TanggalBawaan: '2026-09-30',
    OpsiJenis: [
        { Nilai: 'Umum', Label: 'Jurnal umum', Penjelasan: 'Pencatatan tanpa dokumen sendiri.' },
        { Nilai: 'Penyesuaian', Label: 'Jurnal penyesuaian', Penjelasan: 'Penyesuaian akhir periode.' },
    ],
    OpsiOutlet: [{ Uuid: '01K5OUTLET0000000000000001', Nama: 'Toko Utama Solo Baru' }],
    OpsiAkun: [
        { Uuid: akunBeban, Kode: '6-2000', Nama: 'Beban Sewa, Listrik, Air, Internet', Jenis: 'Beban' },
        { Uuid: akunAkrual, Kode: '2-9500', Nama: 'Beban Yang Masih Harus Dibayar', Jenis: 'Kewajiban' },
    ],
    WajibOutlet: false,
    Lampiran: { Ekstensi: ['jpg', 'pdf'], UkuranMaksimalKb: 5120 },
};

const propsDetail: PropsDetailJurnalUmum = {
    JurnalUmum: {
        Uuid: '01K5JURNALUMUM00000000001',
        Nomor: 'JM/2026/09/0001',
        Jenis: 'Penyesuaian',
        LabelJenis: 'Jurnal penyesuaian',
        Tanggal: '2026-09-30',
        Referensi: 'MEMO-AKR-09',
        Keterangan: 'Akrual listrik September belum ditagih PLN',
        TotalNilai: '1500000.00',
        Pembalik: false,
        Dibalik: false,
        DibuatOleh: 'Rina Wulandari',
        DibuatPada: '2026-09-30T03:15:00Z',
        UuidDibalik: null,
        NomorDibalik: null,
        UuidPembalik: null,
        NomorPembalik: null,
        Lampiran: { Nama: 'Memo akrual.pdf', Ukuran: 204800 },
    },
    Jurnal: { Uuid: '01K5JURNAL0000000000000009', Nomor: 'JU/2026/09/000009' },
    Baris: [
        {
            Urutan: 1,
            KodeAkun: '6-2000',
            NamaAkun: 'Beban listrik',
            NamaOutlet: null,
            Debit: '1500000.00',
            Kredit: '0.00',
            Memo: null,
        },
        {
            Urutan: 2,
            KodeAkun: '2-9500',
            NamaAkun: 'Beban yang masih harus dibayar',
            NamaOutlet: null,
            Debit: '0.00',
            Kredit: '1500000.00',
            Memo: 'Tagihan PLN Agustus',
        },
    ],
    Total: { Debit: '1500000.00', Kredit: '1500000.00' },
    Izin: { Kelola: true },
};

function IsiBaris(nomor: number, akun: string, sisi: 'Debit' | 'Kredit', nilai: string): void {
    PilihOpsi(screen.getByRole('combobox', { name: new RegExp(`Akun baris ${String(nomor)}`) }), akun);
    fireEvent.change(screen.getByLabelText(new RegExp(`${sisi} baris ${String(nomor)}`)), { target: { value: nilai } });
}

describe('Kelola/Akuntansi/JurnalUmum (FIN-05, D-131)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/akuntansi/jurnal-umum/buat'));
    afterEach(() => cleanup());

    it('buat: simpan nonaktif sampai seimbang; total & selisih dihitung tanpa float; payload membawa UuidKlien', () => {
        RenderUji(<HalamanBuatJurnalUmum {...propsBuat} />);
        const simpan = screen.getByRole<HTMLButtonElement>('button', { name: 'Simpan & posting' });
        expect(simpan.disabled).toBe(true);
        expect(screen.getByRole('status').textContent).toBe('Isi debit dan kredit');

        fireEvent.change(screen.getByLabelText(/^Keterangan/), { target: { value: 'Akrual listrik September' } });
        IsiBaris(1, akunBeban, 'Debit', '1500000,10');
        IsiBaris(2, akunAkrual, 'Kredit', '1500000');
        expect(screen.getByRole('status').textContent).toBe('Selisih Rp 0,10: debit dan kredit harus sama');
        expect(simpan.disabled).toBe(true);

        fireEvent.change(screen.getByLabelText(/Kredit baris 2/), { target: { value: '1500000,10' } });
        expect(screen.getByRole('status').textContent).toBe('Seimbang');
        expect(simpan.disabled).toBe(false);

        fireEvent.click(simpan);
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/akuntansi/jurnal-umum',
            expect.objectContaining({
                UuidKlien: propsBuat.UuidKlien,
                Jenis: 'Umum',
                Tanggal: '2026-09-30',
                Referensi: null,
                Keterangan: 'Akrual listrik September',
                Baris: [
                    { UuidAkun: akunBeban, UuidOutlet: null, Debit: '1500000.10', Kredit: null, Memo: null },
                    { UuidAkun: akunAkrual, UuidOutlet: null, Debit: null, Kredit: '1500000.10', Memo: null },
                ],
            }),
            expect.anything(),
        );
    });

    it('buat: memilih akun pada baris kosong mengisi sisi lawan dengan selisih sehingga jurnal langsung seimbang', () => {
        AturHalamanUji();
        RenderUji(<HalamanBuatJurnalUmum {...propsBuat} />);

        IsiBaris(1, akunBeban, 'Debit', '1500000');
        PilihOpsi(screen.getByRole('combobox', { name: /Akun baris 2/ }), akunAkrual);

        expect((screen.getByLabelText(/Kredit baris 2/) as HTMLInputElement).value).toMatch(/1\.500\.000/);
        expect((screen.getByLabelText(/Debit baris 2/) as HTMLInputElement).value).toBe('');
    });

    it('buat: mengisi debit mengosongkan kredit baris yang sama; baris bisa ditambah & dihapus (minimal dua)', () => {
        RenderUji(<HalamanBuatJurnalUmum {...propsBuat} />);
        fireEvent.change(screen.getByLabelText(/Kredit baris 1/), { target: { value: '500' } });
        fireEvent.change(screen.getByLabelText(/Debit baris 1/), { target: { value: '700' } });
        expect(screen.getByLabelText<HTMLInputElement>(/Kredit baris 1/).value).toBe('');

        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Hapus baris 1' }).disabled).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'Tambah baris' }));
        expect(screen.getByLabelText(/Debit baris 3/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Hapus baris 3' }));
        expect(screen.queryByLabelText(/Debit baris 3/)).toBeNull();
    });

    it('buat: masalah per baris dari server tampil di barisnya; masalah umum "Baris" tampil di bawah tabel', () => {
        AturHalamanUji(
            {
                'Baris.0.UuidAkun': 'Akun 1-1400 Persediaan adalah akun kendali.',
                Baris: 'Jurnal belum seimbang: total debit Rp 1.000, total kredit Rp 900.',
            },
            '/kelola/akuntansi/jurnal-umum/buat',
        );
        RenderUji(<HalamanBuatJurnalUmum {...propsBuat} />);
        expect(screen.getByText('Akun 1-1400 Persediaan adalah akun kendali.')).toBeTruthy();
        expect(screen.getByRole('alert').textContent).toContain('Jurnal belum seimbang');
    });

    it('detail: nomor, baris jurnal seimbang, tautan jurnal & lampiran; dokumen pembalik hanya untuk yang berhak', () => {
        RenderUji(<HalamanDetailJurnalUmum {...propsDetail} />);
        expect(screen.getAllByText('JM/2026/09/0001').length).toBeGreaterThan(0);
        expect(screen.getByRole('link', { name: 'JU/2026/09/000009' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/jurnal/01K5JURNAL0000000000000009',
        );
        expect(screen.getByRole('link', { name: /Memo akrual\.pdf/ }).getAttribute('href')).toBe(
            '/kelola/akuntansi/jurnal-umum/01K5JURNALUMUM00000000001/lampiran',
        );
        expect(screen.getByText('Total (seimbang)')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Buat dokumen pembalik' }));
        fireEvent.change(screen.getByLabelText(/Alasan koreksi/), { target: { value: 'Akrual dibalik awal bulan' } });
        fireEvent.click(screen.getByRole('button', { name: 'Buat pembalik' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/akuntansi/jurnal-umum/01K5JURNALUMUM00000000001/pembalik',
            expect.objectContaining({ Alasan: 'Akrual dibalik awal bulan' }),
            expect.anything(),
        );
        cleanup();

        RenderUji(<HalamanDetailJurnalUmum {...propsDetail} Izin={{ Kelola: false }} />);
        expect(screen.queryByRole('button', { name: 'Buat dokumen pembalik' })).toBeNull();
        cleanup();

        RenderUji(
            <HalamanDetailJurnalUmum
                {...propsDetail}
                JurnalUmum={{
                    ...propsDetail.JurnalUmum,
                    Dibalik: true,
                    UuidPembalik: '01K5PEMBALIK0000000000001',
                    NomorPembalik: 'JM/2026/10/0001',
                }}
            />,
        );
        expect(screen.getByText('Jurnal umum ini sudah dibalik')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Buat dokumen pembalik' })).toBeNull();
        expect(screen.getByRole('link', { name: 'JM/2026/10/0001' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/jurnal-umum/01K5PEMBALIK0000000000001',
        );
    });
});
