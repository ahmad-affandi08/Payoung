import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanLangganan from '@/Halaman/Kelola/Langganan/Indeks';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { HariTersisa, KalimatSisaHari, PersenPeriodeTerpakai } from '@/Komponen/Langganan/KepalaPaket';
import PenunjukTagihan, { IndeksLangkahTagihan } from '@/Komponen/Langganan/PenunjukTagihan';
import { KeteranganPemakaian, PersenPemakaian } from '@/Komponen/Langganan/PemakaianPaket';
import type { PilihanPaket } from '@/Komponen/Langganan/FormPilihPaket';
import type { RingkasanLangganan } from '@/Tipe/Langganan';
import type { TagihanLangganan } from '@/Tipe/TagihanLangganan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/* D-332: halaman Langganan (kepala paket + empat tab) dan penunjuk perjalanan tagihan. */

const langganan: RingkasanLangganan = {
    Status: 'Aktif',
    LabelStatus: 'Aktif',
    KodePaket: 'PRO',
    NamaPaket: 'Pro',
    SiklusTagihan: 'Tahunan',
    JumlahBulanSiklus: 12,
    TrialBerakhirPada: null,
    PeriodeMulai: '2026-01-01T00:00:00Z',
    PeriodeSelesai: '2026-12-31T00:00:00Z',
    BatasTenggangPada: '2027-01-07T00:00:00Z',
};

const tagihanTerbuka: TagihanLangganan = {
    Uuid: 'TG-1',
    Nomor: 'TL/2611/0042',
    Jenis: 'Perpanjangan',
    LabelJenis: 'Perpanjangan',
    Status: 'Terbit',
    Manual: false,
    Keterangan: null,
    LabelStatus: 'Menunggu pembayaran',
    KodePaket: 'PRO',
    NamaPaket: 'Pro',
    Siklus: 'Tahunan',
    JumlahBulan: 12,
    Subtotal: '1788000.00',
    SubtotalPaket: '1788000.00',
    RincianAddon: [],
    NaikPaket: null,
    KodeKupon: null,
    Diskon: '178800.00',
    TarifPpn: '11.000000',
    PengaliDppPembilang: 1,
    PengaliDppPenyebut: 1,
    DasarPengenaanPajak: '1609200.00',
    JumlahPpn: '177012.00',
    Total: '1786212.00',
    TerbitPada: '2026-11-20T02:00:00Z',
    JatuhTempoPada: '2026-12-24T02:00:00Z',
    DibayarPada: null,
    DibatalkanPada: null,
    AlasanBatal: null,
    DihapusBukuPada: null,
    AlasanHapusBuku: null,
    DikembalikanPada: null,
    PeriodeMulai: null,
    PeriodeSelesai: null,
};

const tagihanLunas: TagihanLangganan = {
    ...tagihanTerbuka,
    Uuid: 'TG-0',
    Nomor: 'TL/2601/0007',
    Status: 'Lunas',
    LabelStatus: 'Lunas',
    TerbitPada: '2026-01-02T02:00:00Z',
    JatuhTempoPada: '2026-01-15T02:00:00Z',
};

const paket: PilihanPaket[] = [
    {
        Kode: 'PRO',
        Nama: 'Pro',
        Keterangan: null,
        HargaBulanan: '149000.00',
        HargaTahunan: '1788000.00',
        HargaPerLama: [{ JumlahBulan: 12, Subtotal: '1609200.00', HargaPenuh: '1788000.00', DiskonPersen: '10' }],
        PaketBerjalan: true,
        NaikPaket: null,
        BisaDipilih: true,
    },
];

function RenderLangganan(tagihan: TagihanLangganan[]) {
    return RenderUji(
        <HalamanLangganan
            Langganan={langganan}
            Addon={{ Alasan: null, Dimiliki: [], Tersedia: [] }}
            PilihanPaket={paket}
            AturanLama={{ MinimalBulan: 12, MaksimalBulan: 12, TanggaDiskon: [] }}
            Tagihan={tagihan}
            HariMasaTenggang={7}
            Kapasitas={{ Outlet: { Batas: 3, Terpakai: 2 }, Pengguna: { Batas: 10, Terpakai: 9 } }}
        />,
    );
}

