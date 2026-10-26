import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanKeamananAkun from '@/Halaman/Autentikasi/KeamananAkun';
import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * BR-00.5: Keamanan akun satu halaman terpandu: ringkasan status, lalu panel email (verifikasi + ganti email),
 * kata sandi, Google, verifikasi dua langkah, dan PIN kasir.
 */
beforeEach(() => AturHalamanUji({}, '/kelola/keamanan'));
afterEach(() => cleanup());

const akun: ComponentProps<typeof HalamanKeamananAkun>['Akun'] = {
    Email: 'rina@kopinusantara.id',
    EmailTerverifikasi: true,
    EmailDiverifikasiPada: '2026-10-01T01:00:00Z',
    BisaGantiEmail: true,
};
const duaFaktor = { Aktif: false, AktifPada: null, SisaKodePemulihan: 0, Wajib: false };
const google = {
    Tersedia: false,
    Tertaut: false,
    TertautPada: null,
    KataSandiOtomatis: false,
    MasukDenganGoogle: false,
};

function Render(ubah: Partial<{ akun: typeof akun; duaFaktor: typeof duaFaktor; google: typeof google }> = {}) {
    return RenderUji(
        <HalamanKeamananAkun
            Akun={ubah.akun ?? akun}
            DuaFaktor={ubah.duaFaktor ?? duaFaktor}
            Aktivasi={null}
            KodePemulihanBaru={null}
            Google={ubah.google ?? google}
        />,
    );
}

describe('ringkasan keamanan', () => {
    it('menghitung pengaman yang beres dan menautkan tiap baris ke panelnya', () => {
        Render();

        const ringkasan = screen.getByRole('navigation', { name: 'Ringkasan keamanan' });
        // Email terverifikasi + kata sandi diatur = 2 dari 3 (email, kata sandi, dua langkah); PIN kasir opsional.
        expect(within(ringkasan).getByText('2 dari 3 pengaman sudah beres.')).toBeTruthy();
        expect(
            within(ringkasan)
                .getByRole('link', { name: /Email akun/ })
                .getAttribute('href'),
        ).toBe('#email');
        expect(
            within(ringkasan)
                .getByRole('link', { name: /Verifikasi dua langkah/ })
                .getAttribute('href'),
        ).toBe('#dua-langkah');
        expect(within(ringkasan).queryByRole('link', { name: /Akun Google/ })).toBeNull();
    });

    it('email belum terverifikasi dan 2FA wajib terlihat sebagai peringatan', () => {
        Render({
            akun: { ...akun, EmailTerverifikasi: false, EmailDiverifikasiPada: null },
            duaFaktor: { ...duaFaktor, Wajib: true },
        });

        const ringkasan = screen.getByRole('navigation', { name: 'Ringkasan keamanan' });
        expect(within(ringkasan).getByText('Belum terverifikasi')).toBeTruthy();
        expect(within(ringkasan).getByText('Wajib diaktifkan')).toBeTruthy();
        expect(within(ringkasan).getByText('1 dari 3 pengaman sudah beres.')).toBeTruthy();
    });
});

describe('panel email akun', () => {
    it('terverifikasi: tidak ada peringatan. Ganti email mengirim email baru + kata sandi', () => {
        Render();

        expect(screen.queryByText('Email belum terverifikasi')).toBeNull();
        fireEvent.change(screen.getByLabelText(/Email baru/), { target: { value: 'baru@kopinusantara.id' } });
        fireEvent.change(screen.getByLabelText('Kata sandi saat ini', { selector: 'input' }), {
            target: { value: 'kata-sandi-kuat-123' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Kirim tautan konfirmasi' }));

        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'post',
            url: '/kelola/keamanan/email',
            data: { Email: 'baru@kopinusantara.id', KataSandi: 'kata-sandi-kuat-123' },
        });
    });

    it('belum terverifikasi: tawarkan kirim ulang tautan dan petunjuk ganti email', () => {
        Render({ akun: { ...akun, EmailTerverifikasi: false, EmailDiverifikasiPada: null } });

        expect(screen.getByText('Email belum terverifikasi')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Kirim ulang tautan' }));
        expect(kirimanForm.at(-1)).toMatchObject({ metode: 'post', url: '/verifikasi-email/kirim-ulang' });
    });

    it('akun buatan Google tanpa kata sandi: minta atur kata sandi, tanpa formulir ganti email', () => {
        Render({ akun: { ...akun, BisaGantiEmail: false } });

        expect(screen.queryByRole('button', { name: 'Kirim tautan konfirmasi' })).toBeNull();
        expect(screen.getByRole('link', { name: 'Atur kata sandi dulu' }).getAttribute('href')).toBe(
            '/ganti-kata-sandi',
        );
    });

    it('masalah dari server tampil di bidangnya', () => {
        AturHalamanUji(
            { Email: 'Maksud Anda ...@gmail.com? Periksa penulisan email Anda.', KataSandi: 'Kata sandi salah.' },
            '/kelola/keamanan',
        );
        Render();

        expect(screen.getByText('Maksud Anda ...@gmail.com? Periksa penulisan email Anda.')).toBeTruthy();
        expect(screen.getByText('Kata sandi salah.')).toBeTruthy();
    });
});
