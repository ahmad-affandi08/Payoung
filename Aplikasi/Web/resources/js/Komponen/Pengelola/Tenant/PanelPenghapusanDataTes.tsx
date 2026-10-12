import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import PanelPenghapusanData from '@/Komponen/Pengelola/Tenant/PanelPenghapusanData';
import type { PermintaanPenghapusanData } from '@/Tipe/TenantPengelola';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/* P-07 / UU PDP: panel permintaan penghapusan data di tampilan 360° tenant (konsol). */

const dasar: PermintaanPenghapusanData = {
    Uuid: '01K5PENGHAPUSAN0000000000A1',
    Status: 'Menunggu',
    DiajukanPada: '2026-10-12T06:00:00Z',
    BerlakuPada: '2026-10-26T06:00:00Z',
    MasaTungguSelesai: false,
    Alasan: 'Usaha ditutup',
    CatatanPemutus: null,
    DiputuskanPada: null,
    DilaksanakanPada: null,
    BuktiDikirimPada: null,
    BerkasPembukuanDihapusPada: null,
    TabelDianonimkan: 0,
    TabelDihapus: 0,
    PenggunaDianonimkan: 0,
    BerkasDihapus: 0,
    BerkasGagal: 0,
};

describe('P-07 panel penghapusan data (konsol)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/tenant/01K5TENANT');
        kirimanForm.length = 0;
    });
    afterEach(cleanup);

    it('tanpa permintaan tidak menampilkan apa pun', () => {
        const { container } = RenderUji(
            <PanelPenghapusanData uuidTenant="01K5TENANT" slug="toko-a" permintaan={[]} boleh />,
        );

        expect(container.textContent).toBe('');
    });

    it('selama masa tunggu tombol laksanakan terkunci tetapi tolak tersedia; tanpa izin tidak ada tombol', () => {
        RenderUji(<PanelPenghapusanData uuidTenant="01K5TENANT" slug="toko-a" permintaan={[dasar]} boleh />);

        expect((screen.getByRole('button', { name: 'Laksanakan penghapusan' }) as HTMLButtonElement).disabled).toBe(
            true,
        );
        expect(screen.getByRole('button', { name: 'Tolak permintaan' })).toBeTruthy();
        expect(screen.getByText(/Masa tunggu belum berakhir/)).toBeTruthy();
        cleanup();

        RenderUji(<PanelPenghapusanData uuidTenant="01K5TENANT" slug="toko-a" permintaan={[dasar]} boleh={false} />);
        expect(screen.queryByRole('button', { name: 'Laksanakan penghapusan' })).toBeNull();
    });

    it('setelah masa tunggu: melaksanakan meminta catatan dan slug lalu mengirim POST ke rute laksanakan', () => {
        RenderUji(
            <PanelPenghapusanData
                uuidTenant="01K5TENANT"
                slug="toko-a"
                permintaan={[{ ...dasar, MasaTungguSelesai: true }]}
                boleh
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Laksanakan penghapusan' }));
        fireEvent.change(screen.getByLabelText(/Catatan verifikasi identitas Owner/), {
            target: { value: 'Identitas diverifikasi lewat telepon.' },
        });
        fireEvent.change(screen.getByLabelText(/Ketik slug tenant \(toko-a\)/), { target: { value: 'toko-a' } });
        fireEvent.click(screen.getAllByRole('button', { name: 'Laksanakan penghapusan' }).at(-1) as HTMLElement);

        const kiriman = kirimanForm.find((item) => item.url.endsWith('/laksanakan'));
        expect(kiriman?.url).toBe(`/tenant/01K5TENANT/penghapusan-data/${dasar.Uuid}/laksanakan`);
        expect(kiriman?.data).toMatchObject({
            Catatan: 'Identitas diverifikasi lewat telepon.',
            KonfirmasiSlug: 'toko-a',
        });
    });

    it('menolak mengirim alasan ke rute tolak; yang sudah dilaksanakan menampilkan ringkasan tanpa tombol', () => {
        RenderUji(<PanelPenghapusanData uuidTenant="01K5TENANT" slug="toko-a" permintaan={[dasar]} boleh />);
        fireEvent.click(screen.getByRole('button', { name: 'Tolak permintaan' }));
        fireEvent.change(screen.getByLabelText(/Alasan penolakan/), { target: { value: 'Ada sengketa pembayaran.' } });
        fireEvent.click(screen.getAllByRole('button', { name: 'Tolak permintaan' }).at(-1) as HTMLElement);

        expect(kirimanForm.find((item) => item.url.endsWith('/tolak'))?.data).toMatchObject({
            Alasan: 'Ada sengketa pembayaran.',
        });
        cleanup();

        RenderUji(
            <PanelPenghapusanData
                uuidTenant="01K5TENANT"
                slug="toko-a"
                boleh
                permintaan={[
                    {
                        ...dasar,
                        Status: 'Dilaksanakan',
                        DilaksanakanPada: '2026-10-27T06:00:00Z',
                        BuktiDikirimPada: '2026-10-27T06:01:00Z',
                        TabelDianonimkan: 14,
                        TabelDihapus: 9,
                        PenggunaDianonimkan: 3,
                        BerkasDihapus: 5,
                        BerkasGagal: 1,
                    },
                ]}
            />,
        );
        expect(
            screen.getByText(
                /14 tabel dianonimkan, 9 tabel dihapus, 3 akun dianonimkan, 5 berkas dihapus, 1 berkas gagal dihapus/,
            ),
        ).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Laksanakan penghapusan' })).toBeNull();
    });
});
