import { cleanup, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import DialogKirimGrosir from '@/Komponen/Grosir/DialogKirimGrosir';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { PilihOpsi } from '@/Pengujian/InteraksiPilihan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

describe('Dialog kirim barang grosir: armada', () => {
    afterEach(() => cleanup());

    it('memilih armada mengisi nama pengemudi dan nomor kendaraan yang terkirim', () => {
        AturHalamanUji();
        RenderUji(
            <DialogKirimGrosir
                uuidPesanan="01J9PSN0000000000000000001"
                baris={[]}
                opsiGudang={[{ Uuid: 'G1', Nama: 'Gudang Utama', NamaOutlet: null } as never]}
                opsiArmada={[
                    { Uuid: 'A1', Nama: 'Colt Diesel 1', NomorKendaraan: 'AD 1234 XY', NamaPengemudi: 'Pak Slamet' },
                ]}
                hariIni="2026-10-26"
                saatTutup={vi.fn()}
            />,
        );

        PilihOpsi(screen.getByRole('combobox', { name: /Armada/ }), 'A1');

        expect((screen.getByLabelText('Nama pengirim') as HTMLInputElement).value).toBe('Pak Slamet');
        expect((screen.getByLabelText('Nomor kendaraan') as HTMLInputElement).value).toBe('AD 1234 XY');
    });

    it('tanpa armada terdaftar, pilihan armada tidak ditampilkan', () => {
        AturHalamanUji();
        RenderUji(
            <DialogKirimGrosir
                uuidPesanan="01J9PSN0000000000000000001"
                baris={[]}
                opsiGudang={[{ Uuid: 'G1', Nama: 'Gudang Utama', NamaOutlet: null } as never]}
                hariIni="2026-10-26"
                saatTutup={vi.fn()}
            />,
        );

        expect(screen.queryByRole('combobox', { name: /Armada/ })).toBeNull();
    });
});
