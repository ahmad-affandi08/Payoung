import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import DialogHasil, { LAMA_DIALOG_BERHASIL_MS } from './DialogHasil';

describe('DialogHasil', () => {
    beforeEach(() => vi.useFakeTimers({ shouldAdvanceTime: true }));
    afterEach(() => {
        cleanup();
        vi.useRealTimers();
    });

    it('tidak menampilkan apa pun tanpa pesan', () => {
        render(<DialogHasil berhasil={null} gagal={undefined} penanda={{}} />);

        expect(screen.queryByRole('alertdialog')).toBeNull();
    });

    it('menampilkan pesan berhasil lalu menutup sendiri', () => {
        render(<DialogHasil berhasil="Produk Kopi disimpan." gagal={undefined} penanda={{}} />);

        const dialog = screen.getByRole('alertdialog');
        expect(dialog.textContent).toContain('Berhasil');
        expect(dialog.textContent).toContain('Produk Kopi disimpan.');
        expect(screen.getByRole('button', { name: 'Oke' })).toBeTruthy();

        act(() => {
            vi.advanceTimersByTime(LAMA_DIALOG_BERHASIL_MS + 10);
        });

        expect(screen.queryByRole('alertdialog')).toBeNull();
    });

    it('pesan gagal menunggu tombol Tutup dan menang atas pesan berhasil', () => {
        render(<DialogHasil berhasil="Disimpan." gagal="Stok tidak cukup." penanda={{}} />);

        const dialog = screen.getByRole('alertdialog');
        expect(dialog.textContent).toContain('Belum berhasil');
        expect(dialog.textContent).toContain('Stok tidak cukup.');

        act(() => {
            vi.advanceTimersByTime(LAMA_DIALOG_BERHASIL_MS * 3);
        });
        expect(screen.getByRole('alertdialog')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Tutup' }));
        expect(screen.queryByRole('alertdialog')).toBeNull();
    });

    it('pesan yang sama muncul lagi bila penandanya berganti', () => {
        const { rerender: RenderUlang } = render(<DialogHasil berhasil="Disimpan." gagal={undefined} penanda={{}} />);

        act(() => {
            vi.advanceTimersByTime(LAMA_DIALOG_BERHASIL_MS + 10);
        });
        expect(screen.queryByRole('alertdialog')).toBeNull();

        RenderUlang(<DialogHasil berhasil="Disimpan." gagal={undefined} penanda={{}} />);

        expect(screen.getByRole('alertdialog')).toBeTruthy();
    });

    it('galat tetap tampil walau ada dialog terbuka yang tidak menampilkan galat sendiri (tidak boleh senyap)', () => {
        render(
            <div>
                <div role="dialog" data-state="open">
                    Tambah lokasi stok
                </div>
                <DialogHasil berhasil={null} gagal="Multi-gudang belum termasuk di paket usaha ini." penanda={{}} />
            </div>,
        );

        expect(screen.getByRole('alertdialog').textContent).toContain(
            'Multi-gudang belum termasuk di paket usaha ini.',
        );
    });

    it('galat tidak ditumpuk bila dialog terbuka sudah menampilkannya sendiri (data-galat-umum)', () => {
        // Dialog sudah terpasang lebih dulu, seperti di aplikasi: pengguna membuka formulir lalu mengirimnya.
        const { rerender: RenderUlang } = render(
            <div>
                <div role="dialog" data-state="open" data-galat-umum="">
                    Tambah voucher
                </div>
                <DialogHasil berhasil={null} gagal={undefined} penanda={{}} />
            </div>,
        );

        RenderUlang(
            <div>
                <div role="dialog" data-state="open" data-galat-umum="">
                    Tambah voucher
                </div>
                <DialogHasil berhasil={null} gagal="Kode voucher sudah dipakai." penanda={{}} />
            </div>,
        );

        expect(screen.queryByRole('alertdialog')).toBeNull();
    });

    it('galat bidang yang tidak terlihat di halaman tampil di dialog', async () => {
        vi.useRealTimers();
        render(
            <DialogHasil
                berhasil={null}
                gagal={undefined}
                penanda={{}}
                galatBidang={{ Jenis: 'Jenis lokasi tidak dikenal.' }}
            />,
        );

        expect((await screen.findByRole('alertdialog')).textContent).toContain('Jenis lokasi tidak dikenal.');
    });

    it('galat bidang yang sudah terlihat di halaman tidak ditumpuk dialog', async () => {
        vi.useRealTimers();
        render(
            <div>
                <p>Nama wajib diisi.</p>
                <DialogHasil
                    berhasil={null}
                    gagal={undefined}
                    penanda={{}}
                    galatBidang={{ Nama: 'Nama wajib diisi.' }}
                />
            </div>,
        );

        await new Promise((selesai) => window.setTimeout(selesai, 40));
        expect(screen.queryByRole('alertdialog')).toBeNull();
    });
});
