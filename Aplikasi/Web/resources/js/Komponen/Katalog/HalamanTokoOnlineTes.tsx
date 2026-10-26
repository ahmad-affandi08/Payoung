import { cleanup, screen } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanTokoOnline from '@/Halaman/Kelola/TokoOnline/Daftar';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const props: ComponentProps<typeof HalamanTokoOnline> = {
    TautanPublik: 'https://payoung.id/kopi-senja',
    AkunPembeliTersedia: false,
    Notifikasi: { Siap: true, Alasan: null, GagalBulanIni: 0 },
    QrSvg: '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
    Pengaturan: {
        Aktif: false,
        BayarSaatAmbilAktif: true,
        CodAktif: false,
        QrisAktif: false,
        AkunPelangganAktif: false,
        NotifikasiWhatsappAktif: false,
        MinimalPesanan: '0.00',
        MenitKedaluwarsa: 120,
        PesanTutup: null,
    },
    Outlet: [
        {
            Id: 1,
            Uuid: '01K0UTLT00000000000000001A',
            Nama: 'Toko Pusat',
            TokoOnlineAktif: false,
            AmbilSendiriAktif: true,
            KirimAktif: false,
        },
    ],
    Zona: [],
    Kurir: [],
    Pesanan: [],
    OpsiStatusPesanan: [],
    OpsiStatusPengiriman: [],
    OpsiAkun: [],
    IzinRefund: false,
};

/** Toko sudah menerima pesanan, jadi panel Bagikan tidak menambah peringatan (role alert) di halaman. */
const propsSiap: ComponentProps<typeof HalamanTokoOnline> = {
    ...props,
    Pengaturan: { ...props.Pengaturan, Aktif: true },
    Outlet: [{ ...(props.Outlet[0] as (typeof props.Outlet)[number]), TokoOnlineAktif: true }],
};

afterEach(cleanup);

describe('Toko online › Pengaturan toko', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/toko-online'));

    it('tanpa masalah tidak menampilkan peringatan', () => {
        RenderUji(<HalamanTokoOnline {...propsSiap} />);
        expect(screen.queryByRole('alert')).toBeNull();
    });

    it('masalah dari server (misal fitur belum di paket) tampil di dekat tombol simpan, tidak diam-diam', () => {
        AturHalamanUji({ Aktif: 'Toko online belum aktif di paket usaha ini.' }, '/kelola/toko-online');
        RenderUji(<HalamanTokoOnline {...propsSiap} />);

        const peringatan = screen.getByRole('alert');
        expect(peringatan.textContent).toContain('Toko online belum aktif di paket usaha ini.');
    });
});

describe('Toko online › Kabar pesanan lewat WhatsApp', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/toko-online'));

    it('memperingatkan bila sakelar menyala tetapi WhatsApp belum bisa mengirim, dan menghitung kabar yang gagal', () => {
        const propsKuotaHabis: ComponentProps<typeof HalamanTokoOnline> = {
            ...propsSiap,
            Pengaturan: { ...propsSiap.Pengaturan, NotifikasiWhatsappAktif: true },
            Notifikasi: { Siap: false, Alasan: 'Kuota pesan WhatsApp bulan ini sudah habis.', GagalBulanIni: 3 },
        };
        RenderUji(<HalamanTokoOnline {...propsKuotaHabis} />);

        const peringatan = screen.getByRole('alert');
        expect(peringatan.textContent).toContain('Kuota pesan WhatsApp bulan ini sudah habis.');
        expect(screen.getByRole('link', { name: 'Atur WhatsApp' }).getAttribute('href')).toBe(
            '/kelola/pengaturan/whatsapp',
        );
        expect(screen.getByText(/3 kabar pesanan gagal terkirim/)).toBeTruthy();
    });

    it('tanpa peringatan bila WhatsApp siap atau sakelar mati', () => {
        RenderUji(
            <HalamanTokoOnline
                {...propsSiap}
                Notifikasi={{ Siap: false, Alasan: 'WhatsApp belum tersambung.', GagalBulanIni: 0 }}
            />,
        );

        expect(screen.queryByRole('alert')).toBeNull();
    });
});

describe('D-110 bagikan toko online', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/toko-online'));

    it('menampilkan tautan, QR, salin, WhatsApp, unduh QR, dan poster. Peringatan bila toko belum menerima pesanan', () => {
        RenderUji(<HalamanTokoOnline {...props} />);

        const bagikan = screen.getByRole('region', { name: 'Bagikan toko' });
        expect(bagikan.textContent).toContain('https://payoung.id/kopi-senja');
        expect(screen.getByAltText('QR toko online')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Salin tautan' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Kirim lewat WhatsApp' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Unduh QR' }).getAttribute('download')).toBe('qr-toko-online.svg');
        expect(screen.getByRole('link', { name: 'Cetak poster' }).getAttribute('href')).toBe(
            '/kelola/toko-online/poster',
        );
        expect(screen.getByText(/Toko belum menerima pesanan/)).toBeTruthy();
    });

    it('tanpa peringatan bila toko sudah menerima pesanan dan ada outlet yang tampil online', () => {
        RenderUji(
            <HalamanTokoOnline
                {...props}
                Pengaturan={{ ...props.Pengaturan, Aktif: true }}
                Outlet={[{ ...(props.Outlet[0] as (typeof props.Outlet)[number]), TokoOnlineAktif: true }]}
            />,
        );

        expect(screen.queryByText(/Toko belum menerima pesanan/)).toBeNull();
    });
});
