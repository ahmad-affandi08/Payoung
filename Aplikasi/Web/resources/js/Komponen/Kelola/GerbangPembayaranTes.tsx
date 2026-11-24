import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanGerbangPembayaran, { LangkahQris } from '@/Halaman/Kelola/Pembayaran/Gerbang';
import type { GerbangPembayaranTenant, OpsiPenyediaGerbang } from '@/Tipe/Pembayaran';

/*
 * F-08 v2.06 gerbang pembayaran milik toko: bidang mengikuti penyedia, kredensial tersimpan hanya petunjuk,
 * aktifkan hanya setelah uji berhasil, URL webhook bisa disalin, dan isian ikut terkirim.
 */

const uji = vi.hoisted(() => ({
    kiriman: [] as { url: string; data: unknown }[],
    router: [] as string[],
    halaman: { props: { errors: {} } as Record<string, unknown>, url: '/kelola/pembayaran/gerbang' },
}));

vi.mock('@inertiajs/react', () => {
    function useForm<T extends object>(awal: T) {
        const [data, AturData] = useState<T>(awal);

        return {
            data,
            setData: (kunci: keyof T, nilai: unknown) => AturData((lama) => ({ ...lama, [kunci]: nilai })),
            errors: {},
            processing: false,
            isDirty: false,
            post: (url: string) => uji.kiriman.push({ url, data }),
        };
    }

    return {
        Head: () => null,
        Link: ({ href, children }: { href: string; children?: ReactNode }) => <a href={href}>{children}</a>,
        router: { post: (url: string) => uji.router.push(url), get: vi.fn() },
        usePage: () => uji.halaman,
        useForm,
    };
});

vi.mock('@/TataLetak/TataLetakAplikasi', () => ({
    default: ({ judul, children }: { judul: string; children: ReactNode }) => (
        <main>
            <h1>{judul}</h1>
            {children}
        </main>
    ),
}));

const penyedia: OpsiPenyediaGerbang[] = [
    {
        Nilai: 'Doku',
        Label: 'DOKU',
        Keterangan: 'DOKU SNAP: QRIS dinamis langsung bisa dipindai.',
        BidangPengaturan: [
            { Kunci: 'IdKlien', Label: 'Client ID', Jenis: 'Teks', Wajib: true },
            { Kunci: 'IdPedagangQris', Label: 'Merchant ID QRIS', Jenis: 'Teks', Wajib: true },
            { Kunci: 'IdTerminalQris', Label: 'Terminal ID QRIS', Jenis: 'Teks', Wajib: true },
            { Kunci: 'KodePosQris', Label: 'Kode pos QRIS', Jenis: 'Teks', Wajib: true },
        ],
        BidangKredensial: [{ Kunci: 'KunciRahasia', Label: 'Secret key', Wajib: true }],
    },
];
const lingkungan = [
    { Nilai: 'Sandbox', Label: 'Sandbox (uji coba)' },
    { Nilai: 'Produksi', Label: 'Produksi (uang sungguhan)' },
];

function Gerbang(ubah: Partial<GerbangPembayaranTenant> = {}): GerbangPembayaranTenant {
    return {
        SumberAkun: 'Sendiri',
        Uuid: '01K5GERBANG000000000000001',
        Penyedia: 'Doku',
        LabelPenyedia: 'DOKU',
        PenyediaDiizinkan: true,
        Lingkungan: 'Sandbox',
        Pengaturan: { IdKlien: 'BRN-0012-7788', IdPedagangQris: '111642', IdTerminalQris: 'A01', KodePosQris: '57214' },
        PetunjukKredensial: { KunciRahasia: '••••7788' },
        StatusUji: 'BelumDiuji',
        LabelStatusUji: 'Belum diuji',
        PesanUji: null,
        DiujiPada: null,
        Aktif: false,
        UrlWebhook: 'https://payoung.id/webhook/doku/1a-TokenWebhookToko',
        KunciPublikSnap: '-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkq\n-----END PUBLIC KEY-----',
        UrlToken: 'https://payoung.id/webhook/doku/1a-TokenWebhookToko/authorization/v1/access-token/b2b',
        WebhookDiterimaPada: null,
        WebhookDitolakPada: null,
        ...ubah,
    };
}

beforeEach(() => {
    uji.kiriman.length = 0;
    uji.router.length = 0;
});
afterEach(() => cleanup());

