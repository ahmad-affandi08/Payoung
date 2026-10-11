import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import BagianAddon, { type AddonDimiliki, type DataAddonLangganan } from '@/Komponen/Langganan/BagianAddon';
import DialogNaikPaket from '@/Komponen/Langganan/DialogNaikPaket';
import RincianTagihan from '@/Komponen/Langganan/RincianTagihan';
import type { PenawaranFitur } from '@/Tipe/Aplikasi';
import type { TagihanLangganan } from '@/Tipe/TagihanLangganan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const milikToko: AddonDimiliki = {
    Uuid: 'AD-1',
    Kode: 'TOKO_ONLINE',
    Nama: 'Toko online',
    Fitur: 'Toko online',
    HargaBulanan: '79000.00',
    Jumlah: 1,
    MulaiPada: '2026-09-23T01:00:00Z',
    SelesaiPada: '2026-10-01T03:00:00Z',
    Aktif: true,
    Berhenti: false,
};

const dasar: DataAddonLangganan = {
    Alasan: null,
    Dimiliki: [milikToko],
    Tersedia: [
        {
            Kode: 'PERANGKAT_TAMBAHAN',
            Nama: 'Perangkat tambahan',
            Fitur: null,
            HargaBulanan: '29000.00',
            BisaJumlah: true,
            HargaProrata: '8700',
        },
        {
            Kode: 'INSIGHT',
            Nama: 'Forecast & insight',
            Fitur: 'Forecast & insight',
            HargaBulanan: '59000.00',
            BisaJumlah: false,
            HargaProrata: null,
        },
    ],
};

