import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import DialogKonfirmasiDuaFaktor from '@/Komponen/Pengelola/DialogKonfirmasiDuaFaktor';

type Pendengar = (peristiwa: unknown) => void;

const uji = vi.hoisted(() => ({
    pendengar: {} as Record<string, Pendengar>,
    kunjungi: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        on: (jenis: string, panggil: Pendengar) => {
            uji.pendengar[jenis] = panggil;

            return () => {
                uji.pendengar = Object.fromEntries(Object.entries(uji.pendengar).filter(([kunci]) => kunci !== jenis));
            };
        },
        visit: uji.kunjungi,
    },
}));

const kunjunganAsal = {
    method: 'post',
    url: new URL('https://konsol.payoung.id/integrasi'),
    data: { Pengaturan: { KanalVa: 'Mandiri|VIRTUAL_ACCOUNT_BANK_MANDIRI|86188|0' } },
    preserveScroll: true,
};

function KirimPeristiwaGalat(status: number, data: unknown) {
    const Pencegah = vi.fn();

    act(() => {
        uji.pendengar.before?.({ detail: { visit: kunjunganAsal } });
        uji.pendengar.httpException?.({
            detail: { response: { status, data, headers: {} } },
            preventDefault: Pencegah,
        });
    });

    return Pencegah;
}

const galatPerluKode = { Galat: { Kode: 'PerluKonfirmasiDuaFaktor', Pesan: 'Masukkan kode.' } };

function JawabFetch(status: number, isi: unknown) {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: status < 400, status, json: () => Promise.resolve(isi) }));
}

describe('DialogKonfirmasiDuaFaktor (D-42)', () => {
    beforeEach(() => {
        uji.kunjungi.mockClear();
    });

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('jawaban 423 membuka dialog kode di tempat dan mencegah dialog masalah bawaan Inertia', () => {
        render(<DialogKonfirmasiDuaFaktor />);

        const Pencegah = KirimPeristiwaGalat(423, galatPerluKode);

        expect(Pencegah).toHaveBeenCalledTimes(1);
        expect(screen.getByText('Konfirmasi dengan kode')).toBeTruthy();
    });

    it('jawaban masalah lain tidak disentuh', () => {
        render(<DialogKonfirmasiDuaFaktor />);

        const Pencegah = KirimPeristiwaGalat(500, { Galat: { Kode: 'ServerBermasalah' } });
        const PencegahKode = KirimPeristiwaGalat(423, { Galat: { Kode: 'TerkunciLain' } });

        expect(Pencegah).not.toHaveBeenCalled();
        expect(PencegahKode).not.toHaveBeenCalled();
        expect(screen.queryByText('Konfirmasi dengan kode')).toBeNull();
    });

    it('isi jawaban berupa teks JSON tetap dikenali', () => {
        render(<DialogKonfirmasiDuaFaktor />);

        const Pencegah = KirimPeristiwaGalat(423, JSON.stringify(galatPerluKode));

        expect(Pencegah).toHaveBeenCalledTimes(1);
    });

    it('kode benar: permintaan yang tadi dikirim ulang dengan data form yang sama', async () => {
        render(<DialogKonfirmasiDuaFaktor />);
        KirimPeristiwaGalat(423, galatPerluKode);
        JawabFetch(200, { Berhasil: true });

        fireEvent.change(screen.getByLabelText(/Kode/), { target: { value: '123456' } });
        fireEvent.click(screen.getByRole('button', { name: 'Konfirmasi dan lanjutkan' }));

        await waitFor(() => {
            expect(uji.kunjungi).toHaveBeenCalledTimes(1);
        });
        const [alamat, opsi] = uji.kunjungi.mock.calls[0] as [URL, typeof kunjunganAsal];

        expect(String(alamat)).toBe('https://konsol.payoung.id/integrasi');
        expect(opsi.method).toBe('post');
        expect(opsi.data).toEqual(kunjunganAsal.data);
        expect(vi.mocked(fetch).mock.calls[0]?.[0]).toBe('/dua-faktor/konfirmasi');
        expect(screen.queryByText('Konfirmasi dengan kode')).toBeNull();
    });

    it('kode salah: pesan tampil di dialog, dialog tetap terbuka, dan permintaan tidak diulang', async () => {
        render(<DialogKonfirmasiDuaFaktor />);
        KirimPeristiwaGalat(423, galatPerluKode);
        JawabFetch(422, { Galat: { Kode: 'KodeSalah', Pesan: 'Kode tidak cocok.' } });

        fireEvent.change(screen.getByLabelText(/Kode/), { target: { value: '000000' } });
        fireEvent.click(screen.getByRole('button', { name: 'Konfirmasi dan lanjutkan' }));

        expect(await screen.findByText('Kode tidak cocok.')).toBeTruthy();
        expect(uji.kunjungi).not.toHaveBeenCalled();
        expect(screen.getByText('Konfirmasi dengan kode')).toBeTruthy();
    });

    it('Batal menutup dialog tanpa mengulang apa pun', () => {
        render(<DialogKonfirmasiDuaFaktor />);
        KirimPeristiwaGalat(423, galatPerluKode);

        fireEvent.click(screen.getByRole('button', { name: 'Batal' }));

        expect(screen.queryByText('Konfirmasi dengan kode')).toBeNull();
        expect(uji.kunjungi).not.toHaveBeenCalled();
    });
});
