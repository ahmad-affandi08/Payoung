import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { BukaPilihan } from '@/Pengujian/InteraksiPilihan';

import DaftarTab from './DaftarTab';
import GrupRadio from './GrupRadio';
import LangkahImpor from './LangkahImpor';
import PemilihProduk from './PemilihProduk';
import SakelarPadat, { usePadatTabel } from './SakelarPadat';
import { RenderUji } from './TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('./TiruanInertia')).TiruanInertia);

/*
 * Perilaku komponen Katalog setelah migrasi ke shadcn/ui (Tabs, RadioGroup, Toggle, Command/Popover, Progress):
 * kontrak ARIA dan interaksi keyboard lama harus tetap berlaku.
 */

function TabUji() {
    const [aktif, AturAktif] = useState<'Umum' | 'Harga' | 'Pajak'>('Umum');

    return (
        <DaftarTab
            label="Bagian formulir"
            tab={[
                { Kunci: 'Umum', Label: 'Umum' },
                { Kunci: 'Harga', Label: 'Harga', AdaGalat: true },
                { Kunci: 'Pajak', Label: 'Pajak' },
            ]}
            aktif={aktif}
            saatPilih={AturAktif}
            panel={{ Umum: <p>Isi umum</p>, Harga: <p>Isi harga</p>, Pajak: <p>Isi pajak</p> }}
        />
    );
}

describe('DaftarTab (Tabs)', () => {
    afterEach(() => cleanup());

    it('pola ARIA tabs: panah kanan pindah tab, panel lain tetap terpasang tetapi tersembunyi', async () => {
        render(<TabUji />);
        const umum = screen.getByRole('tab', { name: 'Umum' });

        expect(screen.getByRole('tablist', { name: 'Bagian formulir' })).toBeTruthy();
        expect(umum.getAttribute('aria-selected')).toBe('true');
        expect(screen.getByRole('tab', { name: /Harga.*perlu diperbaiki/ })).toBeTruthy();
        expect(screen.getByText('Isi harga').closest('[role="tabpanel"]')?.hasAttribute('hidden')).toBe(true);

        umum.focus();
        fireEvent.keyDown(umum, { key: 'ArrowRight' });
        // Radix memindahkan fokus (dan memilih tab) pada tick berikutnya.
        await waitFor(() =>
            expect(screen.getByRole('tab', { name: /Harga/ }).getAttribute('aria-selected')).toBe('true'),
        );
        expect(document.activeElement).toBe(screen.getByRole('tab', { name: /Harga/ }));
        expect(screen.getByText('Isi umum').closest('[role="tabpanel"]')?.hasAttribute('hidden')).toBe(true);

        fireEvent.click(screen.getByRole('tab', { name: 'Pajak' }));
        expect(screen.getByRole('tabpanel', { name: 'Pajak' }).textContent).toBe('Isi pajak');
    });
});

describe('GrupRadio (RadioGroup)', () => {
    afterEach(() => cleanup());

    it('radio berlabel, memilih lewat label, dan nonaktif bila disabled', () => {
        const SaatBerubah = vi.fn();
        const opsi = [
            { Nilai: 'Ikut', Label: 'Ikuti pengaturan outlet' },
            { Nilai: 'Ya', Label: 'Ya', Keterangan: 'Harga jual sudah termasuk pajak' },
        ];
        const { rerender: RenderUlang } = render(
            <GrupRadio legenda="Harga termasuk pajak?" nilai="Ikut" opsi={opsi} saatBerubah={SaatBerubah} />,
        );

        expect(screen.getByRole('group', { name: 'Harga termasuk pajak?' })).toBeTruthy();
        expect(screen.getByRole('radio', { name: 'Ikuti pengaturan outlet' }).getAttribute('aria-checked')).toBe(
            'true',
        );
        fireEvent.click(screen.getByLabelText(/Harga jual sudah termasuk pajak/));
        expect(SaatBerubah).toHaveBeenCalledWith('Ya');

        RenderUlang(
            <GrupRadio legenda="Harga termasuk pajak?" nilai="Ikut" opsi={opsi} saatBerubah={SaatBerubah} disabled />,
        );
        expect(screen.getByRole<HTMLButtonElement>('radio', { name: /Ya/ }).disabled).toBe(true);
    });
});

function PadatUji() {
    const [padat, AturPadat] = usePadatTabel('Uji');

    return <SakelarPadat padat={padat} saatBerubah={AturPadat} />;
}

