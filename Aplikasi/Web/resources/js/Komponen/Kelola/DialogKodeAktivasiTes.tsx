import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { LABEL_SELESAI_RAHASIA } from '@/Komponen/Umpan/DialogRahasia';

import DialogKodeAktivasi from './DialogKodeAktivasi';

afterEach(cleanup);

const kode = {
    UuidPerangkat: '0198f0c2-3f6a-7a12-9c31-5b2d4e6f8a10',
    Kode: 'A7K9M2QT',
    NamaPerangkat: 'Kasir Depan',
    KodePerangkat: 'JKT1-K02',
    QrSvg: '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
    KedaluwarsaPada: '2026-09-27T10:15:00+07:00',
};

describe('DialogKodeAktivasi (F-02b BR-02.3)', () => {
    it('berjudul nama & kode perangkat, memuat QR, dan memakai dialog rahasia yang sama dengan nilai rahasia lain', () => {
        render(<DialogKodeAktivasi kode={kode} saatSelesai={vi.fn()} />);

        expect(screen.getByRole('alertdialog', { name: 'Aktifkan Kasir Depan (JKT1-K02)' })).toBeTruthy();
        expect(screen.getByAltText('Kode QR aktivasi Kasir Depan')).toBeTruthy();
        expect(screen.getByText('Hanya ditampilkan sekali')).toBeTruthy();
        expect(screen.getByText(/Biarkan dialog ini terbuka sampai perangkat selesai diaktifkan/)).toBeTruthy();
        expect(screen.getByRole('button', { name: LABEL_SELESAI_RAHASIA })).toBeTruthy();
    });

    it('menampilkan kode apa adanya tanpa tanda hubung', () => {
        render(<DialogKodeAktivasi kode={kode} saatSelesai={vi.fn()} />);

        // Server memang menormalkan tanda hubung, tetapi yang tampil harus persis sama dengan isi QR
        // dan dengan yang diketik di aplikasi kasir supaya tidak membingungkan.
        expect(screen.getByText('A7K9M2QT')).toBeTruthy();
        expect(screen.queryByText('A7K9-M2QT')).toBeNull();
    });

    it('tombol salin menyalin kode tanpa tanda hubung lalu berubah menjadi konfirmasi', async () => {
        const TulisTeks = vi.fn().mockResolvedValue(undefined);
        Object.assign(navigator, { clipboard: { writeText: TulisTeks } });

        render(<DialogKodeAktivasi kode={kode} saatSelesai={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: /Salin kode/ }));

        expect(TulisTeks).toHaveBeenCalledWith('A7K9M2QT');
        await waitFor(() => expect(screen.getByRole('button', { name: /Kode tersalin/ })).toBeTruthy());
    });

    it('menyebut pemindaian QR sekaligus batasnya di Windows', () => {
        render(<DialogKodeAktivasi kode={kode} saatSelesai={vi.fn()} />);

        // Pemindai (mobile_scanner) hanya ada di Android & iOS, jadi janji "pindai" harus disertai
        // keterangan bahwa di Windows kodenya diketik.
        expect(screen.getByText(/pindai QR ini atau ketik/i)).toBeTruthy();
        expect(screen.getByText(/Windows tidak punya pemindai/i)).toBeTruthy();
    });

    it('konfirmasi memanggil saatSelesai', () => {
        const SaatSelesai = vi.fn();
        render(<DialogKodeAktivasi kode={kode} saatSelesai={SaatSelesai} />);

        fireEvent.click(screen.getByRole('button', { name: LABEL_SELESAI_RAHASIA }));
        expect(SaatSelesai).toHaveBeenCalledTimes(1);
    });
});
