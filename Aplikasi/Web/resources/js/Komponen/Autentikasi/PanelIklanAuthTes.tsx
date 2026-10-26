import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanMasuk from '@/Halaman/Autentikasi/Masuk';
import { AturHalamanUji, RenderUji, TambahPropsHalamanUji } from '@/Komponen/Katalog/TiruanInertia';
import type { PenawaranAuth } from '@/Tipe/Aplikasi';

import PanelIklanAuth, { SELANG_IKLAN_MS, SusunIklan } from './PanelIklanAuth';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-87: halaman masuk & daftar back-office dua kolom dengan panel iklan. Iklan harga memakai data katalog asli:
 * diskon hanya tampil bila server mengirim `Promo` (harga normal yang lebih mahal memang terjadwal).
 */
const penawaranPromo: PenawaranAuth = {
    NamaPaket: 'Starter',
    HargaBulanan: '59000.00',
    MasaTrialHari: 14,
    Promo: {
        HargaBulananNormal: '99000.00',
        HargaTahunanNormal: '950400.00',
        BerlakuSampai: '2027-01-31',
        PersenDiskon: 40,
        HargaTerkunci: true,
    },
    Paket: [
        { Nama: 'Starter', HargaBulanan: '59000.00', HargaNormal: '99000.00', Sorotan: ['1 outlet', '2 pengguna'] },
        { Nama: 'Pro', HargaBulanan: '149000.00', HargaNormal: '249000.00', Sorotan: ['3 outlet'] },
        { Nama: 'Bisnis', HargaBulanan: '299000.00', HargaNormal: null, Sorotan: [] },
    ],
};

function PromoUji(): NonNullable<PenawaranAuth['Promo']> {
    if (penawaranPromo.Promo === null) {
        throw new Error('Penawaran uji harus punya promo.');
    }

    return penawaranPromo.Promo;
}

