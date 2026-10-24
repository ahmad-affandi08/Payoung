import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import PanelAksesDukungan from '@/Komponen/Pengelola/Tenant/PanelAksesDukungan';
import type { IzinAksesDukungan, RiwayatAksesDukunganTenant } from '@/Tipe/TenantPengelola';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/* PGL-16 / P-09: panel Akses dukungan di tampilan 360° tenant (konsol). */

const izin: IzinAksesDukungan = {
    Uuid: '01K5AKSES0000000000000000A',
    Jenis: 'Berizin',
    LabelJenis: 'Izin Owner',
    Cakupan: 'BacaSaja',
    LabelCakupan: 'Baca saja',
    DataPribadiTerbuka: false,
    NamaPemberi: 'Rina Wulandari',
    Alasan: null,
    KategoriDarurat: null,
    NomorTiket: null,
    MulaiPada: '2026-10-12T03:00:00Z',
    BerakhirPada: '2026-10-13T03:00:00Z',
    DicabutPada: null,
    Status: 'Berlaku',
    JumlahSesi: 0,
};

const kosong: RiwayatAksesDukunganTenant = { Aktif: null, Izin: [], Sesi: [], Alat: [] };
const BuatRiwayatDenganIzin = (tambahan: Partial<IzinAksesDukungan> = {}): RiwayatAksesDukunganTenant => ({
    Aktif: { ...izin, ...tambahan, SesiBerjalan: [] },
    Izin: [{ ...izin, ...tambahan }],
    Sesi: [],
    Alat: [],
});

describe('PGL-16 panel akses dukungan (konsol)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/tenant/01K5TENANT');
        kirimanForm.length = 0;
    });
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('tanpa izin dari Owner: menjelaskan keadaan dan tidak menawarkan masuk; tombol darurat hanya untuk yang berizin', () => {
        RenderUji(<PanelAksesDukungan uuidTenant="01K5TENANT" data={kosong} bolehMasuk bolehDarurat={false} />);

        expect(screen.getByText(/belum memberi izin akses dukungan/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Masuk sebagai tenant' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Buka akses darurat' })).toBeNull();
        cleanup();

        RenderUji(<PanelAksesDukungan uuidTenant="01K5TENANT" data={kosong} bolehMasuk bolehDarurat />);
        expect(screen.getByRole('button', { name: 'Buka akses darurat' })).toBeTruthy();
    });

    it('dengan izin berlaku: menampilkan cakupan dan batas waktu, dan tombol masuk hanya untuk yang berizin masuk', () => {
        RenderUji(
            <PanelAksesDukungan
                uuidTenant="01K5TENANT"
                data={BuatRiwayatDenganIzin()}
                bolehMasuk
                bolehDarurat={false}
            />,
        );

        expect(screen.getByText('Baca saja')).toBeTruthy();
        expect(screen.getByText(/data pribadi pelanggan tersamar/)).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Masuk sebagai tenant' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Tutup akses darurat' })).toBeNull();
        cleanup();

        RenderUji(
            <PanelAksesDukungan
                uuidTenant="01K5TENANT"
                data={BuatRiwayatDenganIzin()}
                bolehMasuk={false}
                bolehDarurat={false}
            />,
        );
        expect(screen.queryByRole('button', { name: 'Masuk sebagai tenant' })).toBeNull();
    });

    it('masuk sebagai tenant meminta alamat sekali pakai lalu membukanya di tab baru', async () => {
        const jendela = { location: { href: '' }, close: vi.fn() };
        vi.stubGlobal(
            'open',
            vi.fn(() => jendela),
        );
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve({
                    ok: true,
                    status: 200,
                    json: () =>
                        Promise.resolve({
                            Url: 'https://dashboard.payoung.id/dukungan/masuk/7/abc',
                            BerakhirPada: '2026-10-12T07:00:00Z',
                        }),
                }),
            ),
        );

        RenderUji(
            <PanelAksesDukungan
                uuidTenant="01K5TENANT"
                data={BuatRiwayatDenganIzin()}
                bolehMasuk
                bolehDarurat={false}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Masuk sebagai tenant' }));

        await waitFor(() => expect(jendela.location.href).toBe('https://dashboard.payoung.id/dukungan/masuk/7/abc'));
        expect((fetch as unknown as ReturnType<typeof vi.fn>).mock.calls[0]?.[0]).toBe(
            '/tenant/01K5TENANT/akses-dukungan/masuk',
        );
    });

    it('masalah dari server ditampilkan dan tab kosong ditutup', async () => {
        const jendela = { location: { href: '' }, close: vi.fn() };
        vi.stubGlobal(
            'open',
            vi.fn(() => jendela),
        );
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve({
                    ok: false,
                    status: 422,
                    json: () =>
                        Promise.resolve({ Galat: { Kode: 'AksesTidakAda', Pesan: 'Izin akses sudah berakhir.' } }),
                }),
            ),
        );

        RenderUji(
            <PanelAksesDukungan
                uuidTenant="01K5TENANT"
                data={BuatRiwayatDenganIzin()}
                bolehMasuk
                bolehDarurat={false}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Masuk sebagai tenant' }));

        await waitFor(() => expect(screen.getByText('Izin akses sudah berakhir.')).toBeTruthy());
        expect(jendela.close).toHaveBeenCalled();
    });

    it('akses darurat: kategori, alasan, durasi, dan cakupan dikirim ke rute darurat', () => {
        RenderUji(<PanelAksesDukungan uuidTenant="01K5TENANT" data={kosong} bolehMasuk={false} bolehDarurat />);

        fireEvent.click(screen.getByRole('button', { name: 'Buka akses darurat' }));
        fireEvent.change(screen.getByLabelText(/Alasan tertulis/), {
            target: { value: 'Dugaan pembobolan akun kasir, perlu menelusuri log.' },
        });
        fireEvent.click(screen.getAllByRole('button', { name: 'Buka akses darurat' }).at(-1) as HTMLElement);

        const kiriman = kirimanForm.find((item) => item.url.endsWith('/darurat'));
        expect(kiriman?.url).toBe('/tenant/01K5TENANT/akses-dukungan/darurat');
        expect(kiriman?.data).toMatchObject({
            Kategori: 'InsidenKeamanan',
            Alasan: 'Dugaan pembobolan akun kasir, perlu menelusuri log.',
            DurasiJam: '1',
            Cakupan: 'BacaSaja',
        });
    });

    it('izin darurat yang berlaku bisa ditutup Super Admin; izin Owner tidak punya tombol tutup', () => {
        RenderUji(
            <PanelAksesDukungan
                uuidTenant="01K5TENANT"
                data={BuatRiwayatDenganIzin({
                    Jenis: 'Darurat',
                    LabelJenis: 'Akses darurat',
                    Alasan: 'Insiden',
                    KategoriDarurat: 'Insiden keamanan',
                })}
                bolehMasuk
                bolehDarurat
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Tutup akses darurat' }));
        fireEvent.click(screen.getAllByRole('button', { name: 'Tutup akses darurat' }).at(-1) as HTMLElement);

        expect(kirimanForm.find((item) => item.url.endsWith('/cabut'))?.url).toBe(
            `/tenant/01K5TENANT/akses-dukungan/${izin.Uuid}/cabut`,
        );
        cleanup();

        RenderUji(
            <PanelAksesDukungan uuidTenant="01K5TENANT" data={BuatRiwayatDenganIzin()} bolehMasuk bolehDarurat />,
        );
        expect(screen.queryByRole('button', { name: 'Tutup akses darurat' })).toBeNull();
    });
});
