import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, RenderUji, TambahPropsHalamanUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import Buat from '@/Halaman/Pengelola/Komunikasi/Buat';
import Daftar, { type BarisSiaran } from '@/Halaman/Pengelola/Komunikasi/Daftar';
import Tampil from '@/Halaman/Pengelola/Komunikasi/Tampil';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/* D-210: halaman Komunikasi di konsol (daftar, buat dengan estimasi penerima, rincian + batalkan). */

function AturKonsol() {
    AturHalamanUji({}, '/komunikasi');
    TambahPropsHalamanUji({
        NamaAplikasi: 'Payoung',
        Lingkungan: 'Produksi',
        Kilat: null,
        Pengguna: {
            Uuid: 'P1',
            Nama: 'Dewi Super',
            Email: 'dewi@contoh.id',
            KodePeran: [],
            Izin: ['komunikasi.kelola', 'tenant.lihat'],
        },
        PeringatanSuperAdmin: false,
        PeringatanIntegrasi: [],
        PeringatanOperasional: [],
    });
}

const siaran: BarisSiaran = {
    Uuid: '01JSIARAN0000000000000001',
    Judul: 'Pemeliharaan Minggu malam',
    Kanal: 'Email',
    Status: 'Terjadwal',
    LabelStatus: 'Terjadwal',
    JadwalKirimPada: '2026-10-26T10:00:00Z',
    SelesaiPada: null,
    JumlahPenerima: 0,
    JumlahTerkirim: 0,
    JumlahGagal: 0,
    JumlahDilewati: 0,
};

const propsBuat = {
    PilihanStatus: [{ Nilai: 'Aktif', Label: 'Aktif' }],
    PilihanKesehatan: [{ Nilai: 'Berisiko', Label: 'Berisiko' }],
    PilihanPaket: [{ Nilai: 'PRO', Label: 'Pro' }],
    TenantAwal: [],
    Whatsapp: { Tersedia: true, Alasan: null, JamMulai: 8, JamSelesai: 21 },
};

describe('Komunikasi konsol (D-210)', () => {
    beforeEach(() => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve({
                    ok: true,
                    json: () =>
                        Promise.resolve({ TenantCocok: 130, Penerima: 120, TanpaKontak: 10, Contoh: ['Rina', 'Budi'] }),
                }),
            ),
        );
    });
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('daftar menampilkan siaran dengan tautan ke rincian dan hasil kirim', () => {
        AturKonsol();
        RenderUji(
            <Daftar
                Siaran={[
                    {
                        ...siaran,
                        Status: 'Selesai',
                        LabelStatus: 'Selesai',
                        JumlahPenerima: 120,
                        JumlahTerkirim: 110,
                        JumlahGagal: 6,
                        JumlahDilewati: 4,
                    },
                ]}
            />,
        );

        expect(screen.getByRole('link', { name: 'Pemeliharaan Minggu malam' }).getAttribute('href')).toBe(
            '/komunikasi/01JSIARAN0000000000000001',
        );
        expect(screen.getByText(/110 dari 120 terkirim \| 6 gagal \| 4 dilewati/)).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Buat siaran' }).getAttribute('href')).toBe('/komunikasi/buat');
    });

    it('buat siaran menampilkan estimasi penerima dari server dan mengirim formulir', async () => {
        AturKonsol();
        RenderUji(<Buat {...propsBuat} />);

        await waitFor(() => expect(screen.getByRole('status').textContent).toContain('120 penerima dari 130 tenant'));
        expect(screen.getByRole('status').textContent).toContain('10 tenant tanpa kontak WhatsApp');

        const kirim = screen.getByRole('button', { name: 'Kirim siaran' }) as HTMLButtonElement;
        expect(kirim.disabled).toBe(true);

        fireEvent.change(screen.getByLabelText(/Judul/), { target: { value: 'Fitur baru Kotak Tindakan' } });
        fireEvent.change(screen.getByLabelText(/Isi pesan/), {
            target: { value: 'Sekarang ada Kotak Tindakan di Beranda.' },
        });
        expect((screen.getByRole('button', { name: 'Kirim siaran' }) as HTMLButtonElement).disabled).toBe(false);
    });

    it('WhatsApp tidak tersedia: Email jadi bawaan dan alasannya ditampilkan', async () => {
        AturKonsol();
        RenderUji(
            <Buat
                {...propsBuat}
                Whatsapp={{
                    ...propsBuat.Whatsapp,
                    Tersedia: false,
                    Alasan: 'WhatsApp platform belum dikonfigurasi di Integrasi.',
                }}
            />,
        );

        expect(screen.getByText(/belum dikonfigurasi di Integrasi/)).toBeTruthy();
        expect(await screen.findByText(/tenant tanpa kontak email/)).toBeTruthy();
    });

    it('rincian siaran terjadwal bisa dibatalkan setelah konfirmasi; siaran selesai tidak', () => {
        AturKonsol();
        const { unmount: Lepas } = RenderUji(
            <Tampil Siaran={{ ...siaran, Isi: 'Isi pesan', Segmen: {} }} Penerima={[]} />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Batalkan siaran' }));
        fireEvent.click(screen.getAllByRole('button', { name: 'Batalkan siaran' }).at(-1) as HTMLElement);

        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/komunikasi/01JSIARAN0000000000000001/batal',
            {},
            expect.anything(),
        );

        Lepas();
        RenderUji(
            <Tampil
                Siaran={{ ...siaran, Status: 'Selesai', LabelStatus: 'Selesai', Isi: 'Isi pesan', Segmen: {} }}
                Penerima={[]}
            />,
        );
        expect(screen.queryByRole('button', { name: 'Batalkan siaran' })).toBeNull();
    });
});
