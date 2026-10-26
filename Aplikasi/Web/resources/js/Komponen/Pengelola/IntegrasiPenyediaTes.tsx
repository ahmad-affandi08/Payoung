import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanIntegrasi from '@/Halaman/Pengelola/Integrasi/Daftar';
import { PilihOpsi } from '@/Pengujian/InteraksiPilihan';
import { IzinPengelola, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

/*
 * v2.04 konsol integrasi: pilih penyedia → bidang & nilai bawaan berganti, peringatan WhatsApp tidak resmi tampil,
 * dan penyedia ikut terkirim.
 */

const uji = vi.hoisted(() => ({
    kiriman: [] as { url: string; data: unknown }[],
    halaman: { props: {} as Record<string, unknown>, url: '/integrasi' },
}));

vi.mock('@inertiajs/react', () => {
    function useForm<T extends object>(awal: T) {
        const [data, AturData] = useState<T>(awal);

        return {
            data,
            setData: (kunci: keyof T, nilai: unknown) => AturData((lama) => ({ ...lama, [kunci]: nilai })),
            errors: {},
            processing: false,
            post: (url: string) => uji.kiriman.push({ url, data }),
        };
    }

    return {
        Head: () => null,
        Link: ({ href, children }: { href: string; children?: ReactNode }) => <a href={href}>{children}</a>,
        router: { post: vi.fn(), get: vi.fn(), visit: vi.fn(), reload: vi.fn() },
        usePage: () => uji.halaman,
        useForm,
    };
});

function BidangSmtp(host: string, pengguna: string) {
    return [
        { Kunci: 'Host', Label: 'Host SMTP', Jenis: 'Teks' as const, Wajib: true, Bawaan: host },
        { Kunci: 'Port', Label: 'Port', Jenis: 'Angka' as const, Wajib: true, Bawaan: 587 },
        { Kunci: 'NamaPengguna', Label: 'Nama pengguna', Jenis: 'Teks' as const, Wajib: true, Bawaan: pengguna },
    ];
}

function Slot(
    jenis: string,
    daftar: { Nilai: string; Label: string; Keterangan: string; Resmi: boolean; host?: string }[],
) {
    const penyedia = daftar.map((p) => ({
        Nilai: p.Nilai,
        Label: p.Label,
        Keterangan: p.Keterangan,
        Resmi: p.Resmi,
        BidangPengaturan: BidangSmtp(p.host ?? '', p.Nilai === 'SendGrid' ? 'apikey' : ''),
        BidangKredensial: [{ Kunci: 'KataSandi', Label: `Kunci ${p.Label}`, Wajib: true }],
    }));
    const [pertama] = penyedia;
    if (!pertama) {
        throw new Error('Butuh minimal satu penyedia.');
    }

    return {
        Jenis: jenis,
        LabelJenis: jenis,
        Lingkungan: 'Staging' as const,
        LingkunganServer: true,
        Penyedia: { Nilai: pertama.Nilai, Label: pertama.Label },
        BidangPengaturan: pertama.BidangPengaturan,
        BidangKredensial: pertama.BidangKredensial,
        DaftarPenyedia: penyedia,
        Konfigurasi: null,
    };
}

beforeEach(() => {
    const props: PropsBersamaPengelola = {
        NamaAplikasi: 'Payoung',
        Lingkungan: 'Staging',
        Kilat: null,
        Pengguna: {
            Uuid: 'P1',
            Nama: 'Dewi',
            Email: 'dewi@contoh.id',
            KodePeran: [],
            Izin: [IzinPengelola.IntegrasiKelola],
        },
        PeringatanSuperAdmin: false,
        PeringatanIntegrasi: [],
        PeringatanOperasional: [],
        errors: {},
    };
    uji.halaman.props = props;
    uji.kiriman.length = 0;
});
afterEach(() => cleanup());

describe('Integrasi: pilih penyedia (v2.04)', () => {
    it('ganti penyedia email mengisi host & nama pengguna bawaan lalu penyedia ikut dikirim', () => {
        render(
            <HalamanIntegrasi
                Integrasi={[
                    Slot('Email', [
                        { Nilai: 'Smtp', Label: 'SMTP', Keterangan: '', Resmi: true },
                        {
                            Nilai: 'SendGrid',
                            Label: 'Twilio SendGrid',
                            Keterangan: 'Nama pengguna selalu "apikey".',
                            Resmi: true,
                            host: 'smtp.sendgrid.net',
                        },
                    ]),
                ]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Atur konfigurasi' }));
        PilihOpsi(screen.getByRole('combobox', { name: 'Penyedia' }), 'SendGrid');

        expect((screen.getByLabelText(/Host SMTP/) as HTMLInputElement).value).toBe('smtp.sendgrid.net');
        expect((screen.getByLabelText(/Nama pengguna/) as HTMLInputElement).value).toBe('apikey');
        expect(screen.getByText('Nama pengguna selalu "apikey".')).toBeTruthy();
        expect(screen.getByLabelText(/Kunci Twilio SendGrid/, { selector: 'input' })).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Simpan konfigurasi' }));
        expect(uji.kiriman[0]?.url).toBe('/integrasi');
        expect(uji.kiriman[0]?.data).toMatchObject({
            Jenis: 'Email',
            Penyedia: 'SendGrid',
            Pengaturan: { Host: 'smtp.sendgrid.net', Port: '587', NamaPengguna: 'apikey' },
        });
    });

    it('WhatsApp tidak resmi menampilkan peringatan risiko blokir', () => {
        render(
            <HalamanIntegrasi
                Integrasi={[
                    Slot('Whatsapp', [
                        {
                            Nilai: 'MetaCloud',
                            Label: 'WhatsApp Cloud API (resmi, Meta)',
                            Keterangan: 'Resmi.',
                            Resmi: true,
                        },
                        {
                            Nilai: 'Fonnte',
                            Label: 'Fonnte (tidak resmi)',
                            Keterangan: 'Nomor bisa diblokir WhatsApp.',
                            Resmi: false,
                        },
                    ]),
                ]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Atur konfigurasi' }));
        PilihOpsi(screen.getByRole('combobox', { name: 'Penyedia' }), 'Fonnte');
        const peringatan = screen.getByText('Nomor bisa diblokir WhatsApp.').closest('[data-jenis]');
        expect(peringatan?.getAttribute('data-jenis')).toBe('peringatan');
    });

    it('v2.06 katalog gerbang untuk toko (hanya DOKU): izinkan terkirim langsung, larang wajib lewat lembar beralasan', async () => {
        const { router } = await import('@inertiajs/react');
        const doku = {
            Penyedia: 'Doku',
            Label: 'DOKU',
            Diizinkan: true,
            JumlahTenant: 3,
            JumlahAktif: 2,
            JumlahUjiGagal: 1,
            WebhookDiterima24Jam: 2,
            WebhookDitolak24Jam: 0,
        };
        const { unmount: Lepas } = render(
            <HalamanIntegrasi Integrasi={[]} GerbangTenant={[{ ...doku, Diizinkan: false }]} />,
        );

        expect(screen.getByText('Gerbang pembayaran untuk toko')).toBeTruthy();
        expect(screen.getByText('Dilarang')).toBeTruthy();
        expect(screen.getAllByRole('heading', { level: 3 })).toHaveLength(1);
        fireEvent.click(screen.getByRole('button', { name: 'Izinkan DOKU' }));
        expect(router.post).toHaveBeenCalledWith(
            '/integrasi/gerbang-pembayaran/Doku',
            { Diizinkan: true, Alasan: '' },
            expect.anything(),
        );
        Lepas();

        render(<HalamanIntegrasi Integrasi={[]} GerbangTenant={[doku]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Larang DOKU' }));
        expect(screen.getByText(/2 toko aktif memakai penyedia ini/)).toBeTruthy();
        fireEvent.change(screen.getByLabelText(/Alasan/), { target: { value: 'Gangguan penyelesaian dana' } });
        fireEvent.click(screen.getByRole('button', { name: 'Larang penyedia' }));
        expect(router.post).toHaveBeenCalledWith(
            '/integrasi/gerbang-pembayaran/Doku',
            { Diizinkan: false, Alasan: 'Gangguan penyelesaian dana' },
            expect.anything(),
        );
    });
});

describe('Integrasi: ringkasan konfigurasi (T2)', () => {
    it('enkripsi tersimpan "Ssl" tampil sebagai SSL. Kata sandi hanya tampil sebagai titik', () => {
        const slot = Slot('Email', [{ Nilai: 'Smtp', Label: 'SMTP', Keterangan: '', Resmi: true }]);
        const bidang = [
            ...slot.BidangPengaturan,
            { Kunci: 'Enkripsi', Label: 'Enkripsi', Jenis: 'Pilihan' as const, Wajib: true, Opsi: ['Ssl', 'Tls'] },
        ];
        render(
            <HalamanIntegrasi
                Integrasi={[
                    {
                        ...slot,
                        BidangPengaturan: bidang,
                        Konfigurasi: {
                            Uuid: 'K1',
                            Pengaturan: {
                                Host: 'smtp.hostinger.com',
                                Port: 465,
                                NamaPengguna: 'halo@payoung.id',
                                Enkripsi: 'Ssl',
                            },
                            PetunjukKredensial: { KataSandi: '••••' },
                            Aktif: true,
                            Status: 'Terhubung',
                            LabelStatus: 'Terhubung',
                            TerakhirDiujiPada: null,
                            HasilUji: null,
                            KredensialDiubahPada: '2026-10-01T03:00:00Z',
                            RotasiSetiapHari: 90,
                            PerluRotasi: false,
                        },
                    },
                ]}
            />,
        );

        expect(screen.getByText('SSL')).toBeTruthy();
        expect(screen.queryByText('Ssl')).toBeNull();
        expect(screen.getByText('••••')).toBeTruthy();
    });
});
