import { act, cleanup, fireEvent, render, renderHook, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import BidangTeksKaya from '@/Komponen/Formulir/BidangTeksKaya';
import DialogTambahBlok from './DialogTambahBlok';
import PanelRiwayat, { type RevisiHalaman } from './PanelRiwayat';
import { RingkasBlok } from './DaftarBlok';
import { BerinyaIdBlok, BuatMuatan, KunciDraf, type Draf } from './Tipe';
import { useRiwayat } from './useRiwayat';

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

const draf: Draf = {
    Slug: 'promo',
    Judul: 'Promo',
    JudulSeo: '',
    DeskripsiSeo: '',
    UuidGambarOg: null,
    TampilDiSitemap: true,
    Bagian: BerinyaIdBlok([{ Jenis: 'Hero', Judul: 'Halo' }]),
};

describe('Editor visual situs D-63', () => {
    it('muatan ke server tidak membawa penanda _id editor', () => {
        const muatan = BuatMuatan(draf);

        expect(muatan.Bagian).toEqual([{ Jenis: 'Hero', Judul: 'Halo' }]);
        expect(KunciDraf(draf)).not.toContain('_id');
        expect(draf.Bagian[0]?._id).toBeTruthy();
    });

    it('urungkan & ulangi, dan ketikan beruntun digabung menjadi satu langkah', () => {
        vi.useFakeTimers();
        const { result } = renderHook(() => useRiwayat('a'));

        act(() => result.current.ubah('b'));
        act(() => result.current.ubah('bc', true));
        act(() => result.current.ubah('bcd', true));
        expect(result.current.nilai).toBe('bcd');

        act(() => result.current.urungkan());
        expect(result.current.nilai).toBe('a');
        expect(result.current.bisaUrungkan).toBe(false);
        expect(result.current.bisaUlangi).toBe(true);

        act(() => result.current.ulangi());
        expect(result.current.nilai).toBe('bcd');
    });

    it('perubahan baru setelah urungkan membuang riwayat ulangi', () => {
        const { result } = renderHook(() => useRiwayat(1));

        act(() => result.current.ubah(2));
        act(() => result.current.urungkan());
        act(() => result.current.ubah(3));

        expect(result.current.bisaUlangi).toBe(false);
        expect(result.current.nilai).toBe(3);
    });

    it('ringkasan blok memakai judul, label, atau judul item pertama', () => {
        expect(RingkasBlok({ Jenis: 'Hero', Judul: ' Kasir ' })).toBe('Kasir');
        expect(RingkasBlok({ Jenis: 'Faq', Item: [{ Pertanyaan: 'Bisa offline?' }] })).toBe('Bisa offline?');
        expect(RingkasBlok({ Jenis: 'Pemisah' })).toBe('');
    });

    it('galeri tambah blok mengelompokkan jenis dan memilih saat kartu diklik', () => {
        const SaatPilih = vi.fn();

        render(
            <DialogTambahBlok
                terbuka
                saatTutup={() => undefined}
                jenis={['Hero', 'Faq']}
                label={{ Hero: 'Hero', Faq: 'Tanya jawab' }}
                saatPilih={SaatPilih}
                keterangan="Pilih blok."
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /Tanya jawab/ }));
        expect(SaatPilih).toHaveBeenCalledWith('Faq');
    });
});

describe('Riwayat versi & jadwal terbit D-63', () => {
    const revisi: RevisiHalaman[] = [
        {
            Uuid: 'R1',
            Jenis: 'Terbit',
            Judul: 'Promo',
            JumlahBlok: 3,
            DibuatPada: '2026-10-06T03:00:00Z',
            Pembuat: 'Rina',
        },
    ];

    it('menampilkan revisi dan memulihkan lewat tombol Pulihkan', () => {
        const SaatPulihkan = vi.fn();

        render(
            <PanelRiwayat
                terbuka
                saatTutup={() => undefined}
                revisi={revisi}
                bolehUbah
                memproses={false}
                saatPulihkan={SaatPulihkan}
            />,
        );

        expect(screen.getByText(/Saat diterbitkan/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Pulihkan' }));
        expect(SaatPulihkan).toHaveBeenCalledWith(revisi[0]);
    });

    it('tanpa izin kelola tidak ada tombol pulihkan. Kosong menjelaskan kapan versi pertama tersimpan', () => {
        const { rerender: RenderUlang } = render(
            <PanelRiwayat
                terbuka
                saatTutup={() => undefined}
                revisi={revisi}
                bolehUbah={false}
                memproses={false}
                saatPulihkan={() => undefined}
            />,
        );
        expect(screen.queryByRole('button', { name: 'Pulihkan' })).toBeNull();

        RenderUlang(
            <PanelRiwayat
                terbuka
                saatTutup={() => undefined}
                revisi={[]}
                bolehUbah
                memproses={false}
                saatPulihkan={() => undefined}
            />,
        );
        expect(screen.getByText(/Versi pertama tersimpan saat halaman ini diterbitkan/)).toBeTruthy();
    });
});

describe('Bilah alat teks kaya D-63', () => {
    it('tombol Tebal membungkus pilihan dengan penanda', () => {
        const SaatUbah = vi.fn();

        render(<BidangTeksKaya label="Isi" nilai="halo dunia" saatBerubah={SaatUbah} />);
        const kolom = screen.getByLabelText('Isi') as HTMLTextAreaElement;
        kolom.setSelectionRange(5, 10);
        fireEvent.click(screen.getByRole('button', { name: 'Tebal' }));

        expect(SaatUbah).toHaveBeenCalledWith('halo **dunia**');
    });
});