describe('Gerbang pembayaran toko (v2.06)', () => {
    it('belum terhubung (hanya DOKU): tidak ada pilihan penyedia, bidang DOKU tampil lalu isian terkirim', () => {
        render(<HalamanGerbangPembayaran Gerbang={null} DaftarPenyedia={penyedia} DaftarLingkungan={lingkungan} />);

        expect(screen.getByText('Hubungkan QRIS dalam 4 langkah')).toBeTruthy();
        expect(screen.queryByText(/\(selesai\)/)).toBeNull();
        expect(screen.queryByRole('combobox', { name: 'Penyedia' })).toBeNull();
        fireEvent.change(screen.getByLabelText(/Client ID/, { selector: 'input' }), {
            target: { value: 'BRN-0012-7788' },
        });
        fireEvent.change(screen.getByLabelText(/Merchant ID QRIS/, { selector: 'input' }), {
            target: { value: '111642' },
        });
        fireEvent.change(screen.getByLabelText(/Terminal ID QRIS/, { selector: 'input' }), {
            target: { value: 'A01' },
        });
        fireEvent.change(screen.getByLabelText(/Kode pos QRIS/, { selector: 'input' }), { target: { value: '57214' } });
        fireEvent.change(screen.getByLabelText(/Secret key/, { selector: 'input' }), {
            target: { value: 'SK-rahasia-toko-7788' },
        });

        fireEvent.click(screen.getByRole('button', { name: 'Simpan akun merchant' }));
        expect(uji.kiriman[0]).toEqual({
            url: '/kelola/pembayaran/gerbang',
            data: {
                Penyedia: 'Doku',
                Lingkungan: 'Sandbox',
                Pengaturan: {
                    IdKlien: 'BRN-0012-7788',
                    IdPedagangQris: '111642',
                    IdTerminalQris: 'A01',
                    KodePosQris: '57214',
                },
                Kredensial: { KunciRahasia: 'SK-rahasia-toko-7788' },
            },
        });
    });

    it('tersimpan belum diuji: petunjuk kredensial tampil, aktifkan nonaktif, uji koneksi terkirim', () => {
        render(
            <HalamanGerbangPembayaran Gerbang={Gerbang()} DaftarPenyedia={penyedia} DaftarLingkungan={lingkungan} />,
        );

        expect(screen.getByText(/Tersimpan ••••7788/)).toBeTruthy();
        expect(screen.getByText('https://payoung.id/webhook/doku/1a-TokenWebhookToko')).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Aktifkan gerbang' }) as HTMLButtonElement).disabled).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'Uji koneksi' }));
        expect(uji.router).toEqual(['/kelola/pembayaran/gerbang/uji']);
    });

    it('D-316 tenant uji bisa memakai akun DOKU Payoung, tenant biasa tidak melihat pilihannya', () => {
        const { unmount: Lepas } = render(
            <HalamanGerbangPembayaran Gerbang={null} DaftarPenyedia={penyedia} DaftarLingkungan={lingkungan} />,
        );
        expect(screen.queryByRole('button', { name: 'Pakai akun DOKU Payoung' })).toBeNull();
        Lepas();

        const { unmount: Lepas2 } = render(
            <HalamanGerbangPembayaran
                AkunPayoungTersedia
                Gerbang={null}
                DaftarPenyedia={penyedia}
                DaftarLingkungan={lingkungan}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Pakai akun DOKU Payoung' }));
        expect(uji.router).toEqual(['/kelola/pembayaran/gerbang/akun-payoung']);
        Lepas2();

        // Sudah memakai akun Payoung: peringatan tampil, URL notifikasi dan kunci publik tidak.
        render(
            <HalamanGerbangPembayaran
                AkunPayoungTersedia
                Gerbang={Gerbang({ SumberAkun: 'Payoung', KunciPublikSnap: null, Pengaturan: {} })}
                DaftarPenyedia={penyedia}
                DaftarLingkungan={lingkungan}
            />,
        );
        expect(screen.getByText(/Memakai akun DOKU Payoung \(khusus uji\)/)).toBeTruthy();
        expect(screen.queryByText('URL notifikasi (webhook)')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Pakai akun DOKU Payoung' })).toBeNull();
    });

    it('D-314 kunci publik SNAP dan URL token tampil untuk didaftarkan di DOKU, kunci bisa dibuat ulang', () => {
        render(
            <HalamanGerbangPembayaran Gerbang={Gerbang()} DaftarPenyedia={penyedia} DaftarLingkungan={lingkungan} />,
        );

        expect(screen.getByText(/BEGIN PUBLIC KEY/)).toBeTruthy();
        expect(screen.getByText(/access-token\/b2b$/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Buat ulang kunci' }));
        expect(uji.router).toEqual(['/kelola/pembayaran/gerbang/kunci-snap']);
    });

    it('uji berhasil: bisa diaktifkan. Penyedia dilarang platform menampilkan peringatan dan tidak bisa diaktifkan', () => {
        const { unmount: Lepas } = render(
            <HalamanGerbangPembayaran
                Gerbang={Gerbang({ StatusUji: 'Berhasil', LabelStatusUji: 'Uji berhasil' })}
                DaftarPenyedia={penyedia}
                DaftarLingkungan={lingkungan}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Aktifkan gerbang' }));
        expect(uji.router).toEqual(['/kelola/pembayaran/gerbang/aktifkan']);
        Lepas();

        render(
            <HalamanGerbangPembayaran
                Gerbang={Gerbang({ StatusUji: 'Berhasil', PenyediaDiizinkan: false })}
                DaftarPenyedia={penyedia}
                DaftarLingkungan={lingkungan}
            />,
        );
        expect(screen.getByText(/sedang tidak tersedia dari platform/)).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Aktifkan gerbang' }) as HTMLButtonElement).disabled).toBe(true);
    });

    it('#24 langkah QRIS ditandai selesai mengikuti status gerbang', () => {
        expect(LangkahQris(null).map((l) => l.Selesai)).toEqual([false, false, false, false]);
        expect(LangkahQris(Gerbang()).map((l) => l.Selesai)).toEqual([true, true, false, false]);
        expect(LangkahQris(Gerbang({ StatusUji: 'Berhasil' })).map((l) => l.Selesai)).toEqual([
            true,
            true,
            true,
            false,
        ]);
        expect(LangkahQris(Gerbang({ StatusUji: 'Berhasil', Aktif: true })).every((l) => l.Selesai)).toBe(true);
    });
});
