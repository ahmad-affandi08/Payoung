import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanGiftCard from '@/Halaman/Kelola/Pelanggan/GiftCard';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel } from '@/Komponen/Persediaan/DataUjiPersediaan';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import type { BarisGiftCard, PropsGiftCard } from '@/Tipe/Pelanggan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const alamat = '/kelola/pelanggan/gift-card';

function BuatKartu(tambahan: Partial<BarisGiftCard> = {}): BarisGiftCard {
    return {
        Uuid: '01K5GIFTCARD000000000000001',
        KodeSamar: 'GC-••••-••••-7QX4',
        NamaPemegang: 'Bu Ratna',
        NilaiAwal: '100000.00',
        Saldo: '100000.00',
        Status: 'Aktif',
        LabelStatus: 'Aktif',
        BerlakuSampai: '2026-12-31',
        DiterbitkanPada: '2026-10-01T03:00:00Z',
        ...tambahan,
    };
}

function BuatProps(tambahan: Partial<PropsGiftCard> = {}, kartu: BarisGiftCard[] = [BuatKartu()]): PropsGiftCard {
    return {
        GiftCard: BuatHasilTabel(kartu),
        Izin: { Kelola: true },
        Ringkasan: { KartuAktif: 3, SaldoBeredar: '250000.00' },
        Pengaturan: {
            Berlaku: true,
            MinimalNilai: '10000.00',
            MaksimalNilai: '5000000.00',
            MasaBerlakuBawaanHari: 365,
        },
        AkunKasBank: [{ Uuid: '01K5AKUNKAS000000000000001', Kode: '1-1100', Nama: 'Kas Outlet' }],
        KartuBaru: null,
        ...tambahan,
    };
}

beforeEach(() => AturHalamanUji({}, alamat));
afterEach(() => cleanup());

describe('CRM-06 halaman gift card', () => {
    it('menampilkan kode samar, saldo, masa berlaku, ringkasan, dan tab Gift card yang aktif', () => {
        RenderUji(<HalamanGiftCard {...BuatProps()} />);

        expect(screen.getAllByText('GC-••••-••••-7QX4').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Rp 100.000').length).toBeGreaterThan(0);
        expect(screen.getByText('Rp 250.000')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Gift card' }).getAttribute('aria-current')).toBe('page');
        expect(screen.getByRole('link', { name: 'Isi deposit' }).getAttribute('aria-current')).toBeNull();
    });

    it('jual gift card: nilai + akun + masa berlaku terkirim dengan Uuid sekali pakai', () => {
        RenderUji(<HalamanGiftCard {...BuatProps()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Jual gift card' }));
        fireEvent.change(screen.getByLabelText(/Nilai kartu/), { target: { value: '150000' } });
        fireEvent.change(screen.getByLabelText(/Nama pemegang/), { target: { value: 'Pak Budi' } });
        fireEvent.click(screen.getByRole('button', { name: 'Jual dan tampilkan kode' }));

        expect(tiruanRouter.post).toHaveBeenCalledTimes(1);
        const [url, isi] = tiruanRouter.post.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe(alamat);
        expect(isi).toMatchObject({
            Nilai: '150000',
            UuidAkun: '01K5AKUNKAS000000000000001',
            NamaPemegang: 'Pak Budi',
            MasaBerlakuHari: 365,
            Catatan: null,
        });
        expect(String(isi.Uuid)).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
    });

    it('kartu baru: kode lengkap tampil sekali di dialog rahasia dan baru hilang setelah dikonfirmasi', () => {
        RenderUji(
            <HalamanGiftCard
                {...BuatProps({
                    KartuBaru: {
                        Uuid: '01K5GIFTCARD000000000000002',
                        Kode: 'AB23-CD45-EF67',
                        Nilai: '150000.00',
                        BerlakuSampai: null,
                    },
                })}
            />,
        );

        expect(screen.getByText('Gift card senilai Rp 150.000 dijual')).toBeTruthy();
        expect(screen.getByText('AB23-CD45-EF67')).toBeTruthy();
        expect(screen.getByText('Tanpa batas masa berlaku.')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Saya sudah menyimpannya' }));
        expect(screen.queryByText('AB23-CD45-EF67')).toBeNull();
    });

    it('tanpa izin kelola tidak ada tombol jual dan menu baris hanya riwayat; paket tanpa fitur menyembunyikan tombol jual', () => {
        RenderUji(<HalamanGiftCard {...BuatProps({ Izin: { Kelola: false } })} />);
        expect(screen.queryByRole('button', { name: 'Jual gift card' })).toBeNull();
        BukaMenu(screen.getByRole('button', { name: /Aksi gift card/ }));
        expect(screen.getAllByRole('menuitem').map((m) => m.textContent)).toEqual(['Lihat riwayat']);
        cleanup();

        RenderUji(<HalamanGiftCard {...BuatProps({ Pengaturan: { ...BuatProps().Pengaturan, Berlaku: false } })} />);
        expect(screen.queryByRole('button', { name: 'Jual gift card' })).toBeNull();
    });

    it('batalkan & kembalikan uang hanya untuk kartu utuh; kartu terpakai hanya bisa dihanguskan; alasan ≥ 5 karakter', () => {
        const terpakai = BuatKartu({
            Uuid: '01K5GIFTCARD000000000000003',
            KodeSamar: 'GC-••••-••••-AAAA',
            Saldo: '60000.00',
        });
        RenderUji(<HalamanGiftCard {...BuatProps({}, [BuatKartu(), terpakai])} />);

        BukaMenu(screen.getByRole('button', { name: 'Aksi gift card GC-••••-••••-AAAA' }));
        expect(screen.queryByRole('menuitem', { name: 'Batalkan & kembalikan uang' })).toBeNull();
        fireEvent.click(screen.getByRole('menuitem', { name: 'Hanguskan sisa saldo' }));
        const tombol = screen.getByRole('button', { name: 'Hanguskan sisa saldo' }) as HTMLButtonElement;
        expect(tombol.disabled).toBe(true);
        fireEvent.change(screen.getByLabelText(/Alasan/), { target: { value: 'Kartu hilang' } });
        fireEvent.click(tombol);
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `${alamat}/${terpakai.Uuid}/hanguskan`,
            { Alasan: 'Kartu hilang' },
            expect.anything(),
        );
        cleanup();

        RenderUji(<HalamanGiftCard {...BuatProps()} />);
        BukaMenu(screen.getByRole('button', { name: 'Aksi gift card GC-••••-••••-7QX4' }));
        expect(screen.getByRole('menuitem', { name: 'Batalkan & kembalikan uang' })).toBeTruthy();
    });
});