describe('SakelarPadat (Toggle)', () => {
    afterEach(() => {
        cleanup();
        window.localStorage.clear();
    });

    it('aria-pressed dan teks mengikuti keadaan. Preferensi disimpan per peramban', () => {
        render(<PadatUji />);
        const sakelar = screen.getByRole('button', { name: 'Tampilan padat: nonaktif' });

        expect(sakelar.getAttribute('aria-pressed')).toBe('false');
        fireEvent.click(sakelar);
        expect(screen.getByRole('button', { name: 'Tampilan padat: aktif' }).getAttribute('aria-pressed')).toBe('true');
        expect(window.localStorage.getItem('Katalog.Padat.Uji')).toBe('1');
    });
});

describe('LangkahImpor (stepper)', () => {
    afterEach(() => cleanup());

    it('langkah aktif ditandai aria-current dan teks. Batang progres hanya visual', () => {
        const { container } = render(<LangkahImpor status="Pratinjau" />);
        const aktif = screen.getByRole('list', { name: 'Langkah impor produk' }).querySelector('[aria-current="step"]');

        expect(aktif?.textContent).toContain('Pratinjau');
        expect(aktif?.textContent).toContain('(langkah saat ini)');
        expect(screen.queryByRole('progressbar')).toBeNull();
        expect(container.querySelector('[data-slot="progress"]')?.getAttribute('aria-hidden')).toBe('true');
    });
});

describe('PemilihProduk (Command + Popover)', () => {
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    const hasil = {
        Data: [
            {
                Uuid: 'P-GULA',
                Nama: 'Gula pasir',
                Sku: null,
                Jenis: 'BahanBaku',
                UuidSatuanDasar: 'SAT-G',
                Satuan: [{ Uuid: 'SAT-G', Nama: 'Gram', Simbol: 'g', BolehDesimal: true, KonversiKeDasar: '1.0000' }],
            },
            {
                Uuid: 'P-GULA-AREN',
                Nama: 'Gula aren',
                Sku: 'PRD-000077',
                Jenis: 'BahanBaku',
                UuidSatuanDasar: 'SAT-G',
                Satuan: [],
            },
        ],
    };

    it('panah bawah menyorot opsi berikutnya (aria-activedescendant) dan klik memilih produk', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve(hasil) }));
        const SaatPilih = vi.fn();
        RenderUji(<PemilihProduk label="Cari bahan" jenis={['BahanBaku']} saatPilih={SaatPilih} />);
        const pemicu = screen.getByRole('combobox', { name: 'Cari bahan' });

        // Pola PilihanCari: pemicunya tombol, sorotan keyboard dipegang cmdk di dalam popover.
        expect(pemicu.getAttribute('aria-expanded')).toBe('false');
        BukaPilihan(pemicu);
        expect(pemicu.getAttribute('aria-expanded')).toBe('true');

        const cari = screen.getByPlaceholderText('Cari nama, SKU, atau barcode');
        fireEvent.change(cari, { target: { value: 'gula' } });

        // Opsi pertama tersorot sendiri, jadi Enter langsung memilih tanpa menekan panah dulu.
        await waitFor(() =>
            expect(screen.getByRole('option', { name: /Gula pasir/ }).getAttribute('aria-selected')).toBe('true'),
        );

        fireEvent.keyDown(cari, { key: 'ArrowDown' });
        await waitFor(() =>
            expect(screen.getByRole('option', { name: /Gula aren/ }).getAttribute('aria-selected')).toBe('true'),
        );

        fireEvent.click(screen.getByRole('option', { name: /Gula pasir/ }));
        expect(SaatPilih).toHaveBeenCalledWith(expect.objectContaining({ Uuid: 'P-GULA' }));
        // Daftar menutup setelah memilih, siap untuk baris berikutnya.
        await waitFor(() => expect(pemicu.getAttribute('aria-expanded')).toBe('false'));
    });

    it('Enter tanpa daftar terbuka tidak ditahan (formulir induk tetap bisa dikirim); Home/End tetap milik input', () => {
        RenderUji(<PemilihProduk label="Cari komponen" jenis={['Stok']} saatPilih={vi.fn()} />);
        const input = screen.getByRole('combobox', { name: 'Cari komponen' });

        expect(fireEvent.keyDown(input, { key: 'Enter' })).toBe(true);
        expect(fireEvent.keyDown(input, { key: 'Home' })).toBe(true);
        expect(fireEvent.keyDown(input, { key: 'End' })).toBe(true);
    });
});
