import { cleanup, screen } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanTokoOnline from '@/Halaman/Kelola/TokoOnline/Daftar';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const props: ComponentProps<typeof HalamanTokoOnline> = {
    TautanPublik: 'https://payoung.id/kopi-senja',
    AkunPembeliTersedia: false,
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

afterEach(cleanup);

describe('Toko online › Pengaturan toko', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/toko-online'));

    it('tanpa galat tidak menampilkan peringatan', () => {
        RenderUji(<HalamanTokoOnline {...props} />);
        expect(screen.queryByRole('alert')).toBeNull();
    });

    it('galat dari server (misal fitur belum di paket) tampil di dekat tombol simpan, tidak diam-diam', () => {
        AturHalamanUji({ Aktif: 'Toko online belum aktif di paket usaha ini.' }, '/kelola/toko-online');
        RenderUji(<HalamanTokoOnline {...props} />);

        const peringatan = screen.getByRole('alert');
        expect(peringatan.textContent).toContain('Toko online belum aktif di paket usaha ini.');
    });
});
