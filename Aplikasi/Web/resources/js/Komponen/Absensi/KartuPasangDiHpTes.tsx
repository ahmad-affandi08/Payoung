import { act, cleanup, fireEvent, render, renderHook, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import KartuPasangDiHp from '@/Komponen/Absensi/KartuPasangDiHp';
import { JalurHalamanAbsen, usePasangPwa } from '@/Fitur/Absensi/usePasangPwa';

/*
 * F-18 bagian 4 (D-211): halaman Absen bisa dipasang ke layar utama HP. Android/desktop memakai jendela pasang
 * peramban lewat tombol; iPhone/iPad mendapat petunjuk Bagikan; yang sudah terpasang tidak melihat apa-apa.
 */
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('Kartu Pasang di HP', () => {
    it('tombol Pasang di HP muncul bila peramban menawarkan pemasangan dan memanggil prompt-nya', async () => {
        const Prompt = vi.fn(() => Promise.resolve());
        const { result } = renderHook(() => usePasangPwa('/kelola/absen'));
        expect(result.current.bisaPasang).toBe(false);

        act(() => {
            const peristiwa = new Event('beforeinstallprompt', { cancelable: true });
            Object.assign(peristiwa, { prompt: Prompt, userChoice: Promise.resolve({ outcome: 'accepted' }) });
            window.dispatchEvent(peristiwa);
        });
        expect(result.current.bisaPasang).toBe(true);

        render(<KartuPasangDiHp keadaan={result.current} />);
        fireEvent.click(screen.getByRole('button', { name: 'Pasang di HP' }));
        await act(async () => Promise.resolve());

        expect(Prompt).toHaveBeenCalledTimes(1);
        expect(result.current.terpasang).toBe(true);
        expect(result.current.bisaPasang).toBe(false);
    });

    it('iPhone tanpa jendela pasang mendapat petunjuk Bagikan. Sudah terpasang tidak menampilkan apa pun', () => {
        vi.stubGlobal('navigator', {
            ...navigator,
            userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
        });
        const { result } = renderHook(() => usePasangPwa('/kelola/absen'));
        expect(result.current.petunjukIos).toBe(true);

        render(<KartuPasangDiHp keadaan={result.current} />);
        expect(screen.getByText(/Tambahkan ke Layar Utama/)).toBeTruthy();
        cleanup();

        const { container } = render(
            <KartuPasangDiHp keadaan={{ ...result.current, terpasang: true, petunjukIos: false }} />,
        );
        expect(container.textContent).toBe('');
    });

    it('jalur halaman diturunkan dari alamat dasar, termasuk alamat mutlak tautan pribadi', () => {
        expect(JalurHalamanAbsen('/kelola/absen')).toBe('/kelola/absen');
        expect(JalurHalamanAbsen('https://dashboard.payoung.id/toko-a/absen/abc/')).toBe('/toko-a/absen/abc');
    });
});