beforeEach(() => {
    vi.useFakeTimers();
    AturHalamanUji({}, '/masuk');
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

describe('SusunIklan', () => {
    it('promo: iklan pertama menyebut harga, diskon, tanggal akhir, dan kunci harga dari data server', () => {
        const iklan = SusunIklan(penawaranPromo, false);

        expect(iklan[0]).toMatchObject({
            kunci: 'promo',
            label: 'Diskon peluncuran 40%',
            judul: 'Mulai Rp 59.000 per bulan',
        });
        expect(iklan[0]?.teks).toContain('Harga normal Rp 99.000');
        expect(iklan[0]?.teks).toContain('31 Jan 2027');
        expect(iklan[0]?.teks).toContain('tetap di harga ini');
        expect(iklan[0]?.sorot?.[0]).toBe('Coba gratis 14 hari, tanpa kartu kredit');
        expect(iklan.map((butir) => butir.kunci)).toEqual(['promo', 'paket', 'offline', 'pantau', 'lengkap']);
    });

    it('iklan paket memuat nama, harga, harga normal, dan batas apa adanya dari data, tanpa klaim tulisan tangan', () => {
        const paket = SusunIklan(penawaranPromo, false).find((butir) => butir.kunci === 'paket');

        expect(paket?.judul).toBe('Paket Starter, Pro dan Bisnis');
        expect(paket?.sorot).toEqual([
            'Starter: Rp 59.000/bulan (normal Rp 99.000) | 1 outlet, 2 pengguna',
            'Pro: Rp 149.000/bulan (normal Rp 249.000) | 3 outlet',
            'Bisnis: Rp 299.000/bulan',
        ]);
        expect(JSON.stringify(SusunIklan(penawaranPromo, false))).not.toContain('beberapa outlet');
    });

    it('satu paket saja tidak menampilkan iklan daftar paket', () => {
        const satu = SusunIklan(
            {
                ...penawaranPromo,
                Paket: [penawaranPromo.Paket[0] ?? { Nama: 'A', HargaBulanan: '1', HargaNormal: null, Sorotan: [] }],
            },
            false,
        );

        expect(satu.map((butir) => butir.kunci)).not.toContain('paket');
    });

    it('tanpa kunci harga janji "tetap di harga ini" tidak muncul, tanpa promo tidak ada diskon', () => {
        const tanpaKunci = SusunIklan({ ...penawaranPromo, Promo: { ...PromoUji(), HargaTerkunci: false } }, false);
        expect(tanpaKunci[0]?.teks).not.toContain('tetap di harga ini');

        const tanpaPromo = SusunIklan({ ...penawaranPromo, Promo: null }, false);
        expect(tanpaPromo[0]?.kunci).toBe('harga');
        expect(JSON.stringify(tanpaPromo[0])).not.toContain('Diskon');
    });

    it('tanpa penawaran (tidak ada paket berbayar) tidak menyebut harga. Edisi Lisensi tidak ada iklan harga sama sekali', () => {
        expect(SusunIklan(null, false)[0]?.kunci).toBe('gratis');
        expect(SusunIklan(penawaranPromo, true).map((butir) => butir.kunci)).toEqual(['offline', 'pantau', 'lengkap']);
    });
});

describe('PanelIklanAuth (carousel)', () => {
    it('berganti otomatis tiap selang, berhenti saat disorot, dan bisa dipilih manual', () => {
        render(<PanelIklanAuth nama="Payoung" penawaran={penawaranPromo} lisensi={false} />);

        const SlideAktif = () => screen.getAllByRole('group').find((el) => el.getAttribute('aria-hidden') === null);
        expect(SlideAktif()?.textContent).toContain('Diskon peluncuran 40%');

        act(() => vi.advanceTimersByTime(SELANG_IKLAN_MS));
        expect(SlideAktif()?.textContent).toContain('Paket Starter, Pro dan Bisnis');

        // Disorot mouse: tidak lagi berganti.
        fireEvent.mouseEnter(screen.getByRole('region', { name: 'Keunggulan Payoung' }).parentElement as HTMLElement);
        act(() => vi.advanceTimersByTime(SELANG_IKLAN_MS * 3));
        expect(SlideAktif()?.textContent).toContain('Paket Starter, Pro dan Bisnis');

        fireEvent.click(screen.getByRole('button', { name: /Iklan 4: Pantau dari HP/ }));
        expect(SlideAktif()?.textContent).toContain('Omzet, stok, dan shift');
        fireEvent.click(screen.getByRole('button', { name: 'Iklan berikutnya' }));
        expect(SlideAktif()?.textContent).toContain('Stok, akuntansi, promo');
        fireEvent.click(screen.getByRole('button', { name: 'Iklan berikutnya' }));
        expect(SlideAktif()?.textContent).toContain('Diskon peluncuran 40%');
    });

    it('pengguna yang meminta gerakan dikurangi tidak mengalami pergantian otomatis', () => {
        const MatchMediaAsli = window.matchMedia;
        window.matchMedia = ((kueri: string) => ({
            matches: kueri.includes('prefers-reduced-motion'),
            media: kueri,
            onchange: null,
            addEventListener: () => undefined,
            removeEventListener: () => undefined,
            addListener: () => undefined,
            removeListener: () => undefined,
            dispatchEvent: () => false,
        })) as typeof window.matchMedia;

        render(<PanelIklanAuth nama="Payoung" penawaran={penawaranPromo} lisensi={false} />);
        act(() => vi.advanceTimersByTime(SELANG_IKLAN_MS * 3));

        const aktif = screen.getAllByRole('group').find((el) => el.getAttribute('aria-hidden') === null);
        expect(aktif?.textContent).toContain('Diskon peluncuran 40%');
        window.matchMedia = MatchMediaAsli;
    });
});

describe('halaman masuk dengan panel iklan', () => {
    it('menampilkan panel iklan dan pita penawaran ringkas untuk layar kecil, lengkap dengan logo dan tautan legal', () => {
        TambahPropsHalamanUji({ Penawaran: penawaranPromo });
        RenderUji(<HalamanMasuk />);

        expect(screen.getByRole('complementary', { name: 'Keunggulan Payoung' })).toBeTruthy();
        expect(screen.getByText('Diskon peluncuran 40% | mulai Rp 59.000/bulan')).toBeTruthy();
        expect(screen.getByRole('heading', { level: 1, name: 'Masuk' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Syarat & Ketentuan' }).getAttribute('href')).toBe(
            '/legal/syarat-ketentuan',
        );
        expect(screen.getByRole('link', { name: 'Lupa kata sandi?' })).toBeTruthy();
    });

    it('edisi Lisensi: tidak ada pita harga dan tidak ada iklan harga', () => {
        TambahPropsHalamanUji({ Penawaran: penawaranPromo, Edisi: 'Lisensi' });
        RenderUji(<HalamanMasuk />);

        expect(screen.queryByText(/Diskon peluncuran/)).toBeNull();
        expect(screen.queryByRole('link', { name: 'Daftar gratis' })).toBeNull();
    });
});
