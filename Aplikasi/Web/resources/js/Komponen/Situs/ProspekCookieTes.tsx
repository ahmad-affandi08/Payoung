import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { BukaPengaturanCookie, KUNCI_PERSETUJUAN } from '@/Pustaka/AnalitikSitus';
import type { BagianSitus } from '@/Tipe/Situs';

import BagianFormulirProspek from './Bagian/BagianFormulirProspek';
import PersetujuanCookie from './PersetujuanCookie';

const Kirim = vi.fn();
const dataFormulir: Record<string, unknown> = {};
let terkirim = false;

vi.mock('@inertiajs/react', () => ({
    router: { on: () => () => undefined },
    usePage: () => ({ props: { ProspekTerkirim: terkirim }, url: '/kontak?utm=1' }),
    useForm: (awal: Record<string, unknown>) => {
        Object.assign(dataFormulir, awal);

        return {
            data: dataFormulir,
            errors: {},
            processing: false,
            setData: (kunci: string, nilai: unknown) => {
                dataFormulir[kunci] = nilai;
            },
            post: Kirim,
            reset: vi.fn(),
        };
    },
}));

const blokDemo = {
    Jenis: 'FormulirProspek',
    Label: null,
    Judul: 'Minta demo gratis',
    Subjudul: null,
    JenisProspek: 'Demo',
    TeksTombol: null,
} as Extract<BagianSitus, { Jenis: 'FormulirProspek' }>;

beforeEach(() => {
    terkirim = false;
    Kirim.mockReset();
    window.localStorage.clear();
    document.head.querySelectorAll('script').forEach((s) => s.remove());
});

afterEach(cleanup);

describe('Situs bagian B: formulir prospek', () => {
    it('mengirim ke /prospek dengan jenis Demo, halaman asal tanpa query, dan perangkap bot tersembunyi', () => {
        render(<BagianFormulirProspek bagian={blokDemo} latar="latar" />);

        expect(screen.getByRole('heading', { name: 'Minta demo gratis' })).toBeTruthy();
        expect(screen.getByLabelText(/Nomor WhatsApp/)).toBeTruthy();
        expect(
            screen
                .getByText(/kebijakan privasi/i)
                .closest('a')
                ?.getAttribute('href'),
        ).toBe('/legal/kebijakan-privasi');
        expect(dataFormulir.Jenis).toBe('Demo');
        expect(dataFormulir.HalamanAsal).toBe('/kontak');
        expect(document.querySelector('input[name="Situs"]')?.getAttribute('tabindex')).toBe('-1');

        fireEvent.click(screen.getByRole('button', { name: 'Kirim permintaan demo' }));
        expect(Kirim).toHaveBeenCalledWith('/prospek', expect.anything());
    });

    it('setelah terkirim menampilkan ucapan terima kasih, bukan formulir', () => {
        terkirim = true;
        render(<BagianFormulirProspek bagian={{ ...blokDemo, JenisProspek: 'Kontak' }} latar="permukaan" />);

        expect(screen.getByRole('status').textContent).toContain('pesan Anda terkirim');
        expect(screen.queryByRole('button', { name: 'Kirim pesan' })).toBeNull();
    });
});

describe('Situs bagian B: persetujuan cookie', () => {
    const analitik = { IdGoogleAnalytics: 'G-ABC123', IdMetaPixel: null };

    it('tanpa ID analitik tidak menampilkan bilah', () => {
        render(<PersetujuanCookie analitik={{ IdGoogleAnalytics: null, IdMetaPixel: null }} />);
        expect(screen.queryByRole('region', { name: 'Persetujuan cookie' })).toBeNull();
    });

    it('skrip analitik tidak dimuat sebelum Terima; Tolak disimpan dan tidak memuat apa pun', () => {
        render(<PersetujuanCookie analitik={analitik} />);
        expect(document.querySelector('script[src*="googletagmanager"]')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Tolak' }));
        expect(window.localStorage.getItem(KUNCI_PERSETUJUAN)).toBe('tolak');
        expect(screen.queryByRole('region', { name: 'Persetujuan cookie' })).toBeNull();
        expect(document.querySelector('script[src*="googletagmanager"]')).toBeNull();
    });

    it('Terima memuat GA4 dengan ID dari konsol. Pilihan diingat pada kunjungan berikutnya', () => {
        render(<PersetujuanCookie analitik={analitik} />);
        fireEvent.click(screen.getByRole('button', { name: 'Terima' }));

        expect(window.localStorage.getItem(KUNCI_PERSETUJUAN)).toBe('terima');
        expect(document.querySelector('script[src*="googletagmanager"]')?.getAttribute('src')).toContain('G-ABC123');

        cleanup();
        render(<PersetujuanCookie analitik={analitik} />);
        expect(screen.queryByRole('region', { name: 'Persetujuan cookie' })).toBeNull();
    });

    it('audit F-22: "Pengaturan cookie" membuka bilah lagi. Menarik persetujuan menghapus cookie analitik & memuat ulang', () => {
        const MuatUlang = vi.fn();
        vi.stubGlobal('location', { ...window.location, reload: MuatUlang, hostname: 'localhost' });
        window.localStorage.setItem(KUNCI_PERSETUJUAN, 'terima');
        document.cookie = '_ga=GA1.1.123; path=/';
        render(<PersetujuanCookie analitik={analitik} />);
        expect(screen.queryByRole('region', { name: 'Persetujuan cookie' })).toBeNull();

        act(() => BukaPengaturanCookie());
        expect(screen.getByRole('region', { name: 'Persetujuan cookie' }).textContent).toContain(
            'Pilihan Anda saat ini: diterima',
        );

        fireEvent.click(screen.getByRole('button', { name: 'Tolak' }));
        expect(window.localStorage.getItem(KUNCI_PERSETUJUAN)).toBe('tolak');
        expect(document.cookie).not.toContain('_ga=');
        expect(MuatUlang).toHaveBeenCalledOnce();
        vi.unstubAllGlobals();
    });
});
