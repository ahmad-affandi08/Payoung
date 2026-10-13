import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanNotifikasi from '@/Halaman/Kelola/Notifikasi';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import LonceNotifikasi from '@/TataLetak/LonceNotifikasi';
import type { BarisNotifikasi, PropsNotifikasi } from '@/Tipe/Notifikasi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const jenis: PropsNotifikasi['Jenis'] = [
    { Nilai: 'Persetujuan', Label: 'Permintaan persetujuan', BisaDibisukan: false },
    { Nilai: 'SelisihKas', Label: 'Shift dan kas perlu dicek', BisaDibisukan: true },
    { Nilai: 'StokKritis', Label: 'Stok menipis', BisaDibisukan: true },
];

function Notifikasi(ubah: Partial<BarisNotifikasi> = {}): BarisNotifikasi {
    return {
        Uuid: '01J9ZC5V7Q8R2T4W6Y8A0B2C4D',
        Jenis: 'StokKritis',
        LabelJenis: 'Stok menipis',
        Judul: 'Stok menipis',
        Isi: '3 butir. Stok di bawah batas minimum.',
        Tautan: '/kelola/laporan/stok?tab=kritis',
        DibacaPada: null,
        DibuatPada: '2026-10-07T01:00:00Z',
        ...ubah,
    };
}

function Props(ubah: Partial<PropsNotifikasi> = {}): PropsNotifikasi {
    return {
        Notifikasi: [Notifikasi()],
        BelumDibaca: 1,
        Dibisukan: [],
        Jenis: jenis,
        Saring: { Tampil: 'semua' },
        ...ubah,
    };
}

beforeEach(() => AturHalamanUji({}, '/kelola/notifikasi'));
afterEach(cleanup);

describe('F31 lonceng notifikasi', () => {
    it('tautan ke pusat notifikasi dengan lencana jumlah yang belum dibaca', () => {
        RenderUji(<LonceNotifikasi belumDibaca={3} />);
        const tautan = screen.getByRole('link', { name: 'Notifikasi, 3 belum dibaca' });
        expect(tautan.getAttribute('href')).toBe('/kelola/notifikasi');
        expect(within(tautan).getByText('3')).toBeTruthy();
    });

    it('tanpa notifikasi baru: tanpa lencana; di atas 99 ditulis 99+', () => {
        RenderUji(<LonceNotifikasi belumDibaca={0} />);
        const kosong = screen.getByRole('link', { name: 'Notifikasi' });
        expect(kosong.textContent).toBe('');
        cleanup();

        RenderUji(<LonceNotifikasi belumDibaca={250} />);
        expect(screen.getByText('99+')).toBeTruthy();
    });
});

describe('F31 halaman pusat notifikasi', () => {
    it('daftar: yang belum dibaca bertanda "Baru", jenis dan waktu terbaca, tombol buka dan tandai dibaca', () => {
        RenderUji(<HalamanNotifikasi {...Props()} />);
        const daftar = screen.getByRole('list', { name: 'Daftar notifikasi' });
        expect(within(daftar).getByText('Baru')).toBeTruthy();
        expect(within(daftar).getByText('3 butir. Stok di bawah batas minimum.')).toBeTruthy();
        expect(within(daftar).getByRole('button', { name: 'Buka' })).toBeTruthy();
        expect(within(daftar).getByRole('button', { name: 'Tandai dibaca' })).toBeTruthy();
    });

    it('buka notifikasi baru: ditandai dibaca dulu, lalu menuju halaman tujuan', () => {
        RenderUji(<HalamanNotifikasi {...Props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Buka' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/notifikasi/dibaca',
            { Uuid: ['01J9ZC5V7Q8R2T4W6Y8A0B2C4D'] },
            expect.objectContaining({ preserveScroll: true }),
        );
        const opsi = tiruanRouter.post.mock.calls[0]?.[2] as { onSuccess: () => void };
        opsi.onSuccess();
        expect(tiruanRouter.visit).toHaveBeenCalledWith('/kelola/laporan/stok?tab=kritis');
    });

    it('notifikasi yang sudah dibaca langsung dibuka; tanpa tautan web (persetujuan) tidak ada tombol buka', () => {
        RenderUji(
            <HalamanNotifikasi
                {...Props({
                    Notifikasi: [
                        Notifikasi({ DibacaPada: '2026-10-07T02:00:00Z' }),
                        Notifikasi({
                            Uuid: '01J9ZC5V7Q8R2T4W6Y8A0B2C4E',
                            Jenis: 'Persetujuan',
                            LabelJenis: 'Permintaan persetujuan',
                            Judul: 'Diskon besar',
                            Tautan: null,
                        }),
                    ],
                    BelumDibaca: 1,
                })}
            />,
        );

        fireEvent.click(screen.getAllByRole('button', { name: 'Buka' })[0] as HTMLElement);
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        expect(tiruanRouter.visit).toHaveBeenCalledWith('/kelola/laporan/stok?tab=kritis');
        expect(screen.getAllByRole('button', { name: 'Buka' })).toHaveLength(1);
        expect(screen.getByText('Diputuskan lewat Aplikasi Owner.')).toBeTruthy();
    });

    it('tandai semua dibaca: nonaktif bila tidak ada yang baru, kirim Semua bila ada', () => {
        RenderUji(<HalamanNotifikasi {...Props({ Notifikasi: [], BelumDibaca: 0 })} />);
        expect((screen.getByRole('button', { name: 'Tandai semua dibaca' }) as HTMLButtonElement).disabled).toBe(true);
        expect(screen.getByText(/Belum ada notifikasi/)).toBeTruthy();
        cleanup();

        RenderUji(<HalamanNotifikasi {...Props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Tandai semua dibaca' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith('/kelola/notifikasi/dibaca', { Semua: true }, expect.anything());
    });

    it('saringan belum dibaca: tautan dan keadaan kosong khusus', () => {
        RenderUji(
            <HalamanNotifikasi {...Props({ Notifikasi: [], BelumDibaca: 0, Saring: { Tampil: 'belum-dibaca' } })} />,
        );
        const nav = screen.getByRole('navigation', { name: 'Saringan notifikasi' });
        expect(within(nav).getByRole('link', { name: 'Semua' }).getAttribute('href')).toBe('/kelola/notifikasi');
        const belum = within(nav).getByRole('link', { name: 'Belum dibaca (0)' });
        expect(belum.getAttribute('href')).toBe('/kelola/notifikasi?tampil=belum-dibaca');
        expect(belum.getAttribute('aria-current')).toBe('page');
        expect(screen.getByText('Semua notifikasi sudah dibaca.')).toBeTruthy();
    });

    it('pengaturan: jenis yang dibisukan tidak tercentang; simpan mengirim daftar dibisukan; persetujuan tidak bisa dimatikan', () => {
        RenderUji(<HalamanNotifikasi {...Props({ Dibisukan: ['StokKritis'] })} />);

        expect(screen.queryByRole('checkbox', { name: 'Permintaan persetujuan' })).toBeNull();
        expect(screen.getByText(/Permintaan persetujuan selalu aktif/)).toBeTruthy();
        expect(screen.getByRole('checkbox', { name: 'Stok menipis' }).getAttribute('aria-checked')).toBe('false');
        expect((screen.getByRole('button', { name: 'Simpan pengaturan' }) as HTMLButtonElement).disabled).toBe(true);

        fireEvent.click(screen.getByRole('checkbox', { name: 'Shift dan kas perlu dicek' }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan' }));

        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/notifikasi/pengaturan',
            { Dibisukan: ['SelisihKas', 'StokKritis'] },
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
