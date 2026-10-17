import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { SiapkanKameraTiruan } from '@/Pengujian/KameraTiruan';
import type { Kendaraan } from '@/Tipe/Bengkel';

import DialogKendaraan from './DialogKendaraan';
import PemilihCariBengkel from './PemilihCariBengkel';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const kamera = vi.hoisted(() => ({
    mulai: vi.fn(),
    daftar: vi.fn(),
}));

vi.mock('@/Pustaka/PemindaiKamera', async (impor) => ({
    ...(await impor<typeof import('@/Pustaka/PemindaiKamera')>()),
    MulaiPemindaian: kamera.mulai,
    DaftarKamera: kamera.daftar,
}));

function Unit(jenis: 'Kendaraan' | 'Perangkat'): Kendaraan {
    return {
        Uuid: 'K-1',
        Jenis: jenis,
        NomorPolisi: null,
        Label: 'Unit uji',
        Merek: 'Samsung',
        Tipe: null,
        Tahun: null,
        Warna: null,
        NomorRangka: null,
        NomorMesin: null,
        KmTerakhir: null,
        Catatan: null,
        Aktif: true,
        Pelanggan: { Uuid: 'PL-1', Nama: 'Budi Santoso' },
        JumlahServis: 0,
        ServisTerakhirPada: null,
    };
}

describe('Bengkel: pindai kamera nomor seri/IMEI', () => {
    beforeEach(() => {
        kamera.mulai.mockReset();
        kamera.daftar.mockReset();
    });
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('dialog perangkat: IMEI dari kamera mengisi isian "nomor polisi / IMEI"', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);

        RenderUji(<DialogKendaraan kendaraan={Unit('Perangkat')} pelanggan={null} saatTutup={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Pindai IMEI atau nomor seri perangkat' }));
        await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());
        tiruan.terbaca('356938035643809');

        await waitFor(() =>
            expect((screen.getByLabelText(/IMEI/, { selector: 'input' }) as HTMLInputElement).value).toBe(
                '356938035643809',
            ),
        );
        expect(tiruan.opsiTerakhir()).toMatchObject({ jenisKode: 'Seri' });
    });

    it('dialog kendaraan bermotor: tidak ada tombol kamera (nomor polisi bukan barcode)', () => {
        SiapkanKameraTiruan(kamera);

        RenderUji(<DialogKendaraan kendaraan={Unit('Kendaraan')} pelanggan={null} saatTutup={vi.fn()} />);

        expect(screen.queryByRole('button', { name: /Pindai/ })).toBeNull();
    });

    it("pemilih unit servis: IMEI yang dipindai dan hanya cocok dengan satu unit langsung dipilih (pindaiKamera 'Seri')", async () => {
        const tiruan = SiapkanKameraTiruan(kamera);
        const SaatPilih = vi.fn();

        vi.stubGlobal(
            'fetch',
            vi.fn((url: string) => {
                const kata = new URL(url, 'http://localhost').searchParams.get('kata') ?? '';

                return Promise.resolve({
                    ok: true,
                    json: () =>
                        Promise.resolve({
                            Data: kata === '356938035643809' ? [{ Uuid: 'K-1', Judul: 'Samsung A15' }] : [],
                        }),
                });
            }),
        );
        RenderUji(
            <PemilihCariBengkel<{ Uuid: string; Judul: string }>
                sumber="kendaraan"
                label="Unit servis"
                placeholder="Pilih unit servis"
                buatUrl={(kata) => `/kelola/bengkel/kendaraan/cari?${new URLSearchParams({ kata }).toString()}`}
                ambilId={(u) => u.Uuid}
                ambilJudul={(u) => u.Judul}
                saatPilih={SaatPilih}
                pesanKosong="Belum ada unit."
                pindaiKamera="Seri"
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Pindai nomor seri Unit servis' }));
        await waitFor(() => expect(tiruan.opsiTerakhir()).not.toBeNull());
        await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());
        tiruan.terbaca('356938035643809');

        await waitFor(() => expect(SaatPilih).toHaveBeenCalledWith({ Uuid: 'K-1', Judul: 'Samsung A15' }), {
            timeout: 3000,
        });
        expect(tiruan.opsiTerakhir()).toMatchObject({ jenisKode: 'Seri' });
    });

    it('pemilih tanpa pindaiKamera (pelanggan, jasa) tidak punya tombol kamera', () => {
        SiapkanKameraTiruan(kamera);
        RenderUji(
            <PemilihCariBengkel<{ Uuid: string; Judul: string }>
                sumber="pelanggan"
                label="Pemilik"
                placeholder="Pilih pelanggan"
                buatUrl={() => '/kelola/bengkel/pelanggan/cari'}
                ambilId={(u) => u.Uuid}
                ambilJudul={(u) => u.Judul}
                saatPilih={vi.fn()}
                pesanKosong="Belum ada."
            />,
        );

        expect(screen.queryByRole('button', { name: /Pindai/ })).toBeNull();
    });
});
