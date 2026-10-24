import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanBuatOutlet from '@/Halaman/Kelola/Outlet/Buat';
import {
    AturGalatBalasanUji,
    AturHalamanUji,
    kirimanForm,
    RenderUji,
    TambahPropsHalamanUji,
} from '@/Komponen/Katalog/TiruanInertia';

import {
    HitungLangkahOutletBergalat,
    PetakanGalatOutletKeLangkah,
    PeriksaLangkahOutlet,
    SusunLangkahOutlet,
    type IsianPeriksaOutlet,
} from './LangkahOutlet';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const isian: IsianPeriksaOutlet = {
    Nama: 'Raja Sayur Solo',
    Kode: 'RSY',
    Merek: 'M-1',
    ZonaWaktu: 'WIB',
    JamTutupBuku: '04:00',
    Pkp: false,
    Nitku: '',
    Kanvas: false,
    NomorKendaraan: '',
};
const batas = { Terpakai: 1, Batas: 5 };
const kota = [{ Kode: '33.72', Nama: 'Kota Surakarta', NamaProvinsi: 'Jawa Tengah', ZonaWaktu: 'WIB' }];

const Lanjut = () => fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));

describe('Aturan wizard outlet (LangkahOutlet)', () => {
    it('langkah Kanvas hanya muncul untuk usaha grosir/distribusi', () => {
        expect(SusunLangkahOutlet(false).map((l) => l.Kunci)).toEqual(['Identitas', 'Lokasi', 'Pajak', 'Tinjau']);
        expect(SusunLangkahOutlet(true).map((l) => l.Kunci)).toEqual([
            'Identitas',
            'Lokasi',
            'Pajak',
            'Kanvas',
            'Tinjau',
        ]);
    });

    it('Identitas: nama wajib, kode 3–5 karakter diawali huruf (BR-02.2), merek wajib bila ada pilihan', () => {
        expect(PeriksaLangkahOutlet('Identitas', isian, true)).toEqual({});
        expect(Object.keys(PeriksaLangkahOutlet('Identitas', { ...isian, Nama: '', Kode: '' }, false))).toEqual([
            'Nama',
            'Kode',
        ]);
        for (const kode of ['AB', 'SOLOBARU', '1SLO', 'SL-O', 'SL O']) {
            expect(PeriksaLangkahOutlet('Identitas', { ...isian, Kode: kode }, false).Kode).toContain('3–5 karakter');
        }
        for (const kode of ['RSY', 'JKT1', 'UTAMA', 'a1b2c']) {
            expect(PeriksaLangkahOutlet('Identitas', { ...isian, Kode: kode }, false)).toEqual({});
        }
        expect(PeriksaLangkahOutlet('Identitas', { ...isian, Merek: '' }, true).Merek).toBe('Pilih merek.');
        expect(PeriksaLangkahOutlet('Identitas', { ...isian, Merek: '' }, false)).toEqual({});
    });

    it('Lokasi, Pajak, dan Kanvas memeriksa jam, NITKU, dan nomor kendaraan', () => {
        expect(PeriksaLangkahOutlet('Lokasi', { ...isian, JamTutupBuku: '25:00' }, false).JamTutupBuku).toContain(
            'JJ:MM',
        );
        expect(PeriksaLangkahOutlet('Lokasi', { ...isian, JamTutupBuku: '' }, false).JamTutupBuku).toContain('JJ:MM');
        expect(PeriksaLangkahOutlet('Lokasi', isian, false)).toEqual({});
        expect(PeriksaLangkahOutlet('Pajak', { ...isian, Pkp: true, Nitku: '123' }, false).Nitku).toBe(
            'NITKU terdiri dari 22 angka.',
        );
        expect(PeriksaLangkahOutlet('Pajak', { ...isian, Pkp: true, Nitku: '1'.repeat(22) }, false)).toEqual({});
        expect(PeriksaLangkahOutlet('Pajak', { ...isian, Pkp: false, Nitku: '123' }, false)).toEqual({});
        expect(PeriksaLangkahOutlet('Kanvas', { ...isian, Kanvas: true, NomorKendaraan: 'AD 1234 XY' }, false)).toEqual(
            {},
        );
        expect(
            PeriksaLangkahOutlet('Kanvas', { ...isian, Kanvas: true, NomorKendaraan: '!AD' }, false).NomorKendaraan,
        ).toContain('huruf, angka, dan spasi');
        expect(PeriksaLangkahOutlet('Tinjau', { ...isian, Nama: '' }, false)).toEqual({});
    });

    it('kunci masalah server menuju langkahnya', () => {
        expect(PetakanGalatOutletKeLangkah('Kode')).toBe('Identitas');
        expect(PetakanGalatOutletKeLangkah('JamTutupBuku')).toBe('Lokasi');
        expect(PetakanGalatOutletKeLangkah('Nitku')).toBe('Pajak');
        expect(PetakanGalatOutletKeLangkah('NomorKendaraan')).toBe('Kanvas');
        expect(PetakanGalatOutletKeLangkah('Umum')).toBeNull();
        expect(HitungLangkahOutletBergalat({ Nitku: 'x', Kode: 'y', Umum: 'z' })).toEqual(['Identitas', 'Pajak']);
    });
});

