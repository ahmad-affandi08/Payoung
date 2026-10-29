import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanAkunTokoOnline from '@/Halaman/Publik/AkunTokoOnline';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import DialogMasukPembeli from '@/Komponen/TokoOnline/DialogMasukPembeli';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * F-17 toko online bagian 3: dialog masuk dengan kode WhatsApp (nomor → kode → daftar untuk nomor baru) dan halaman
 * "Akun saya" (riwayat pesanan & belanja kasir, tanggal lahir terkunci setelah tersimpan).
 */

const profil = {
    Uuid: '01JPELANGGAN0000000000001',
    Nama: 'Sinta Maharani Kusumawardani',
    NoHp: '0812-7777-1234',
    Email: null,
    TanggalLahir: '1995-04-17',
    SetujuPemasaran: false,
    Tier: 'Gold',
    Poin: 12500,
};

function Json(isi: unknown, status = 200): Response {
    return new Response(JSON.stringify(isi), { status, headers: { 'Content-Type': 'application/json' } });
}

beforeEach(() => AturHalamanUji({}, '/kopi-senja/akun'));
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('DialogMasukPembeli', () => {
    it('nomor baru: kirim kode → masukkan kode → lengkapi nama dengan persetujuan → masuk', async () => {
        const TiruanFetch = vi
            .fn()
            .mockResolvedValueOnce(
                Json({ KedaluwarsaPada: '2026-10-01T10:05:00Z', KirimUlangPada: '2026-10-01T10:01:00Z' }, 202),
            )
            .mockResolvedValueOnce(Json({ PerluDaftar: true, TokenDaftar: 'a'.repeat(48) }))
            .mockResolvedValueOnce(Json({ PerluDaftar: false, Pelanggan: profil, AlamatTerakhir: null }, 201));
        vi.stubGlobal('fetch', TiruanFetch);
        const SaatMasuk = vi.fn();
        RenderUji(
            <DialogMasukPembeli slug="kopi-senja" namaToko="Kopi Senja" saatMasuk={SaatMasuk} saatTutup={vi.fn()} />,
        );

        fireEvent.change(screen.getByLabelText(/Nomor WhatsApp/), { target: { value: '0812-7777-1234' } });
        fireEvent.click(screen.getByRole('button', { name: 'Kirim kode' }));
        const kode = await screen.findByLabelText(/Kode dari WhatsApp/);
        expect(screen.getByText(/Kode 6 digit sudah dikirim ke WhatsApp 0812-7777-1234/)).toBeTruthy();

        fireEvent.change(kode, { target: { value: '12a3456' } });
        expect((kode as HTMLInputElement).value).toBe('123456');
        fireEvent.click(screen.getByRole('button', { name: 'Masuk' }));

        fireEvent.change(await screen.findByLabelText(/^Nama/), { target: { value: 'Sinta Maharani' } });
        const simpan = screen.getByRole('button', { name: 'Simpan & masuk' }) as HTMLButtonElement;
        expect(simpan.disabled).toBe(true);
        fireEvent.click(screen.getByLabelText(/Saya setuju data saya/));
        await waitFor(() => expect(simpan.disabled).toBe(false));
        fireEvent.click(simpan);

        await waitFor(() => expect(SaatMasuk).toHaveBeenCalledWith({ Pelanggan: profil, AlamatTerakhir: null }));
        const kiriman = TiruanFetch.mock.calls.map((c) => [
            String(c[0]),
            JSON.parse(String((c[1] as RequestInit).body)),
        ]);
        expect(kiriman[0]).toEqual(['/kopi-senja/akun/kode', { NoHp: '0812-7777-1234' }]);
        expect(kiriman[1]).toEqual(['/kopi-senja/akun/masuk', { NoHp: '0812-7777-1234', Kode: '123456' }]);
        expect(kiriman[2]?.[1]).toMatchObject({
            TokenDaftar: 'a'.repeat(48),
            Nama: 'Sinta Maharani',
            SetujuDataPribadi: true,
        });
    });

    it('kode salah: pesan server tampil di dialog, pembeli tetap di tahap kode', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce(
                    Json({ KedaluwarsaPada: '2026-10-01T10:05:00Z', KirimUlangPada: '2026-10-01T10:01:00Z' }, 202),
                )
                .mockResolvedValueOnce(
                    Json({ Galat: { Kode: 'KodeSalah', Pesan: 'Kode salah. Sisa 4 kali percobaan.' } }, 422),
                ),
        );
        RenderUji(
            <DialogMasukPembeli slug="kopi-senja" namaToko="Kopi Senja" saatMasuk={vi.fn()} saatTutup={vi.fn()} />,
        );

        fireEvent.change(screen.getByLabelText(/Nomor WhatsApp/), { target: { value: '081277771234' } });
        fireEvent.click(screen.getByRole('button', { name: 'Kirim kode' }));
        fireEvent.change(await screen.findByLabelText(/Kode dari WhatsApp/), { target: { value: '000000' } });
        fireEvent.click(screen.getByRole('button', { name: 'Masuk' }));

        expect(await screen.findByText('Kode salah. Sisa 4 kali percobaan.')).toBeTruthy();
        expect(screen.getByLabelText(/Kode dari WhatsApp/)).toBeTruthy();
    });
});