describe('Langganan: add-on mandiri (D-49)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/langganan'));
    afterEach(() => cleanup());

    it('add-on dimiliki: berhenti berlangganan mengirim POST; yang sudah berhenti bisa dilanjutkan', () => {
        render(<BagianAddon addon={dasar} />);
        expect(screen.getByText(/diperpanjang otomatis/)).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Berhenti berlangganan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith('/kelola/langganan/addon/AD-1/berhenti', {}, expect.anything());

        cleanup();
        render(<BagianAddon addon={{ ...dasar, Dimiliki: [{ ...milikToko, Berhenti: true }] }} />);
        expect(screen.getByText(/tidak diperpanjang/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Lanjutkan langganan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith('/kelola/langganan/addon/AD-1/lanjut', {}, expect.anything());
    });

    it('beli add-on: jumlah hanya untuk add-on penambah batas; perkiraan prorata tampil; alasan menonaktifkan tombol', () => {
        render(<BagianAddon addon={dasar} />);
        const daftar = screen.getByRole('list', { name: 'Add-on yang bisa dibeli' });
        const [perangkat, insight] = within(daftar).getAllByRole('listitem') as [HTMLElement, HTMLElement];
        expect(within(perangkat).getByText(/tagihan pertama Rp 8\.700/)).toBeTruthy();

        fireEvent.change(within(perangkat).getByLabelText('Jumlah'), { target: { value: '3' } });
        fireEvent.click(within(perangkat).getByRole('button', { name: 'Beli add-on' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/langganan/addon/beli',
            { KodeAddon: 'PERANGKAT_TAMBAHAN', Jumlah: 3 },
            expect.anything(),
        );

        expect(within(insight).queryByLabelText('Jumlah')).toBeNull();

        cleanup();
        render(<BagianAddon addon={{ ...dasar, Alasan: 'Perpanjangan paket Anda belum dibayar.' }} />);
        expect(screen.getByText('Perpanjangan paket Anda belum dibayar.')).toBeTruthy();
        expect(screen.getAllByRole('button', { name: 'Beli add-on' })[0]).toHaveProperty('disabled', true);
    });

    it('dialog fitur terkunci: bisa dibeli → "Beli add-on" + perkiraan; belum bisa → alasan + permintaan lewat tim', () => {
        const addonToko = {
            Kode: 'TOKO_ONLINE',
            Nama: 'Toko online',
            HargaBulanan: '79000.00',
            BisaDibeli: true,
            AlasanTidakBisa: null,
            HargaProrata: '21000',
        };
        const penawaran: PenawaranFitur = {
            Nama: 'Toko online',
            Paket: null,
            Addon: {
                Kode: 'TOKO_ONLINE',
                Nama: 'Toko online',
                HargaBulanan: '79000.00',
                BisaDibeli: true,
                AlasanTidakBisa: null,
                HargaProrata: '21000',
            },
        };
        render(
            <DialogNaikPaket
                kunci="kanal.toko-online"
                penawaran={penawaran}
                namaPaket="Pro"
                bolehKelola
                saatTutup={() => undefined}
            />,
        );
        expect(screen.getByText(/tagihan pertama Rp 21\.000/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Beli add-on Toko online' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/langganan/addon/beli',
            { KodeAddon: 'TOKO_ONLINE' },
            expect.anything(),
        );
        cleanup();

        const belum: PenawaranFitur = {
            ...penawaran,
            Addon: {
                ...addonToko,
                BisaDibeli: false,
                AlasanTidakBisa: 'Selama masa coba, pilih paket dulu.',
                HargaProrata: null,
            },
        };
        render(
            <DialogNaikPaket
                kunci="kanal.toko-online"
                penawaran={belum}
                namaPaket="Pro"
                bolehKelola
                saatTutup={() => undefined}
            />,
        );
        expect(screen.getByText('Selama masa coba, pilih paket dulu.')).toBeTruthy();
        expect(screen.queryByRole('button', { name: /Beli add-on/ })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Minta lewat tim kami' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/langganan/addon',
            { KunciFitur: 'kanal.toko-online' },
            expect.anything(),
        );
    });

    it('rincian tagihan add-on: baris prorata tanpa baris paket; perpanjangan: paket + add-on', () => {
        const rincianToko = {
            KodeAddon: 'TOKO_ONLINE',
            NamaAddon: 'Toko online',
            Jumlah: 1,
            HargaBulanan: '60000.00',
            JumlahBulan: 1,
            Prorata: true,
            HariDitagih: 9,
            HariPeriode: 30,
            Subtotal: '18000.00',
        };
        const tagihan: TagihanLangganan = {
            Uuid: 'TG-A',
            Nomor: 'INV/2026/09/000002',
            Jenis: 'Addon',
            LabelJenis: 'Pembelian add-on',
            Status: 'Terbit',
            LabelStatus: 'Belum dibayar',
            KodePaket: 'PRO',
            NamaPaket: 'Pro',
            Siklus: 'Bulanan',
            JumlahBulan: 1,
            Subtotal: '18000.00',
            SubtotalPaket: '0.00',
            NaikPaket: null,
            RincianAddon: [
                {
                    KodeAddon: 'TOKO_ONLINE',
                    NamaAddon: 'Toko online',
                    Jumlah: 1,
                    HargaBulanan: '60000.00',
                    JumlahBulan: 1,
                    Prorata: true,
                    HariDitagih: 9,
                    HariPeriode: 30,
                    Subtotal: '18000.00',
                },
            ],
            KodeKupon: null,
            Diskon: '0.00',
            TarifPpn: '0.000000',
            PengaliDppPembilang: 1,
            PengaliDppPenyebut: 1,
            DasarPengenaanPajak: '0.00',
            JumlahPpn: '0.00',
            Total: '18000.00',
            TerbitPada: '2026-09-23T01:00:00Z',
            JatuhTempoPada: '2026-09-30T01:00:00Z',
            DibayarPada: null,
            DibatalkanPada: null,
            PeriodeMulai: null,
            PeriodeSelesai: null,
        };
        render(<RincianTagihan tagihan={tagihan} />);
        expect(screen.getByText(/Add-on Toko online/)).toBeTruthy();
        expect(screen.getByText('Prorata 9 dari 30 hari periode berjalan')).toBeTruthy();
        expect(screen.queryByText(/^Paket Pro/)).toBeNull();
        cleanup();

        render(
            <RincianTagihan
                tagihan={{
                    ...tagihan,
                    Jenis: 'Perpanjangan',
                    LabelJenis: 'Perpanjangan langganan',
                    Subtotal: '259000.00',
                    SubtotalPaket: '199000.00',
                    RincianAddon: [
                        {
                            ...rincianToko,
                            Prorata: false,
                            HariDitagih: null,
                            HariPeriode: null,
                            Subtotal: '60000.00',
                        },
                    ],
                }}
            />,
        );
        expect(screen.getByText(/^Paket Pro/)).toBeTruthy();
        expect(screen.getByText('Rp 199.000')).toBeTruthy();
        expect(screen.getByText('Rp 60.000')).toBeTruthy();
    });
});
