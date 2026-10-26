import { act, cleanup, fireEvent, screen, within } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanKeamananAkun from '@/Halaman/Autentikasi/KeamananAkun';
import HalamanPerangkat from '@/Halaman/Kelola/Perangkat/Daftar';
import HalamanPerangkatPanduan from '@/Halaman/Kelola/PanduanAwal/Perangkat';
import HalamanTokenApi from '@/Halaman/Kelola/Pengaturan/Api';
import HalamanWebhook from '@/Halaman/Kelola/Pengaturan/Webhook';
import KodePemulihanKonsol from '@/Halaman/Pengelola/DuaFaktor/KodePemulihan';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { JUDUL_PERINGATAN_RAHASIA, LABEL_SELESAI_RAHASIA } from '@/Komponen/Umpan/DialogRahasia';
import type { PropsHalamanTokenApi, PropsHalamanWebhook } from '@/Tipe/ApiPublik';
import type { KodeAktivasiBaru } from '@/Tipe/PanduanAwal';

import { BuatProgresContoh } from '../PanduanAwal/DataUjiPanduan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-103: semua nilai rahasia sekali-tampil (kode aktivasi perangkat, token API, rahasia webhook, kode pemulihan 2FA)
 * tampil di SATU bentuk dialog yang sama. Test ini memeriksa keenam halaman dengan pernyataan yang identik, supaya
 * perilaku tidak bisa menyimpang di salah satunya.
 */

const kodeAktivasi: KodeAktivasiBaru = {
    UuidPerangkat: '01K5PERANGKAT00000000000A1',
    NamaPerangkat: 'Kasir depan',
    KodePerangkat: 'K01',
    Kode: 'AB12-CD34',
    KedaluwarsaPada: '2026-10-12T03:00:00Z',
    QrSvg: '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
};

const propsToken: PropsHalamanTokenApi = {
    Token: [],
    OpsiCakupan: [{ Nilai: 'produk:baca', Label: 'Baca produk' }],
    TokenBaru: { Nama: 'Laporan BI', Token: 'payoung_12_' + 'x'.repeat(40) },
    AlamatApi: 'https://dashboard.payoung.id/api/v1',
};

const propsWebhook: PropsHalamanWebhook = {
    Webhook: [],
    Kiriman: [],
    OpsiPeristiwa: [{ Nilai: 'penjualan.selesai', Label: 'Penjualan selesai' }],
    RahasiaBaru: { Nama: 'Sistem gudang', Rahasia: 'r'.repeat(48) },
};

const akun: ComponentProps<typeof HalamanKeamananAkun>['Akun'] = {
    Email: 'rina@kopinusantara.id',
    EmailTerverifikasi: true,
    EmailDiverifikasiPada: '2026-10-01T01:00:00Z',
    BisaGantiEmail: true,
};

const kodePemulihan = ['AAAA-1111', 'BBBB-2222', 'CCCC-3333'];

type Kasus = {
    nama: string;
    judul: string | RegExp;
    nilai: string;
    Render: () => void;
    /**
     * Halaman yang berpindah setelah selesai (konsol): dialog tetap terpasang sampai navigasi Inertia mengganti
     * halaman, jadi tidak ada kilatan halaman di belakangnya. Tindakan yang diharapkan diperiksa di sini.
     */
    SetelahSelesai?: () => void;
};