describe('Halaman Akun saya', () => {
    it('sudah masuk: tier, poin, riwayat pesanan & belanja kasir. Tanggal lahir terkunci', () => {
        RenderUji(
            <HalamanAkunTokoOnline
                Slug="kopi-senja"
                Toko={{ Nama: 'Kopi Senja' }}
                AkunAktif
                Pelanggan={profil}
                Riwayat={{
                    Pesanan: [
                        {
                            Nomor: 'ON/SOLO/261001-0001',
                            Outlet: 'Outlet Solo Baru',
                            JenisPemenuhan: 'Dikirim',
                            Status: 'Selesai',
                            LabelStatus: 'Selesai',
                            Total: '1250000.00',
                            DibuatPada: '2026-10-01T03:00:00Z',
                            UrlStatus: '/kopi-senja/pesanan/ABCDEFGH12345678',
                        },
                    ],
                    Belanja: [
                        {
                            Nomor: 'SOLO-K1-261001-0007',
                            Outlet: 'Outlet Solo Baru',
                            Tanggal: '2026-10-01',
                            Status: 'Lunas',
                            LabelStatus: 'Lunas',
                            Total: '45000.00',
                            UrlStruk: '/s/1.01JPENJUALAN000000000001',
                        },
                    ],
                }}
            />,
        );

        expect(screen.getByText('Gold')).toBeTruthy();
        expect(screen.getByText('12.500 poin')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'ON/SOLO/261001-0001' }).getAttribute('href')).toBe(
            '/kopi-senja/pesanan/ABCDEFGH12345678',
        );
        expect(screen.getByRole('link', { name: 'SOLO-K1-261001-0007' }).getAttribute('href')).toBe(
            '/s/1.01JPENJUALAN000000000001',
        );
        expect(screen.getByText('Rp 1.250.000')).toBeTruthy();
        expect(screen.getByText('Sudah tersimpan. Untuk mengubahnya, hubungi toko.')).toBeTruthy();
        // D-241: nama pelanggan jadi judul halaman, kartu member memuat tier dan poin.
        expect(screen.getByRole('heading', { level: 1, name: profil.Nama })).toBeTruthy();
        expect(screen.getByRole('region', { name: 'Kartu member' })).toBeTruthy();
    });

    it('belum masuk: ajakan masuk. Toko tanpa akun pembeli menjelaskan bahwa pesan tetap bisa', () => {
        RenderUji(
            <HalamanAkunTokoOnline
                Slug="kopi-senja"
                Toko={{ Nama: 'Kopi Senja' }}
                AkunAktif
                Pelanggan={null}
                Riwayat={null}
            />,
        );
        expect(screen.getByRole('button', { name: 'Masuk dengan WhatsApp' })).toBeTruthy();
        cleanup();

        RenderUji(
            <HalamanAkunTokoOnline
                Slug="kopi-senja"
                Toko={{ Nama: 'Kopi Senja' }}
                AkunAktif={false}
                Pelanggan={null}
                Riwayat={null}
            />,
        );
        expect(screen.getByText(/Anda tetap bisa memesan tanpa masuk/)).toBeTruthy();
    });
});
