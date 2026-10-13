import { act, cleanup, fireEvent, render, renderHook, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import CangkangWizard from './CangkangWizard';
import { CariLangkahTerdekat, type LangkahWizard } from './TipeWizard';
import { useAlurWizard } from './useAlurWizard';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

type Kunci = 'A' | 'B' | 'C' | 'Tinjau';
const urutan: Kunci[] = ['A', 'B', 'C', 'Tinjau'];
const Langkah = (kunci: Kunci): LangkahWizard<Kunci> => ({
    Kunci: kunci,
    Label: `Langkah ${kunci}`,
    Keterangan: `Isi ${kunci}`,
});
const semua = urutan.map(Langkah);

afterEach(() => cleanup());

describe('CariLangkahTerdekat', () => {
    it('langkah yang ada dipertahankan; yang hilang mundur ke langkah sebelumnya menurut urutan baku', () => {
        const tanpaC = [Langkah('A'), Langkah('B'), Langkah('Tinjau')];

        expect(CariLangkahTerdekat(tanpaC, 'B', urutan)).toBe('B');
        expect(CariLangkahTerdekat(tanpaC, 'C', urutan)).toBe('B');
        expect(CariLangkahTerdekat([Langkah('B'), Langkah('Tinjau')], 'A', urutan)).toBe('B');
    });
});

describe('useAlurWizard', () => {
    it('Maju memeriksa langkah ini; langkah di depan terkunci sampai dicapai', () => {
        const Periksa = vi.fn((kunci: Kunci) => kunci !== 'B');
        const { result } = renderHook(() => useAlurWizard(semua, Periksa, { awal: 'A', urutan }));

        expect(result.current.aktif).toBe('A');
        expect(result.current.CekTerbuka('B')).toBe(false);
        act(() => {
            expect(result.current.Lompat('C')).toBe(false);
        });
        expect(result.current.aktif).toBe('A');

        act(() => {
            expect(result.current.Maju()).toBe(true);
        });
        expect(result.current.aktif).toBe('B');
        expect(result.current.CekTerbuka('B')).toBe(true);
        expect(result.current.CekTerbuka('C')).toBe(false);

        // B tidak valid: maju ditolak dan fokus diminta.
        act(() => {
            expect(result.current.Maju()).toBe(false);
        });
        expect(result.current.aktif).toBe('B');
        expect(result.current.nomorFokus).toBe(1);
    });

    it('Lompat maju ke langkah yang sudah dicapai memeriksa semua langkah di antaranya dan berhenti di yang tidak valid', () => {
        let bValid = true;
        const { result } = renderHook(() =>
            useAlurWizard(semua, (kunci) => kunci !== 'B' || bValid, { awal: 'A', urutan }),
        );

        act(() => {
            result.current.Maju();
        });
        act(() => {
            result.current.Maju();
        });
        expect(result.current.aktif).toBe('C');

        act(() => result.current.Mundur());
        act(() => result.current.Mundur());
        expect(result.current.aktif).toBe('A');
        bValid = false;
        act(() => {
            expect(result.current.Lompat('C')).toBe(false);
        });
        expect(result.current.aktif).toBe('B');
        expect(result.current.nomorFokus).toBe(1);
    });

    it('Paksa membuka langkah dan membuka semua langkah lain; daftar langkah yang mengecil menggeser langkah aktif', () => {
        const { result, rerender: UlangRender } = renderHook(
            ({ langkah }: { langkah: LangkahWizard<Kunci>[] }) =>
                useAlurWizard(langkah, () => true, { awal: 'A', urutan }),
            { initialProps: { langkah: semua } },
        );

        act(() => result.current.Paksa('C'));
        expect(result.current.aktif).toBe('C');
        expect(result.current.CekTerbuka('Tinjau')).toBe(true);
        expect(result.current.CekTerjangkau('Tinjau')).toBe(true);

        UlangRender({ langkah: [Langkah('A'), Langkah('B'), Langkah('Tinjau')] });
        expect(result.current.aktif).toBe('B');
    });
});

function ContohWizard({ saatSimpan, tahan }: { saatSimpan: () => void; tahan?: () => boolean }) {
    const [nilai, AturNilai] = useState('');
    const kontrol = useAlurWizard(semua, (kunci) => kunci !== 'A' || nilai !== '', { awal: 'A', urutan });

    return (
        <CangkangWizard
            kontrol={kontrol}
            kunciTinjau="Tinjau"
            labelNavigasi="Langkah contoh"
            teksSimpan="Simpan contoh"
            bergalat={nilai === '' ? ['A'] : []}
            pesanGalat={null}
            saatSimpan={saatSimpan}
            memproses={false}
            bolehSimpan
            tautanBatal="/kelola"
            {...(tahan ? { tahan } : {})}
            panel={{
                A: <input aria-label="Nilai A" value={nilai} onChange={(e) => AturNilai(e.target.value)} />,
                B: <p>Isi B</p>,
                C: <p>Isi C</p>,
                Tinjau: <p>Tinjau {nilai}</p>,
            }}
        />
    );
}

describe('CangkangWizard', () => {
    it('penunjuk "Langkah n dari N"; Enter di isian = Lanjut; hanya langkah terakhir yang menyimpan', () => {
        const Simpan = vi.fn();
        render(<ContohWizard saatSimpan={Simpan} />);

        expect(screen.getByText('Langkah 1 dari 4')).toBeTruthy();
        expect(screen.getByRole('navigation', { name: 'Langkah contoh' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Simpan contoh' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Kembali' })).toBeNull();

        // Langkah A tidak valid: Lanjut tertahan.
        fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
        expect(screen.getByText('Langkah 1 dari 4')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Nilai A'), { target: { value: 'x' } });
        fireEvent.submit(screen.getByLabelText('Nilai A').closest('form') as HTMLFormElement);
        expect(screen.getByText('Langkah 2 dari 4')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
        fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
        expect(screen.getByText('Langkah 4 dari 4')).toBeTruthy();
        expect(Simpan).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Simpan contoh' }));
        expect(Simpan).toHaveBeenCalledTimes(1);
    });

    it('langkah di depan terkunci (aria-disabled) dan "Kembali ke tinjauan" muncul setelah tinjauan dicapai', () => {
        render(<ContohWizard saatSimpan={vi.fn()} />);
        fireEvent.change(screen.getByLabelText('Nilai A'), { target: { value: 'x' } });

        const langkahC = screen.getByRole('button', { name: /Langkah C/ });
        expect(langkahC.getAttribute('aria-disabled')).toBe('true');
        expect(screen.queryByRole('button', { name: 'Kembali ke tinjauan' })).toBeNull();

        for (let i = 0; i < 3; i += 1) {
            fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
        }
        fireEvent.click(screen.getByRole('button', { name: 'Kembali' }));
        fireEvent.click(screen.getByRole('button', { name: 'Kembali' }));
        expect(screen.getByText('Langkah 2 dari 4')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Kembali ke tinjauan' }));
        expect(screen.getByText('Tinjau x')).toBeTruthy();
    });

    it('penjaga isian setengah jadi menahan pindah langkah dan menyimpan', () => {
        const Simpan = vi.fn();
        const Tahan = vi.fn(() => true);
        render(<ContohWizard saatSimpan={Simpan} tahan={Tahan} />);
        fireEvent.change(screen.getByLabelText('Nilai A'), { target: { value: 'x' } });

        fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
        expect(Tahan).toHaveBeenCalled();
        expect(screen.getByText('Langkah 1 dari 4')).toBeTruthy();
        expect(Simpan).not.toHaveBeenCalled();
    });
});
