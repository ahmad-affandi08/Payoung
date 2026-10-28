import { cleanup, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanPerangkatPanduan from '@/Halaman/Kelola/PanduanAwal/Perangkat';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import type { PropsPerangkatPanduan } from '@/Tipe/PanduanAwal';

import { BuatProgresContoh } from './DataUjiPanduan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * Audit kemudahan pakai #2: PIN kasir pemilik ditanyakan di langkah Perangkat, supaya masuk pertama di aplikasi kasir
 * tidak gagal dengan "PIN belum diatur".
 */
function BuatProps(pinDiatur: boolean): PropsPerangkatPanduan {
    return {
        Progres: BuatProgresContoh(),
        Outlet: {
            Uuid: '01K0UTLT',
            Kode: 'PST',
            Nama: 'Toko Sembako Berkah Jaya',
            BatasPerangkat: { Batas: 5, Terpakai: 0 },
        },
        Perangkat: [],
        KodeAktivasiBaru: null,
        UnduhanAplikasi: [],
        BolehKelolaPerangkat: true,
        PinSayaDiatur: pinDiatur,
    };
}

beforeEach(() => AturHalamanUji({}, '/kelola/panduan-awal/perangkat'));
afterEach(cleanup);

describe('Langkah Perangkat kasir panduan awal', () => {
    it('PIN belum diatur: formulir PIN 6 angka tampil di langkah ini', () => {
        RenderUji(<HalamanPerangkatPanduan {...BuatProps(false)} />);

        expect(screen.getByRole('region', { name: 'Atur PIN kasir Anda' })).not.toBeNull();
        expect(screen.getAllByLabelText(/PIN baru \(6 angka\)/).length).toBeGreaterThan(0);
        expect(screen.getByRole('button', { name: 'Simpan PIN' })).not.toBeNull();
    });

    it('PIN sudah diatur: hanya tautan ganti PIN, tanpa formulir', () => {
        RenderUji(<HalamanPerangkatPanduan {...BuatProps(true)} />);

        expect(screen.queryByRole('region', { name: 'Atur PIN kasir Anda' })).toBeNull();
        expect(screen.getByRole('link', { name: 'Keamanan akun › PIN kasir' })).not.toBeNull();
    });
});