describe('Langganan (D-332): kepala paket dan tab', () => {
    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-11-25T03:00:00Z'));
        AturHalamanUji({}, '/kelola/langganan');
        tiruanRouter.visit.mockClear();
    });
    afterEach(() => {
        cleanup();
        vi.useRealTimers();
    });

    it('kepala menampilkan paket, status, sisa hari, dan aksi perpanjang', () => {
        RenderLangganan([tagihanTerbuka, tagihanLunas]);

        const kepala = screen.getByRole('region', { name: 'Paket langganan' });
        expect(within(kepala).getByText('Pro')).toBeTruthy();
        expect(within(kepala).getByText('Aktif')).toBeTruthy();
        expect(within(kepala).getByText('36 hari lagi')).toBeTruthy();
        expect(within(kepala).getByText('Langganan 12 bulan')).toBeTruthy();
        expect(
            within(kepala).getByRole('progressbar', { name: 'Bagian masa langganan yang sudah berjalan' }),
        ).toBeTruthy();
        expect(within(kepala).getByRole('button', { name: 'Perpanjang sekarang' })).toBeTruthy();
        expect(within(kepala).getByRole('button', { name: 'Lihat paket lain' })).toBeTruthy();
    });

    it('Ikhtisar memuat meteran outlet dan pengguna, tab Tagihan menandai tagihan yang menunggu', () => {
        RenderLangganan([tagihanTerbuka, tagihanLunas]);

        expect(screen.getByRole('progressbar', { name: 'Outlet terpakai 67 persen' })).toBeTruthy();
        expect(screen.getByRole('progressbar', { name: 'Pengguna terpakai 90 persen' })).toBeTruthy();
        expect(screen.getByText('Hampir penuh, sisa 1 pengguna')).toBeTruthy();
        expect(screen.getByRole('tab', { name: /Tagihan.*1 menunggu/ })).toBeTruthy();
        expect(screen.getByRole('tab', { name: 'Ikhtisar' }).getAttribute('aria-selected')).toBe('true');
    });

    it('tagihan berikutnya menunjukkan total dan tombol Bayar tagihan membuka halaman tagihan', () => {
        RenderLangganan([tagihanTerbuka, tagihanLunas]);

        const panel = screen.getByRole('region', { name: 'Tagihan berikutnya' });
        expect(within(panel).getByText('Rp 1.786.212')).toBeTruthy();
        expect(within(panel).getByText('TL/2611/0042 | Perpanjangan')).toBeTruthy();

        fireEvent.click(within(panel).getByRole('button', { name: 'Bayar tagihan' }));
        expect(tiruanRouter.visit).toHaveBeenCalledWith('/kelola/langganan/tagihan/TG-1');
    });

    it('tanpa tagihan terbuka: tidak ada tagihan menunggu dan Perpanjang sekarang membuka tab Paket & harga dengan ringkasan', () => {
        RenderLangganan([tagihanLunas]);

        const panel = screen.getByRole('region', { name: 'Tagihan berikutnya' });
        expect(within(panel).getByText(/Tidak ada tagihan yang menunggu pembayaran/)).toBeTruthy();
        expect(screen.getByRole('tab', { name: 'Tagihan' })).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Perpanjang sekarang' }));

        expect(screen.getByRole('tab', { name: 'Paket & harga' }).getAttribute('aria-selected')).toBe('true');
        expect(window.location.search).toContain('tab=paket');
        const ringkasan = screen.getByRole('region', { name: 'Ringkasan' });
        expect(within(ringkasan).getByText('Rp 1.609.200')).toBeTruthy();
        expect(within(ringkasan).getByText(/Hemat 10% dari harga penuh Rp 1.788.000/)).toBeTruthy();
        expect(within(ringkasan).getByRole('button', { name: 'Buat tagihan' })).toBeTruthy();
    });

    it('dengan tagihan terbuka, tab Paket & harga meminta menyelesaikan tagihan dulu, bukan membuat tagihan baru', () => {
        RenderLangganan([tagihanTerbuka]);

        fireEvent.click(screen.getByRole('tab', { name: 'Paket & harga' }));

        expect(screen.getByText('Tagihan TL/2611/0042 menunggu pembayaran')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Buat tagihan' })).toBeNull();
    });
});

