import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import GeserAbsen from '@/Komponen/Absensi/GeserAbsen';
import IlustrasiAbsen, { type KeadaanIlustrasiAbsen } from '@/Komponen/Absensi/IlustrasiAbsen';

/*
 * D-337: absen pulang digeser supaya tidak tertekan tidak sengaja. Ketukan biasa tidak melakukan apa pun, geser sampai
 * ujung (dan hanya itu) atau Enter/Spasi pada pegangan yang melakukan absen. Jalur geser 300px, pegangan 56px.
 */
function Siapkan(sisipan: Partial<Parameters<typeof GeserAbsen>[0]> = {}) {
    const SaatSelesai = vi.fn();
    render(
        <GeserAbsen
            nama="Absen pulang"
            petunjuk="Geser untuk absen pulang"
            disabled={false}
            sibuk={false}
            langkah={null}
            saatSelesai={SaatSelesai}
            {...sisipan}
        />,
    );
    const pegangan = screen.getByRole('button', { name: 'Absen pulang' });
    // jsdom tidak menghitung tata letak, jadi lebar jalur ditetapkan: 300px - 56px pegangan - 8px celah = 236px.
    Object.defineProperty(pegangan.parentElement as HTMLElement, 'clientWidth', { value: 300, configurable: true });
    pegangan.setPointerCapture = vi.fn();

    return { pegangan, SaatSelesai };
}

function Geser(pegangan: HTMLElement, sejauh: number) {
    fireEvent.pointerDown(pegangan, { pointerId: 1, clientX: 0 });
    fireEvent.pointerMove(pegangan, { pointerId: 1, clientX: sejauh });
    fireEvent.pointerUp(pegangan, { pointerId: 1, clientX: sejauh });
}

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

describe('GeserAbsen', () => {
    it('digeser sampai ujung: absen berjalan', () => {
        const { pegangan, SaatSelesai } = Siapkan();

        Geser(pegangan, 250);

        expect(SaatSelesai).toHaveBeenCalledTimes(1);
    });

    it('digeser separuh jalan lalu dilepas: kembali ke awal dan tidak absen', () => {
        const { pegangan, SaatSelesai } = Siapkan();

        Geser(pegangan, 100);

        expect(SaatSelesai).not.toHaveBeenCalled();
        expect(pegangan.style.transform).toBe('translateX(0px)');
    });

    it('ketukan biasa tidak melakukan apa pun', () => {
        const { pegangan, SaatSelesai } = Siapkan();

        fireEvent.click(pegangan);
        fireEvent.pointerDown(pegangan, { pointerId: 1, clientX: 0 });
        fireEvent.pointerUp(pegangan, { pointerId: 1, clientX: 0 });

        expect(SaatSelesai).not.toHaveBeenCalled();
    });

    it('papan ketik: Enter atau Spasi pada pegangan melakukan absen', () => {
        const { pegangan, SaatSelesai } = Siapkan();

        fireEvent.keyDown(pegangan, { key: 'Enter' });
        fireEvent.keyDown(pegangan, { key: ' ' });
        fireEvent.keyDown(pegangan, { key: 'a' });

        expect(SaatSelesai).toHaveBeenCalledTimes(2);
    });

    it('nonaktif atau sibuk: tidak bisa digeser maupun ditekan, dan teks langkah menggantikan petunjuk', () => {
        const { pegangan, SaatSelesai } = Siapkan({ sibuk: true, langkah: 'Mengirim absen…' });

        Geser(pegangan, 250);
        fireEvent.keyDown(pegangan, { key: 'Enter' });

        expect(SaatSelesai).not.toHaveBeenCalled();
        expect((pegangan as HTMLButtonElement).disabled).toBe(true);
        expect(screen.getByText('Mengirim absen…')).not.toBeNull();
        expect(screen.queryByText('Geser untuk absen pulang')).toBeNull();
    });
});

describe('IlustrasiAbsen', () => {
    it.each<KeadaanIlustrasiAbsen>(['belum', 'bekerja', 'selesai', 'wajah'])(
        'keadaan %s: gambar hiasan yang disembunyikan dari pembaca layar',
        (keadaan) => {
            const { container } = render(<IlustrasiAbsen keadaan={keadaan} />);
            const gambar = container.querySelector('svg');

            expect(gambar?.getAttribute('aria-hidden')).toBe('true');
            expect(gambar?.querySelectorAll('rect, circle, path, ellipse, line').length).toBeGreaterThan(5);
        },
    );

    it('tanpa gradien dan tanpa warna lepas: hanya kelas token', () => {
        const { container } = render(<IlustrasiAbsen keadaan="bekerja" />);

        expect(container.innerHTML).not.toMatch(/gradient|#[0-9a-f]{3,6}\b|rgb\(/i);
    });
});
