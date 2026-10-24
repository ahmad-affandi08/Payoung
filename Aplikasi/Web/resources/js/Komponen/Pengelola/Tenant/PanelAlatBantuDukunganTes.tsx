import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import PanelAlatBantuDukungan from '@/Komponen/Pengelola/Tenant/PanelAlatBantuDukungan';
import type { RiwayatAksesDukunganTenant } from '@/Tipe/TenantPengelola';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/* PGL-16 bagian 2 / P-09: alat bantu dukungan di tampilan 360° tenant (konsol). */

const UUID_TENANT = '01K5TENANT0000000000000001';

const akses = {
    Uuid: '01K5AKSES0000000000000000A',
    Jenis: 'Berizin' as const,
    LabelJenis: 'Izin Owner',
    DataPribadiTerbuka: false,
    NamaPemberi: 'Rina Wulandari',
    Alasan: null,
    KategoriDarurat: null,
    NomorTiket: null,
    MulaiPada: '2026-10-12T03:00:00Z',
    BerakhirPada: '2026-10-13T03:00:00Z',
    DicabutPada: null,
    Status: 'Berlaku' as const,
    JumlahSesi: 0,
    SesiBerjalan: [],
};

function BuatRiwayat(cakupan: 'BacaSaja' | 'BacaUbah' | null): RiwayatAksesDukunganTenant {
    return {
        Aktif:
            cakupan === null
                ? null
                : { ...akses, Cakupan: cakupan, LabelCakupan: cakupan === 'BacaUbah' ? 'Baca & ubah' : 'Baca saja' },
        Izin: [],
        Sesi: [],
        Alat: [],
    };
}

const perangkat = {
    Uuid: '01K5PERANGKAT00000000000001',
    Kode: 'UTM-K01',
    Nama: 'Kasir Depan',
    Outlet: 'Outlet Utama',
    Jenis: 'Kasir',
    Platform: 'Android',
    VersiAplikasi: '1.0.0+5',
    Status: 'Aktif',
    TerakhirAktifPada: '2026-10-12T00:00:00Z',
    JumlahOutboxTertunda: 7,
    MintaLogPada: null,
    LogDiunggahPada: '2026-10-11T10:00:00Z',
    PermintaanLogBerjalan: false,
    Perhatian: '7 item belum terkirim dan perangkat belum terhubung lebih dari 2 jam.',
};

function TiruFetch(): ReturnType<typeof vi.fn> {
    const Tiruan = vi.fn((alamat: string) => {
        const isi = alamat.endsWith('/alat-dukungan/perangkat')
            ? { Perangkat: [perangkat] }
            : alamat.endsWith('/alat-dukungan/kirim-ulang')
              ? {
                    PesanGagal: [
                        {
                            Uuid: '01K5PESAN0000000000000001',
                            Kanal: 'Whatsapp',
                            TujuanTersamar: '••••••890',
                            Percobaan: 3,
                            PesanGalat: 'Penyedia menolak.',
                            DibuatPada: '2026-10-11T08:00:00Z',
                            SudahDikirimUlang: false,
                        },
                    ],
                    AnggotaBelumVerifikasi: [
                        {
                            Uuid: '01K5USER00000000000000001',
                            Nama: 'Rina Wulandari',
                            EmailTersamar: 'r•••@kopi.id',
                            Pemilik: true,
                        },
                    ],
                }
              : {
                    Log: {
                        Kode: 'UTM-K01',
                        DiunggahPada: '2026-10-11T10:00:00Z',
                        VersiAplikasi: '1.0.0+5',
                        JumlahBaris: 2,
                        Isi: 'baris satu\nbaris dua',
                    },
                };

        return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(isi) });
    });
    vi.stubGlobal('fetch', Tiruan);

    return Tiruan;
}

