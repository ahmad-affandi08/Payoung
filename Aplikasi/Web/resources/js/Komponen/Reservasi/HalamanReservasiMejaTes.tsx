import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarReservasiMeja from '@/Halaman/Kelola/Reservasi/DaftarMeja';
import { BuatHasilTabel } from '@/Komponen/Katalog/DataUjiKatalog';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import type { BarisReservasiMeja, PropsDaftarReservasiMeja } from '@/Tipe/Reservasi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * FnB reservasi meja + uang muka (D-145): aksi baris mengikuti status & uang muka; membatalkan reservasi yang memegang
 * uang muka mewajibkan memilih nasibnya; meja difilter per outlet.
 */

const baris: BarisReservasiMeja = {
    Uuid: '01JRESERVASIMEJA000000001',
    Nomor: 'RM/2026/10/0001',
    MulaiPada: '2026-10-13T12:00:00Z',
    SelesaiPada: '2026-10-13T14:00:00Z',
    DurasiMenit: 120,
    NamaPemesan: 'Bapak Hendra Wijaya',
    NoHp: '0812-3456-7890',
    JumlahTamu: 4,
    Meja: { Uuid: '01JMEJA0000000000000000007', Nama: '7' },
    Area: null,
    Outlet: { Uuid: '01JOUTLET00000000000000001', Nama: 'Kedai Senja Solo' },
    Status: 'Dikonfirmasi',
    LabelStatus: 'Dikonfirmasi',
    Sumber: 'BackOffice',
    LabelSumber: 'Back-office',
    Catatan: null,
    AlasanBatal: null,
    BolehDiubah: true,
    BolehCatatDp: true,
    UangMuka: { Dicatat: '0.00', Terpakai: '0.00', Dikembalikan: '0.00', Hangus: '0.00', Sisa: '0.00' },
    StatusBerikutnya: ['Hadir', 'Dibatalkan', 'TidakDatang'],
};

function Props(
    ubah: Partial<PropsDaftarReservasiMeja> = {},
    barisUji: BarisReservasiMeja = baris,
): PropsDaftarReservasiMeja {
    return {
        Reservasi: BuatHasilTabel([barisUji]),
        OpsiStatus: [{ Nilai: 'Dikonfirmasi', Label: 'Dikonfirmasi' }],
        OpsiOutlet: [{ Uuid: '01JOUTLET00000000000000001', Nama: 'Kedai Senja Solo' }],
        PilihanMeja: {
            Meja: [
                {
                    Uuid: '01JMEJA0000000000000000007',
                    Nama: '7',
                    Kapasitas: 4,
                    UuidOutlet: '01JOUTLET00000000000000001',
                    NamaArea: 'Teras',
                },
                {
                    Uuid: '01JMEJA0000000000000000009',
                    Nama: '9',
                    Kapasitas: 2,
                    UuidOutlet: '01JOUTLET00000000000000001',
                    NamaArea: null,
                },
            ],
            Area: [],
        },
        AkunKasBank: [{ Uuid: '01JAKUN00000000000000001', Nama: '1-1100 | Kas Besar' }],
        HariIni: '2026-10-13',
        ...ubah,
    };
}

describe('Reservasi meja (D-145)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/reservasi-meja'));
    afterEach(() => cleanup());

    it('aksi baris: datang, ubah, catat uang muka, tidak datang, batalkan; tanpa uang muka tampil "Tanpa uang muka"', () => {
        RenderUji(<HalamanDaftarReservasiMeja {...Props()} />);
        expect(screen.getAllByText('Bapak Hendra Wijaya').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Tanpa uang muka').length).toBeGreaterThan(0);

        BukaMenu(screen.getAllByRole('button', { name: /Aksi/ })[0] as HTMLElement);
        expect(screen.getByRole('menuitem', { name: 'Tamu sudah datang' })).toBeTruthy();
        expect(screen.getByRole('menuitem', { name: 'Ubah reservasi' })).toBeTruthy();
        expect(screen.getByRole('menuitem', { name: 'Catat uang muka' })).toBeTruthy();
        expect(screen.getByRole('menuitem', { name: 'Tamu tidak datang' })).toBeTruthy();
        expect(screen.getByRole('menuitem', { name: 'Batalkan' })).toBeTruthy();
    });

    it('uang muka tercatat tampil bersama sisanya; batal mewajibkan memilih nasib uang muka', () => {
        const dengan: BarisReservasiMeja = {
            ...baris,
            BolehCatatDp: false,
            UangMuka: {
                Dicatat: '100000.00',
                Terpakai: '0.00',
                Dikembalikan: '0.00',
                Hangus: '0.00',
                Sisa: '100000.00',
            },
        };
        RenderUji(<HalamanDaftarReservasiMeja {...Props({}, dengan)} />);
        expect(screen.getAllByText(/Sisa Rp\s?100\.000/).length).toBeGreaterThan(0);

        BukaMenu(screen.getAllByRole('button', { name: /Aksi/ })[0] as HTMLElement);
        expect(screen.queryByRole('menuitem', { name: 'Catat uang muka' })).toBeNull();
        fireEvent.click(screen.getByRole('menuitem', { name: 'Batalkan' }));

        expect(screen.getByText(/Masih ada uang muka/)).toBeTruthy();
        expect(screen.getByRole('combobox', { name: /Uang muka/ })).toBeTruthy();
        expect(screen.getByRole('textbox', { name: /Alasan pembatalan/ })).toBeTruthy();
    });

    it('catat reservasi: meja menampilkan kapasitas dan jam wajib diisi', () => {
        RenderUji(<HalamanDaftarReservasiMeja {...Props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Catat reservasi' }));

        expect(screen.getByRole('dialog', { name: 'Catat reservasi meja' })).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Simpan reservasi' }) as HTMLButtonElement).disabled).toBe(true);
        fireEvent.click(screen.getByRole('combobox', { name: /^Meja/ }));
        expect(screen.getByRole('option', { name: /Meja 7/ })).toBeTruthy();
        expect(screen.getByText(/4 kursi \| Teras/)).toBeTruthy();
    });
});
