import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftar from '@/Halaman/Autentikasi/Daftar';
import HalamanKeamananAkun from '@/Halaman/Autentikasi/KeamananAkun';
import HalamanLengkapiGoogle from '@/Halaman/Autentikasi/LengkapiGoogle';
import HalamanMasuk from '@/Halaman/Autentikasi/Masuk';
import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-57 Masuk dengan Google: tombol di halaman masuk & daftar hanya tampil bila integrasinya aktif, langkah lengkapi
 * pendaftaran tidak meminta email/kata sandi/CAPTCHA, dan Keamanan akun mengelola tautan Google.
 */
beforeEach(() => AturHalamanUji({}, '/masuk'));
afterEach(() => cleanup());

const paket = [{ Kode: 'PRO', Nama: 'Pro', MasaTrialHari: 14 }];

describe('tombol Google di halaman masuk & daftar', () => {
    it('masuk: tombol mengarah ke /masuk/google dan memakai logo, tanpa integrasi tombol tidak ada', () => {
        RenderUji(<HalamanMasuk MasukGoogle />);

        const tautan = screen.getByRole('link', { name: 'Masuk dengan Google' });
        expect(tautan.getAttribute('href')).toBe('/masuk/google');
        expect(tautan.querySelector('img')).not.toBeNull();
        expect(screen.getByRole('separator', { name: 'atau' })).toBeTruthy();
        cleanup();

        RenderUji(<HalamanMasuk />);
        expect(screen.queryByRole('link', { name: 'Masuk dengan Google' })).toBeNull();
    });

    it('daftar: tombol membawa paket yang sedang dipilih', () => {
        RenderUji(<HalamanDaftar Dibuka Paket={paket} PaketTerpilih="PRO" KunciSitusCaptcha={null} MasukGoogle />);

        expect(screen.getByRole('link', { name: 'Daftar dengan Google' }).getAttribute('href')).toBe(
            '/masuk/google?tujuan=daftar&paket=PRO',
        );
    });

    it('daftar tanpa integrasi: hanya formulir biasa', () => {
        RenderUji(<HalamanDaftar Dibuka Paket={paket} PaketTerpilih="PRO" KunciSitusCaptcha={null} />);

        expect(screen.queryByRole('link', { name: 'Daftar dengan Google' })).toBeNull();
        expect(screen.getByLabelText(/Kata sandi/, { selector: 'input' })).toBeTruthy();
    });
});

describe('lengkapi pendaftaran Google', () => {
    it('menampilkan email Google, tanpa kolom email/kata sandi/CAPTCHA. Mengirim ke /daftar/google', () => {
        kirimanForm.length = 0;
        RenderUji(
            <HalamanLengkapiGoogle
                Dibuka
                Akun={{ Nama: 'Sinta Maharani', Email: 'sinta@gmail.com' }}
                Paket={paket}
                PaketTerpilih="PRO"
            />,
        );

        expect(screen.getByText('sinta@gmail.com')).toBeTruthy();
        expect((screen.getByLabelText(/Nama Anda/) as HTMLInputElement).value).toBe('Sinta Maharani');
        expect(screen.queryByLabelText(/Kata sandi/)).toBeNull();
        expect(screen.queryByLabelText(/^Email/)).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Daftar dan mulai trial' }));
        expect(kirimanForm.at(-1)?.url).toBe('/daftar/google');
    });

    it('pendaftaran ditutup: pesan, tanpa formulir', () => {
        RenderUji(
            <HalamanLengkapiGoogle
                Dibuka={false}
                Akun={{ Nama: 'Sinta', Email: 'sinta@gmail.com' }}
                Paket={paket}
                PaketTerpilih="PRO"
            />,
        );

        expect(screen.getByText('Pendaftaran belum dibuka')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Daftar dan mulai trial' })).toBeNull();
    });
});

describe('Keamanan akun: panel Google', () => {
    const akun = {
        Email: 'rina@kopinusantara.id',
        EmailTerverifikasi: true,
        EmailDiverifikasiPada: '2026-10-01T01:00:00Z',
        BisaGantiEmail: true,
    };
    const duaFaktor = { Aktif: false, AktifPada: null, SisaKodePemulihan: 0, Wajib: false };
    const google = {
        Tersedia: true,
        Tertaut: false,
        TertautPada: null,
        KataSandiOtomatis: false,
        MasukDenganGoogle: false,
    };

    it('belum tertaut: tombol tautkan mengarah ke alur tujuan=tautkan', () => {
        AturHalamanUji({}, '/kelola/keamanan');
        RenderUji(
            <HalamanKeamananAkun
                Akun={akun}
                DuaFaktor={duaFaktor}
                Aktivasi={null}
                KodePemulihanBaru={null}
                Google={google}
            />,
        );

        expect(screen.getByText('Status: Belum ditautkan')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Tautkan akun Google' }).getAttribute('href')).toBe(
            '/masuk/google?tujuan=tautkan',
        );
    });

    it('tertaut dengan kata sandi buatan sistem: minta atur kata sandi, tidak ada tombol lepas', () => {
        AturHalamanUji({}, '/kelola/keamanan');
        RenderUji(
            <HalamanKeamananAkun
                Akun={akun}
                DuaFaktor={duaFaktor}
                Aktivasi={null}
                KodePemulihanBaru={null}
                Google={{ ...google, Tertaut: true, TertautPada: '2026-10-05T01:00:00Z', KataSandiOtomatis: true }}
            />,
        );

        expect(screen.getByText('Kata sandi belum diatur')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Atur kata sandi' }).getAttribute('href')).toBe('/ganti-kata-sandi');
        expect(screen.queryByRole('button', { name: 'Lepas tautan Google' })).toBeNull();
    });

    it('tertaut dengan kata sandi sendiri: tombol lepas mengirim DELETE', () => {
        AturHalamanUji({}, '/kelola/keamanan');
        kirimanForm.length = 0;
        RenderUji(
            <HalamanKeamananAkun
                Akun={akun}
                DuaFaktor={duaFaktor}
                Aktivasi={null}
                KodePemulihanBaru={null}
                Google={{ ...google, Tertaut: true, TertautPada: '2026-10-05T01:00:00Z' }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Lepas tautan Google' }));
        expect(kirimanForm.at(-1)).toMatchObject({ metode: 'delete', url: '/kelola/keamanan/google' });
    });

    it('integrasi tidak aktif dan belum tertaut: panel tidak tampil', () => {
        AturHalamanUji({}, '/kelola/keamanan');
        RenderUji(
            <HalamanKeamananAkun
                Akun={akun}
                DuaFaktor={duaFaktor}
                Aktivasi={null}
                KodePemulihanBaru={null}
                Google={{ ...google, Tersedia: false }}
            />,
        );

        expect(screen.queryByText('Masuk dengan Google')).toBeNull();
    });
});
