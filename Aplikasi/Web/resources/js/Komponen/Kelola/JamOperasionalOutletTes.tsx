import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import JamOperasionalOutlet, { type JamOperasionalOutletData } from '@/Komponen/Kelola/JamOperasionalOutlet';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const nama = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

function Data(diatur: boolean): JamOperasionalOutletData {
    return {
        Diatur: diatur,
        ZonaWaktu: 'Asia/Jakarta',
        Hari: nama.map((n) => ({
            Nama: n,
            Buka: diatur && n !== 'Minggu' ? '09:00' : null,
            Tutup: diatur && n !== 'Minggu' ? '21:00' : null,
        })),
    };
}

describe('F-e jam operasional outlet', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/outlet/O-1'));
    afterEach(cleanup);

    it('belum diatur: penjelasan selalu buka, semua hari terisi 08:00–21:00 sebagai usulan. Tombol hapus tidak ada', () => {
        RenderUji(<JamOperasionalOutlet alamatOutlet="/kelola/outlet/O-1" data={Data(false)} bolehKelola />);
        expect(screen.getByText(/dianggap selalu buka/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Hapus pengaturan jam' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Simpan jam operasional' }));
        const [alamat, isi] = tiruanRouter.post.mock.calls[0] as [
            string,
            { Jam: Record<string, { Buka: string; Tutup: string } | null> },
        ];
        expect(alamat).toBe('/kelola/outlet/O-1/jam-operasional');
        expect(Object.keys(isi.Jam)).toEqual(nama);
        expect(isi.Jam.Minggu).toEqual({ Buka: '08:00', Tutup: '21:00' });
    });

    it('sudah diatur: hari libur tidak dikirim. Mematikan Buka menjadikan hari itu null. Hapus pengaturan mengirim null', () => {
        RenderUji(<JamOperasionalOutlet alamatOutlet="/kelola/outlet/O-1" data={Data(true)} bolehKelola />);
        const daftar = screen.getByRole('list', { name: 'Jam operasional per hari' });
        const rabu = within(daftar).getByText('Rabu').closest('li') as HTMLElement;
        fireEvent.click(within(rabu).getByRole('checkbox', { name: 'Buka' }));

        fireEvent.click(screen.getByRole('button', { name: 'Simpan jam operasional' }));
        const kiriman = tiruanRouter.post.mock.calls[0]?.[1] as { Jam: Record<string, unknown> };
        expect(kiriman.Jam.Rabu).toBeNull();
        expect(kiriman.Jam.Minggu).toBeNull();
        expect(kiriman.Jam.Senin).toEqual({ Buka: '09:00', Tutup: '21:00' });

        // Router tiruan tidak memanggil onFinish, jadi tombol masih "memproses": render ulang sebelum menekan Hapus.
        cleanup();
        RenderUji(<JamOperasionalOutlet alamatOutlet="/kelola/outlet/O-1" data={Data(true)} bolehKelola />);
        fireEvent.click(screen.getByRole('button', { name: 'Hapus pengaturan jam' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            '/kelola/outlet/O-1/jam-operasional',
            { Jam: null },
            expect.anything(),
        );
    });

    it('tanpa izin ubah: hanya daftar jam, hari libur bertulis Libur, tanpa tombol simpan', () => {
        RenderUji(<JamOperasionalOutlet alamatOutlet="/kelola/outlet/O-1" data={Data(true)} bolehKelola={false} />);
        const daftar = screen.getByRole('list', { name: 'Jam operasional per hari' });
        expect(within(daftar).getByText('Libur')).toBeTruthy();
        expect(within(daftar).getAllByText('09:00 – 21:00')).toHaveLength(6);
        expect(screen.queryByRole('button', { name: 'Simpan jam operasional' })).toBeNull();
    });

    it('samakan semua hari dengan Senin menyalin jam dan status buka Senin', () => {
        const data = Data(true);
        data.Hari[0] = { Nama: 'Senin', Buka: '10:00', Tutup: '22:00' };
        RenderUji(<JamOperasionalOutlet alamatOutlet="/kelola/outlet/O-1" data={data} bolehKelola />);
        fireEvent.click(screen.getByRole('button', { name: 'Samakan semua hari dengan Senin' }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan jam operasional' }));

        const kiriman = tiruanRouter.post.mock.calls[0]?.[1] as { Jam: Record<string, unknown> };
        expect(kiriman.Jam.Minggu).toEqual({ Buka: '10:00', Tutup: '22:00' });
        expect(kiriman.Jam.Kamis).toEqual({ Buka: '10:00', Tutup: '22:00' });
    });
});