describe('Langganan (D-332): perhitungan tampilan', () => {
    const sekarang = new Date('2026-11-25T03:00:00Z').getTime();

    it('sisa hari dibulatkan ke atas dan kalimatnya mengikuti keadaan', () => {
        expect(HariTersisa('2026-12-31T00:00:00Z', sekarang)).toBe(36);
        expect(HariTersisa('2026-11-25T05:00:00Z', sekarang)).toBe(1);
        expect(HariTersisa('2026-11-25T03:00:00Z', sekarang)).toBe(0);
        expect(HariTersisa('2026-11-20T00:00:00Z', sekarang)).toBeLessThan(0);
        expect(HariTersisa(null, sekarang)).toBeNull();
        expect(KalimatSisaHari(36, 'Aktif')).toBe('36 hari lagi');
        expect(KalimatSisaHari(0, 'Aktif')).toBe('Berakhir hari ini');
        expect(KalimatSisaHari(-3, 'Tertunggak')).toBe('Sudah berakhir');
        expect(KalimatSisaHari(null, 'Gratis')).toBe('Tanpa batas waktu');
        expect(KalimatSisaHari(null, 'Berhenti')).toBe('—');
    });

    it('persen periode dibatasi 0 sampai 100 dan null bila periode tidak sah', () => {
        expect(PersenPeriodeTerpakai('2026-01-01T00:00:00Z', '2026-12-31T00:00:00Z', sekarang)).toBe(90);
        expect(PersenPeriodeTerpakai('2026-01-01T00:00:00Z', '2026-12-31T00:00:00Z', 0)).toBe(0);
        expect(PersenPeriodeTerpakai('2026-01-01T00:00:00Z', '2026-12-31T00:00:00Z', 9e15)).toBe(100);
        expect(PersenPeriodeTerpakai('2026-12-31T00:00:00Z', '2026-01-01T00:00:00Z', sekarang)).toBeNull();
        expect(PersenPeriodeTerpakai(null, '2026-12-31T00:00:00Z', sekarang)).toBeNull();
    });

    it('pemakaian paket: persen, sisa, hampir penuh, penuh, dan tanpa batas', () => {
        expect(PersenPemakaian({ Batas: 3, Terpakai: 2 })).toBe(67);
        expect(PersenPemakaian({ Batas: 3, Terpakai: 5 })).toBe(100);
        expect(PersenPemakaian({ Batas: null, Terpakai: 5 })).toBeNull();
        expect(KeteranganPemakaian({ Batas: 10, Terpakai: 2 }, 'outlet')).toBe('Sisa 8 outlet');
        expect(KeteranganPemakaian({ Batas: 10, Terpakai: 9 }, 'pengguna')).toBe('Hampir penuh, sisa 1 pengguna');
        expect(KeteranganPemakaian({ Batas: 3, Terpakai: 3 }, 'outlet')).toBe(
            'Penuh, naik paket untuk menambah outlet',
        );
        expect(KeteranganPemakaian({ Batas: null, Terpakai: 3 }, 'outlet')).toBe('Tanpa batas di paket Anda');
    });
});

describe('Tagihan (D-332): penunjuk perjalanan', () => {
    afterEach(() => cleanup());

    it('langkah mengikuti status tagihan', () => {
        expect(IndeksLangkahTagihan('Terbit')).toBe(1);
        expect(IndeksLangkahTagihan('JatuhTempo')).toBe(1);
        expect(IndeksLangkahTagihan('Lunas')).toBe(4);
        expect(IndeksLangkahTagihan('Dibatalkan')).toBeNull();
    });

    it('menunggu pembayaran menandai langkah kedua sebagai langkah berjalan', () => {
        render(<PenunjukTagihan status="Terbit" labelStatus="Menunggu pembayaran" />);

        const daftar = screen.getByRole('list', { name: 'Perjalanan tagihan' });
        const butir = within(daftar).getAllByRole('listitem');
        expect(butir).toHaveLength(4);
        expect(butir[1]?.getAttribute('aria-current')).toBe('step');
        expect(butir[0]?.getAttribute('aria-current')).toBeNull();
        expect(within(daftar).getByText('Menunggu pembayaran')).toBeTruthy();
    });

    it('tagihan dibatalkan tidak punya alur, hanya status', () => {
        render(<PenunjukTagihan status="Dibatalkan" labelStatus="Dibatalkan" />);

        expect(screen.queryByRole('list', { name: 'Perjalanan tagihan' })).toBeNull();
        expect(screen.getByText('Dibatalkan')).toBeTruthy();
    });
});