const kasus: Kasus[] = [
    {
        nama: 'Perangkat kasir',
        judul: 'Aktifkan Kasir depan (K01)',
        nilai: 'AB12-CD34',
        Render: () =>
            RenderUji(
                <HalamanPerangkat
                    Perangkat={[]}
                    Outlet={[
                        {
                            Uuid: '01K0UTLT',
                            Kode: 'PST',
                            Nama: 'Toko Pusat',
                            BatasPerangkat: { Batas: 5, Terpakai: 0 },
                        },
                    ]}
                    JenisPerangkat={[{ Nilai: 'Kasir', Label: 'Kasir' }]}
                    KodeAktivasiBaru={kodeAktivasi}
                />,
            ),
    },
    {
        nama: 'Panduan awal › Perangkat',
        judul: 'Aktifkan Kasir depan (K01)',
        nilai: 'AB12-CD34',
        Render: () =>
            RenderUji(
                <HalamanPerangkatPanduan
                    Progres={BuatProgresContoh()}
                    Outlet={{
                        Uuid: '01K0UTLT',
                        Kode: 'PST',
                        Nama: 'Toko Pusat',
                        BatasPerangkat: { Batas: 5, Terpakai: 0 },
                    }}
                    Perangkat={[]}
                    KodeAktivasiBaru={kodeAktivasi}
                    BolehKelolaPerangkat
                    PinSayaDiatur
                />,
            ),
    },
    {
        nama: 'Token API',
        judul: 'Token Laporan BI dibuat',
        nilai: 'payoung_12_' + 'x'.repeat(40),
        Render: () => RenderUji(<HalamanTokenApi {...propsToken} />),
    },
    {
        nama: 'Webhook',
        judul: 'Webhook Sistem gudang dibuat',
        nilai: 'r'.repeat(48),
        Render: () => RenderUji(<HalamanWebhook {...propsWebhook} />),
    },
    {
        nama: 'Keamanan akun',
        judul: 'Verifikasi dua langkah aktif',
        nilai: 'AAAA-1111',
        Render: () =>
            RenderUji(
                <HalamanKeamananAkun
                    Akun={akun}
                    DuaFaktor={{ Aktif: true, AktifPada: '2026-10-11T01:00:00Z', SisaKodePemulihan: 3, Wajib: false }}
                    Aktivasi={null}
                    KodePemulihanBaru={kodePemulihan}
                    Google={{
                        Tersedia: false,
                        Tertaut: false,
                        TertautPada: null,
                        KataSandiOtomatis: false,
                        MasukDenganGoogle: false,
                    }}
                />,
            ),
    },
    {
        nama: 'Konsol › kode pemulihan',
        judul: 'Verifikasi dua langkah aktif',
        nilai: 'AAAA-1111',
        Render: () => RenderUji(<KodePemulihanKonsol KodePemulihan={kodePemulihan} />),
        SetelahSelesai: () => expect(tiruanRouter.visit).toHaveBeenCalledWith('/'),
    },
];

beforeEach(() => AturHalamanUji({}, '/kelola'));
afterEach(cleanup);

describe.each(kasus)('dialog rahasia sekali-tampil: $nama', (k) => {
    it('tampil sebagai satu dialog dengan peringatan, nilai, dan satu tombol selesai', () => {
        k.Render();

        const dialog = screen.getByRole('alertdialog', { name: k.judul });
        expect(screen.getAllByRole('alertdialog')).toHaveLength(1);
        expect(within(dialog).getByText(JUDUL_PERINGATAN_RAHASIA)).toBeTruthy();
        expect(within(dialog).getByText(k.nilai)).toBeTruthy();
        expect(within(dialog).getAllByRole('button', { name: LABEL_SELESAI_RAHASIA })).toHaveLength(1);
    });

    it('tidak ada kartu rahasia di halaman di luar dialog', () => {
        k.Render();

        const dialog = screen.getByRole('alertdialog');
        const semua = screen.getAllByText(k.nilai);
        expect(semua.every((el) => dialog.contains(el))).toBe(true);
    });

    it('Esc tidak menutup dialog. Tombol selesai menutupnya', () => {
        k.Render();

        fireEvent.keyDown(screen.getByRole('alertdialog'), { key: 'Escape' });
        expect(screen.queryByRole('alertdialog')).not.toBeNull();

        act(() => {
            fireEvent.click(screen.getByRole('button', { name: LABEL_SELESAI_RAHASIA }));
        });

        if (k.SetelahSelesai) {
            k.SetelahSelesai();
        } else {
            expect(screen.queryByRole('alertdialog')).toBeNull();
        }
    });

    it('menjaga pengguna dari tak sengaja menutup tab selama dialog terbuka', () => {
        k.Render();

        const peristiwa = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(peristiwa);
        expect(peristiwa.defaultPrevented).toBe(true);

        if (k.SetelahSelesai) {
            return;
        }

        fireEvent.click(screen.getByRole('button', { name: LABEL_SELESAI_RAHASIA }));
        const sesudah = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(sesudah);
        expect(sesudah.defaultPrevented).toBe(false);
    });
});

describe('tanpa nilai rahasia', () => {
    it('halaman token API tanpa TokenBaru tidak membuka dialog', () => {
        RenderUji(<HalamanTokenApi {...propsToken} TokenBaru={null} />);
        expect(screen.queryByRole('alertdialog')).toBeNull();
    });

    it('halaman webhook tanpa RahasiaBaru tidak membuka dialog', () => {
        RenderUji(<HalamanWebhook {...propsWebhook} RahasiaBaru={null} />);
        expect(screen.queryByRole('alertdialog')).toBeNull();
    });
});