describe('Wizard tambah outlet (halaman)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/outlet/buat');
        // Sektor kafe: langkah Kanvas (khusus grosir/distribusi) tidak ada, jadi 4 langkah.
        TambahPropsHalamanUji({ SektorOutlet: ['FNB-CAF'] });
    });
    afterEach(() => cleanup());

    const Render = () =>
        RenderUji(
            <HalamanBuatOutlet
                Merek={[
                    { Nilai: 'M-1', Label: 'Raja Sayur' },
                    { Nilai: 'M-2', Label: 'Raja Buah' },
                ]}
                Kota={kota}
                BatasOutlet={batas}
            />,
        );

    it('Lanjut menahan nama dan kode yang salah; kode dari 6 karakter ditolak', () => {
        Render();

        expect(screen.getByText('Langkah 1 dari 4')).toBeTruthy();
        Lanjut();
        expect(screen.getByText('Isi nama outlet dulu.')).toBeTruthy();
        expect(screen.getByText('Isi kode outlet dulu.')).toBeTruthy();
        expect(screen.getByText('Langkah 1 dari 4')).toBeTruthy();
        expect(kirimanForm).toHaveLength(0);
    });

    it('Tinjau menyorot kode outlet yang tidak bisa diubah, dan Ubah kembali ke langkah itu', () => {
        Render();
        fireEvent.change(screen.getByLabelText('Nama outlet'), { target: { value: 'Raja Sayur Solo' } });
        fireEvent.change(screen.getByLabelText('Kode outlet'), { target: { value: 'rsy' } });
        fireEvent.change(screen.getByLabelText('Merek'), { target: { value: 'M-1' } });
        Lanjut();
        Lanjut();
        Lanjut();

        expect(screen.getByText('Langkah 4 dari 4')).toBeTruthy();
        expect(screen.getByText('Periksa kode outlet')).toBeTruthy();
        expect(screen.getAllByText('RSY').length).toBeGreaterThan(0);
        expect(screen.getByText('Raja Sayur Solo')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Ubah Nama & kode' }));
        expect(screen.getByText('Langkah 1 dari 4')).toBeTruthy();
    });

    it('usaha grosir mendapat langkah Kanvas; nomor kendaraan yang salah menahan Lanjut', () => {
        TambahPropsHalamanUji({ SektorOutlet: ['WHS-DST'] });
        Render();
        fireEvent.change(screen.getByLabelText('Nama outlet'), { target: { value: 'Truk Solo' } });
        fireEvent.change(screen.getByLabelText('Kode outlet'), { target: { value: 'TRK1' } });
        fireEvent.change(screen.getByLabelText('Merek'), { target: { value: 'M-1' } });
        Lanjut();
        Lanjut();
        Lanjut();

        expect(screen.getByText('Langkah 4 dari 5')).toBeTruthy();
        fireEvent.click(screen.getByLabelText('Outlet kanvas (kendaraan salesman)'));
        fireEvent.change(screen.getByLabelText('Nomor kendaraan (opsional)'), { target: { value: '!!' } });
        Lanjut();
        expect(screen.getByText(/huruf, angka, dan spasi/)).toBeTruthy();
        expect(screen.getByText('Langkah 4 dari 5')).toBeTruthy();
    });

    it('server menolak kode kembar: kembali ke langkah Nama & kode dengan pesan server', () => {
        Render();
        fireEvent.change(screen.getByLabelText('Nama outlet'), { target: { value: 'Raja Sayur Solo' } });
        fireEvent.change(screen.getByLabelText('Kode outlet'), { target: { value: 'RSY' } });
        Lanjut();
        Lanjut();
        Lanjut();
        AturGalatBalasanUji({ Kode: 'Kode outlet sudah dipakai.' });
        fireEvent.click(screen.getByRole('button', { name: 'Tambah outlet' }));

        expect(screen.getByText('Langkah 1 dari 4')).toBeTruthy();
        expect(screen.getByText('Kode outlet sudah dipakai.')).toBeTruthy();
        expect(screen.getByText('Perlu diperbaiki')).toBeTruthy();
    });
});
