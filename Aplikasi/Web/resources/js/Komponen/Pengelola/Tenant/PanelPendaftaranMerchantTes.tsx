import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import PanelPendaftaranMerchant from '@/Komponen/Pengelola/Tenant/PanelPendaftaranMerchant';
import type { PendaftaranMerchantTenant, Tampilan360 } from '@/Tipe/TenantPengelola';

/*
 * Konsol pengelola: panel pendaftaran merchant pembayaran (DOKU Partner) di detail tenant. Hanya status & ID;
 * NIK/rekening tersamar; langkah berikutnya setelah disetujui; penampung merchantId/terminalId diisi manual.
 */

const uji = vi.hoisted(() => ({
    router: [] as string[],
    form: [] as { url: string; data: Record<string, unknown> }[],
}));

vi.mock('@inertiajs/react', () => {
    function useForm<T extends object>(awal: T) {
        const [data, AturData] = useState<T>(awal);

        return {
            data,
            setData: (kunci: keyof T, nilai: unknown) => AturData((lama) => ({ ...lama, [kunci]: nilai })),
            errors: {},
            processing: false,
            put: (url: string) => uji.form.push({ url, data: data as Record<string, unknown> }),
        };
    }

    return {
        Link: ({ href, children }: { href: string; children?: ReactNode }) => <a href={href}>{children}</a>,
        router: { post: (url: string) => uji.router.push(url) },
        useForm,
    };
});

beforeEach(() => {
    uji.router.length = 0;
    uji.form.length = 0;
});

afterEach(() => cleanup());

function Pendaftaran(ubah: Partial<PendaftaranMerchantTenant> = {}): PendaftaranMerchantTenant {
    return {
        Uuid: '01K5MERCHANT00000000000001',
        Status: 'Ditinjau',
        LabelStatus: 'Sedang ditinjau',
        NamaPemilik: 'Budi Santoso',
        NamaUsaha: 'Toko Kelontong Berkah Solo',
        NikTersamar: '••••0001',
        RekeningTersamar: '••••0123',
        IdBisnisDoku: 'BSN-0001-AAAA',
        IdBrandDoku: 'BRN-0099-BBBB',
        StatusDoku: 'UPDATING',
        PesanGalat: null,
        AlasanPenolakan: null,
        DikirimPada: '2026-11-24T03:00:00Z',
        DisetujuiPada: null,
        DiperiksaPada: '2026-11-24T03:30:00Z',
        CallbackDiterimaPada: null,
        IdPedagangQris: null,
        IdTerminalQris: null,
        BisaDisegarkan: true,
        ...ubah,
    };
}

function Data(pendaftaran: PendaftaranMerchantTenant | null, aktif = true): Tampilan360['PendaftaranMerchant'] {
    return { Pendaftaran: pendaftaran, PartnerAktif: aktif };
}

describe('PanelPendaftaranMerchant (konsol)', () => {
    it('belum ada pendaftaran: keadaan kosong, tombol segarkan nonaktif, tanpa form penampung', () => {
        render(<PanelPendaftaranMerchant uuidTenant="T-1" data={Data(null)} bolehKelola />);

        expect(screen.getByText('Tenant ini belum mengirim pendaftaran merchant.')).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Segarkan status' }) as HTMLButtonElement).disabled).toBe(true);
        expect(screen.queryByLabelText(/merchantId/)).toBeNull();
    });

    it('Ditinjau: status, ID bisnis/brand, NIK & rekening tersamar. Segarkan memanggil rute konsol', () => {
        render(<PanelPendaftaranMerchant uuidTenant="T-1" data={Data(Pendaftaran())} bolehKelola />);

        expect(screen.getByText('Sedang ditinjau')).toBeTruthy();
        expect(screen.getByText('BSN-0001-AAAA')).toBeTruthy();
        expect(screen.getByText('BRN-0099-BBBB')).toBeTruthy();
        expect(screen.getByText('••••0001')).toBeTruthy();
        expect(screen.getByText('••••0123')).toBeTruthy();
        expect(screen.queryByText(/Langkah berikutnya/)).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Segarkan status' }));
        expect(uji.router).toEqual(['/tenant/T-1/pendaftaran-merchant/segarkan']);
    });

    it('Aktif: menampilkan langkah berikutnya aktivasi QRIS di DOKU Dashboard (Nama Pendek Brand + MCC)', () => {
        render(
            <PanelPendaftaranMerchant
                uuidTenant="T-1"
                data={Data(Pendaftaran({ Status: 'Aktif', LabelStatus: 'Disetujui', StatusDoku: 'ACTIVE' }))}
                bolehKelola
            />,
        );

        expect(screen.getByText('Langkah berikutnya')).toBeTruthy();
        expect(screen.getByText(/Nama Pendek Brand dan MCC/)).toBeTruthy();
        expect(screen.getByText(/DOKU mengisi merchantId dan terminalId otomatis/)).toBeTruthy();
    });

    it('Ditolak: alasan dari DOKU tampil', () => {
        render(
            <PanelPendaftaranMerchant
                uuidTenant="T-1"
                data={Data(
                    Pendaftaran({ Status: 'Ditolak', LabelStatus: 'Ditolak', AlasanPenolakan: 'Foto KTP buram.' }),
                )}
                bolehKelola
            />,
        );

        expect(screen.getByText('Foto KTP buram.')).toBeTruthy();
    });

    it('penampung QRIS diisi manual dan disimpan lewat PUT. Nilai awal dari data', () => {
        render(
            <PanelPendaftaranMerchant
                uuidTenant="T-1"
                data={Data(Pendaftaran({ IdPedagangQris: 'MCH-1' }))}
                bolehKelola
            />,
        );

        expect(screen.getByText(/diisi manual sampai DOKU menjawab/)).toBeTruthy();
        expect((screen.getByLabelText('ID pedagang QRIS (merchantId)') as HTMLInputElement).value).toBe('MCH-1');
        fireEvent.change(screen.getByLabelText('ID terminal QRIS (terminalId)'), { target: { value: 'TRM-9' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan ID QRIS' }));

        expect(uji.form[0]?.url).toBe('/tenant/T-1/pendaftaran-merchant/penampung-qris');
        expect(uji.form[0]?.data).toEqual({ IdPedagangQris: 'MCH-1', IdTerminalQris: 'TRM-9' });
    });

    it('tanpa izin integrasi.kelola: segarkan dan penampung nonaktif dengan penjelasan', () => {
        render(<PanelPendaftaranMerchant uuidTenant="T-1" data={Data(Pendaftaran())} bolehKelola={false} />);

        expect((screen.getByRole('button', { name: 'Segarkan status' }) as HTMLButtonElement).disabled).toBe(true);
        expect((screen.getByRole('button', { name: 'Simpan ID QRIS' }) as HTMLButtonElement).disabled).toBe(true);
        expect((screen.getByLabelText('ID pedagang QRIS (merchantId)') as HTMLInputElement).disabled).toBe(true);
        expect(screen.getByText(/Hanya peran dengan izin kelola integrasi/)).toBeTruthy();
    });

    it('Partner belum diisi: peringatan dengan tautan ke menu Integrasi', () => {
        render(<PanelPendaftaranMerchant uuidTenant="T-1" data={Data(Pendaftaran(), false)} bolehKelola />);

        expect(screen.getByText(/Akun DOKU Partner belum diisi/)).toBeTruthy();
        expect(screen.getByRole('link', { name: 'menu Integrasi' }).getAttribute('href')).toBe('/integrasi');
        expect((screen.getByRole('button', { name: 'Segarkan status' }) as HTMLButtonElement).disabled).toBe(true);
    });
});
