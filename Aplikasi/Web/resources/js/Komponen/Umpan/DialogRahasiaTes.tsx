import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import DialogRahasia, {
    DaftarNilaiRahasia,
    JUDUL_PERINGATAN_RAHASIA,
    LABEL_SELESAI_RAHASIA,
    NilaiRahasia,
    useRahasiaSekali,
} from './DialogRahasia';

/*
 * Nilai rahasia sekali-tampil (kode aktivasi, token API, rahasia webhook, kode pemulihan 2FA) memakai satu dialog
 * yang sama: terbuka otomatis, tidak tertutup selain lewat konfirmasi, dan tidak pernah terbuka dua kali.
 */

afterEach(cleanup);

function Contoh({ saatSelesai = vi.fn() }: { saatSelesai?: () => void }) {
    return (
        <DialogRahasia
            judul="Token Aplikasi akuntansi dibuat"
            peringatan="Salin token ini sekarang."
            saatSelesai={saatSelesai}
        >
            <NilaiRahasia nilai="payoung_12_abcdef" jenis="token" />
        </DialogRahasia>
    );
}

describe('DialogRahasia', () => {
    it('menampilkan judul, peringatan tetap "Hanya ditampilkan sekali", isi, dan satu tombol konfirmasi', () => {
        render(<Contoh />);

        expect(screen.getByRole('alertdialog', { name: 'Token Aplikasi akuntansi dibuat' })).toBeTruthy();
        expect(screen.getByText(JUDUL_PERINGATAN_RAHASIA)).toBeTruthy();
        expect(screen.getByText('Salin token ini sekarang.')).toBeTruthy();
        expect(screen.getByText('payoung_12_abcdef')).toBeTruthy();
        // Satu-satunya tombol penutup; tidak ada "Batal" atau "X".
        expect(screen.getByRole('button', { name: LABEL_SELESAI_RAHASIA })).toBeTruthy();
        expect(screen.queryByRole('button', { name: /Batal|Tutup/ })).toBeNull();
    });

    it('tidak tertutup lewat Esc maupun klik di luar; hanya tombol konfirmasi yang memanggil saatSelesai', () => {
        const SaatSelesai = vi.fn();
        render(<Contoh saatSelesai={SaatSelesai} />);

        fireEvent.keyDown(screen.getByRole('alertdialog'), { key: 'Escape' });
        const overlay = document.querySelector('[data-slot="alert-dialog-overlay"]');
        if (overlay) {
            fireEvent.pointerDown(overlay);
            fireEvent.click(overlay);
        }
        expect(SaatSelesai).not.toHaveBeenCalled();
        expect(screen.getByRole('alertdialog')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: LABEL_SELESAI_RAHASIA }));
        expect(SaatSelesai).toHaveBeenCalledTimes(1);
    });

    it('memasang peringatan sebelum menutup/memuat ulang tab selama terbuka, dan melepasnya saat tertutup', () => {
        const Tambah = vi.spyOn(window, 'addEventListener');
        const Lepas = vi.spyOn(window, 'removeEventListener');
        const Render = render(<Contoh />);

        expect(Tambah.mock.calls.some(([jenis]) => jenis === 'beforeunload')).toBe(true);
        const peristiwa = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(peristiwa);
        expect(peristiwa.defaultPrevented).toBe(true);

        Render.unmount();
        expect(Lepas.mock.calls.some(([jenis]) => jenis === 'beforeunload')).toBe(true);
        Tambah.mockRestore();
        Lepas.mockRestore();
    });
});

describe('NilaiRahasia & DaftarNilaiRahasia', () => {
    it('salin satu nilai: menulis apa adanya ke papan klip lalu tombol berubah menjadi konfirmasi', async () => {
        const TulisTeks = vi.fn().mockResolvedValue(undefined);
        Object.assign(navigator, { clipboard: { writeText: TulisTeks } });
        render(<NilaiRahasia nilai="A7K9M2QT" jenis="kode" besar />);

        fireEvent.click(screen.getByRole('button', { name: /Salin kode/ }));
        expect(TulisTeks).toHaveBeenCalledWith('A7K9M2QT');
        await waitFor(() => expect(screen.getByRole('button', { name: /Kode tersalin/ })).toBeTruthy());
    });

    it('salin semua kode pemulihan: satu kode per baris', async () => {
        const TulisTeks = vi.fn().mockResolvedValue(undefined);
        Object.assign(navigator, { clipboard: { writeText: TulisTeks } });
        render(<DaftarNilaiRahasia nilai={['AAAAA-11111', 'BBBBB-22222']} jenis="kode" />);

        expect(screen.getByText('AAAAA-11111')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: /Salin semua kode/ }));
        expect(TulisTeks).toHaveBeenCalledWith('AAAAA-11111\nBBBBB-22222');
        await waitFor(() => expect(screen.getByRole('button', { name: /Semua kode tersalin/ })).toBeTruthy());
    });
});

describe('useRahasiaSekali', () => {
    function Perangkai({ awal }: { awal: { Nilai: string } | null }) {
        const [data, AturData] = useState<{ Nilai: string } | null>(awal);
        const rahasia = useRahasiaSekali(data);

        return (
            <>
                <button onClick={() => AturData({ Nilai: 'baru' })}>kirim-baru</button>
                <button onClick={() => AturData(null)}>kirim-kosong</button>
                <button onClick={rahasia.selesai}>selesai</button>
                <output>{rahasia.aktif?.Nilai ?? 'tertutup'}</output>
            </>
        );
    }

    it('terbuka saat server mengirim nilai; tertutup hanya lewat selesai; nilai yang sama tidak membukanya lagi', () => {
        const awal = { Nilai: 'pertama' };
        render(<Perangkai awal={awal} />);
        expect(screen.getByRole('status').textContent).toBe('pertama');

        fireEvent.click(screen.getByText('selesai'));
        expect(screen.getByRole('status').textContent).toBe('tertutup');
    });

    it('respons berikutnya tanpa nilai (flash sudah habis) tidak menutup dialog yang masih terbuka', () => {
        render(<Perangkai awal={{ Nilai: 'pertama' }} />);

        act(() => fireEvent.click(screen.getByText('kirim-kosong')));
        expect(screen.getByRole('status').textContent).toBe('pertama');
    });

    it('nilai baru dari server membuka dialog lagi setelah sebelumnya dikonfirmasi', () => {
        render(<Perangkai awal={{ Nilai: 'pertama' }} />);
        fireEvent.click(screen.getByText('selesai'));
        expect(screen.getByRole('status').textContent).toBe('tertutup');

        fireEvent.click(screen.getByText('kirim-baru'));
        expect(screen.getByRole('status').textContent).toBe('baru');
    });

    it('tanpa nilai dari awal: tertutup', () => {
        render(<Perangkai awal={null} />);
        expect(screen.getByRole('status').textContent).toBe('tertutup');
    });
});