describe('PGL-16 bagian 2: panel alat bantu dukungan', () => {
    beforeEach(() => {
        AturHalamanUji({}, `/tenant/${UUID_TENANT}`);
        kirimanForm.length = 0;
    });
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('tanpa izin akses yang berlaku, alat tidak dimuat dan Owner perlu memberi izin', () => {
        const Tiruan = TiruFetch();
        RenderUji(<PanelAlatBantuDukungan uuidTenant={UUID_TENANT} data={BuatRiwayat(null)} />);

        expect(screen.getByText(/Belum ada izin akses dukungan yang berlaku/)).toBeTruthy();
        expect(Tiruan).not.toHaveBeenCalled();
    });

    it('Baca saja: perangkat dan daftar kirim ulang terbaca, tetapi tidak ada tombol yang mengubah data', async () => {
        TiruFetch();
        RenderUji(<PanelAlatBantuDukungan uuidTenant={UUID_TENANT} data={BuatRiwayat('BacaSaja')} />);

        expect(screen.getByText(/Izin ini baca saja/)).toBeTruthy();
        await waitFor(() => expect(screen.getByText('Kasir Depan')).toBeTruthy());
        expect(screen.getByText(/7 item belum terkirim dan perangkat belum terhubung/)).toBeTruthy();
        expect(screen.getByRole('button', { name: /Baca log/ })).toBeTruthy();
        await waitFor(() => expect(screen.getByText('••••••890')).toBeTruthy());

        for (const nama of [
            'Cabut perangkat',
            'Minta log',
            'Bangun ulang saldo stok',
            'Bangun ulang ringkasan harian',
            'Kirim ulang',
            'Kirim ulang email',
        ]) {
            expect(screen.queryByRole('button', { name: nama })).toBeNull();
        }
    });

    it('Baca & ubah: cabut perangkat meminta konfirmasi lalu mengirim ke jalur alat tenant itu', async () => {
        TiruFetch();
        RenderUji(<PanelAlatBantuDukungan uuidTenant={UUID_TENANT} data={BuatRiwayat('BacaUbah')} />);

        await waitFor(() => expect(screen.getByText('Kasir Depan')).toBeTruthy());
        fireEvent.click(screen.getByRole('button', { name: 'Cabut perangkat' }));
        expect(screen.getByText(/Perangkat langsung ditolak server/)).toBeTruthy();
        fireEvent.click(screen.getAllByRole('button', { name: 'Cabut perangkat' }).at(-1) as HTMLElement);

        expect(kirimanForm.at(-1)?.url).toBe(`/tenant/${UUID_TENANT}/alat-dukungan/perangkat/${perangkat.Uuid}/cabut`);
    });

    it('Baca & ubah: bangun ulang saldo stok, kirim ulang pesan, dan kirim ulang email masing-masing ke jalurnya', async () => {
        TiruFetch();
        RenderUji(<PanelAlatBantuDukungan uuidTenant={UUID_TENANT} data={BuatRiwayat('BacaUbah')} />);

        fireEvent.click(screen.getByRole('button', { name: 'Bangun ulang saldo stok' }));
        fireEvent.click(screen.getByRole('button', { name: 'Bangun ulang saldo' }));
        expect(kirimanForm.at(-1)?.url).toBe(`/tenant/${UUID_TENANT}/alat-dukungan/saldo-stok/bangun-ulang`);
        fireEvent.click(screen.getByRole('button', { name: 'Batal' }));

        await waitFor(() => expect(screen.getByRole('button', { name: 'Kirim ulang' })).toBeTruthy());
        fireEvent.click(screen.getByRole('button', { name: 'Kirim ulang' }));
        fireEvent.click(screen.getAllByRole('button', { name: 'Kirim ulang' }).at(-1) as HTMLElement);
        expect(kirimanForm.at(-1)?.url).toBe(
            `/tenant/${UUID_TENANT}/alat-dukungan/pesan/01K5PESAN0000000000000001/kirim-ulang`,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Batal' }));

        fireEvent.click(screen.getByRole('button', { name: 'Kirim ulang email' }));
        fireEvent.click(screen.getAllByRole('button', { name: 'Kirim ulang email' }).at(-1) as HTMLElement);
        expect(kirimanForm.at(-1)?.url).toBe(
            `/tenant/${UUID_TENANT}/alat-dukungan/pengguna/01K5USER00000000000000001/kirim-ulang-verifikasi`,
        );
    });

    it('membaca log perangkat memuat log dari jalur alat dan menampilkannya', async () => {
        const Tiruan = TiruFetch();
        RenderUji(<PanelAlatBantuDukungan uuidTenant={UUID_TENANT} data={BuatRiwayat('BacaSaja')} />);

        await waitFor(() => expect(screen.getByRole('button', { name: /Baca log/ })).toBeTruthy());
        fireEvent.click(screen.getByRole('button', { name: /Baca log/ }));

        await waitFor(() => expect(screen.getByLabelText('Isi log perangkat')).toBeTruthy());
        expect(screen.getByLabelText('Isi log perangkat').textContent).toContain('baris dua');
        expect(Tiruan.mock.calls.map((panggilan) => panggilan[0])).toContain(
            `/tenant/${UUID_TENANT}/alat-dukungan/perangkat/${perangkat.Uuid}/log`,
        );
    });

    it('masalah server (misal izin dicabut) tampil sebagai pesan, bukan layar kosong', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve({
                    ok: false,
                    status: 422,
                    json: () =>
                        Promise.resolve({
                            Galat: { Kode: 'AksesTidakAda', Pesan: 'Tenant ini belum memberi izin akses dukungan.' },
                        }),
                }),
            ),
        );
        RenderUji(<PanelAlatBantuDukungan uuidTenant={UUID_TENANT} data={BuatRiwayat('BacaUbah')} />);

        await waitFor(() =>
            expect(screen.getAllByText('Tenant ini belum memberi izin akses dukungan.').length).toBeGreaterThan(0),
        );
    });
});
