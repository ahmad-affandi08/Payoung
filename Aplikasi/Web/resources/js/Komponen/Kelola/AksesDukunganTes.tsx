import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanAksesDukungan from '@/Halaman/Kelola/Pengaturan/AksesDukungan';
import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import type { RiwayatAksesDukunganTenant } from '@/Tipe/TenantPengelola';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/* PGL-16 / P-09 langkah 1 & 4: Owner memberi, mencabut, dan memeriksa jejak izin akses dukungan. */

const aturan = { DurasiJam: [1, 24, 72] };

const izinBerlaku = {
    Uuid: '01K5AKSES0000000000000000A',
    Jenis: 'Berizin' as const,
    LabelJenis: 'Izin Owner',
    Cakupan: 'BacaUbah' as const,
    LabelCakupan: 'Baca & ubah',
    DataPribadiTerbuka: false,
    NamaPemberi: 'Rina Wulandari',
    Alasan: null,
    KategoriDarurat: null,
    NomorTiket: 'TKT-2026-000012',
    MulaiPada: '2026-10-12T03:00:00Z',
    BerakhirPada: '2026-10-13T03:00:00Z',
    DicabutPada: null,
    Status: 'Berlaku' as const,
    JumlahSesi: 1,
};

const kosong: RiwayatAksesDukunganTenant = { Aktif: null, Izin: [], Sesi: [] };

const berjalan: RiwayatAksesDukunganTenant = {
    Aktif: {
        ...izinBerlaku,
        SesiBerjalan: [
            { NamaPetugas: 'Silvia Suryatmi', MulaiPada: '2026-10-12T03:10:00Z', BerakhirPada: '2026-10-12T07:10:00Z' },
        ],
    },
    Izin: [izinBerlaku],
    Sesi: [
        {
            Uuid: '01K5SESI000000000000000001',
            NamaPetugas: 'Silvia Suryatmi',
            MulaiPada: '2026-10-12T03:10:00Z',
            DiakhiriPada: null,
            BerakhirPada: '2026-10-12T07:10:00Z',
            Berjalan: true,
            AlasanAkhir: null,
            JumlahAktivitas: 12,
            JumlahUbah: 2,
            JumlahDitolak: 1,
        },
    ],
};

describe('PGL-16 halaman Pengaturan › Akses dukungan (Owner)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/pengaturan/akses-dukungan');
        kirimanForm.length = 0;
    });
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('tanpa izin berlaku menampilkan formulir beri izin dengan bawaan paling aman (1 jam, baca saja, data pribadi tersamar)', () => {
        RenderUji(<HalamanAksesDukungan Riwayat={kosong} PilihanTiket={[]} Aturan={aturan} />);

        expect(screen.getByText(/Belum pernah ada izin akses/)).toBeTruthy();
        expect(screen.getByText(/Belum ada petugas yang masuk/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Beri izin akses' }));

        const kiriman = kirimanForm.find((item) => item.url === '/kelola/pengaturan/akses-dukungan');
        expect(kiriman?.metode).toBe('post');
        expect(kiriman?.data).toMatchObject({
            DurasiJam: '1',
            Cakupan: 'BacaSaja',
            DataPribadiTerbuka: false,
            UuidTiket: '',
        });
    });

    it('membuka data pribadi pelanggan butuh dicentang dengan sadar', () => {
        RenderUji(<HalamanAksesDukungan Riwayat={kosong} PilihanTiket={[]} Aturan={aturan} />);

        fireEvent.click(screen.getByRole('checkbox', { name: /Buka data pribadi pelanggan/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Beri izin akses' }));

        expect(kirimanForm.at(-1)?.data).toMatchObject({ DataPribadiTerbuka: true });
    });

    it('izin yang berlaku menyembunyikan formulir, menyebut sesi berjalan, dan mencabut lewat konfirmasi', () => {
        RenderUji(<HalamanAksesDukungan Riwayat={berjalan} PilihanTiket={[]} Aturan={aturan} />);

        expect(screen.queryByRole('button', { name: 'Beri izin akses' })).toBeNull();
        expect(screen.getByText(/Sedang berjalan: Silvia Suryatmi/)).toBeTruthy();
        expect(screen.getByText(/tiket TKT-2026-000012/)).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Cabut izin sekarang' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cabut izin' }));

        expect(kirimanForm.find((item) => item.url.endsWith('/cabut'))?.url).toBe(
            `/kelola/pengaturan/akses-dukungan/${izinBerlaku.Uuid}/cabut`,
        );
    });

    it('riwayat sesi menampilkan jumlah permintaan, perubahan, dan penolakan; jejak dimuat saat dibuka', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve({
                    ok: true,
                    status: 200,
                    json: () =>
                        Promise.resolve({
                            Data: [
                                {
                                    Waktu: '2026-10-12T03:11:00Z',
                                    Metode: 'GET',
                                    Rute: 'kelola.produk.daftar',
                                    Jalur: '/kelola/produk',
                                    StatusHttp: 200,
                                    Ditolak: false,
                                    Alasan: null,
                                },
                                {
                                    Waktu: '2026-10-12T03:12:00Z',
                                    Metode: 'POST',
                                    Rute: 'kelola.kategori.simpan',
                                    Jalur: '/kelola/kategori',
                                    StatusHttp: 302,
                                    Ditolak: true,
                                    Alasan: 'HanyaBaca: Izin akses hanya baca.',
                                },
                            ],
                        }),
                }),
            ),
        );
        RenderUji(<HalamanAksesDukungan Riwayat={berjalan} PilihanTiket={[]} Aturan={aturan} />);

        expect(screen.getByText(/12 permintaan, 2 mengubah data, 1 ditolak/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Lihat jejak' }));

        await waitFor(() => expect(screen.getByText('/kelola/produk', { exact: false })).toBeTruthy());
        expect(screen.getByText(/Izin akses hanya baca/)).toBeTruthy();
        expect((fetch as unknown as ReturnType<typeof vi.fn>).mock.calls[0]?.[0]).toBe(
            '/kelola/pengaturan/akses-dukungan/sesi/01K5SESI000000000000000001/aktivitas',
        );
    });
});
