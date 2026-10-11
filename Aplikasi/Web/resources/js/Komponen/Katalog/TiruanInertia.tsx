/**
 * Tiruan `@inertiajs/react` untuk test Vitest halaman F-03 (bukan kode produksi; tidak diimpor aplikasi).
 * Pemakaian: `vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);`
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render } from '@testing-library/react';
import { useState, type ReactElement, type ReactNode } from 'react';
import { vi } from 'vitest';

import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

export type KirimanUji = { metode: 'post' | 'put' | 'delete'; url: string; data: unknown };

/** Semua pengiriman `useForm().post/put/delete` selama test. */
export const kirimanForm: KirimanUji[] = [];

export const tiruanRouter = {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
};

const halaman: { props: PropsBersamaAplikasi; url: string } = { props: BuatPropsBersama(), url: '/kelola/produk' };

export function BuatPropsBersama(galat: Record<string, string> = {}): PropsBersamaAplikasi {
    return {
        NamaAplikasi: 'Kasir',
        Kilat: null,
        Pengguna: { Uuid: '01J', Nama: 'Rina Wulandari', Email: 'rina@kopinusantara.id', EmailTerverifikasi: true },
        TenantAktif: {
            Nama: 'Kopi Nusantara',
            StatusLangganan: 'Aktif',
            PeriodeSelesai: null,
            BatasTenggangPada: null,
        },
        PengumumanLegal: [],
        Akses: { Pemilik: true, Izin: [] },
        errors: galat,
    };
}

/** Siapkan props bersama & URL halaman sebelum render; kosongkan riwayat pengiriman. */
export function AturHalamanUji(galat: Record<string, string> = {}, url = '/kelola/produk'): void {
    halaman.props = BuatPropsBersama(galat);
    halaman.url = url;
    kirimanForm.length = 0;
    Object.values(tiruanRouter).forEach((fungsi) => fungsi.mockClear());
}

/** Tambahkan props halaman (mis. `Penawaran`, `Edisi`) di atas props bersama yang sudah disiapkan `AturHalamanUji`. */
export function TambahPropsHalamanUji(tambahan: Record<string, unknown>): void {
    halaman.props = { ...halaman.props, ...tambahan };
}

type AturData<T> = ((kunci: keyof T, nilai: unknown) => void) & ((data: T | ((lama: T) => T)) => void);

function useFormTiruan<T extends object>(awal: T | (() => T)) {
    const [data, AturDataForm] = useState<T>(awal);
    const AturDataTiruan = ((kunci: keyof T | T | ((lama: T) => T), nilai?: unknown) => {
        if (typeof kunci === 'function') {
            AturDataForm(kunci as (lama: T) => T);
        } else if (typeof kunci === 'object') {
            AturDataForm(kunci);
        } else {
            AturDataForm((lama) => ({ ...lama, [kunci]: nilai }));
        }
    }) as AturData<T>;
    const BuatKirim = (metode: KirimanUji['metode']) => (url: string) => {
        kirimanForm.push({ metode, url, data });
    };

    return {
        data,
        setData: AturDataTiruan,
        errors: halaman.props.errors,
        hasErrors: Object.keys(halaman.props.errors).length > 0,
        processing: false,
        isDirty: false,
        post: BuatKirim('post'),
        put: BuatKirim('put'),
        delete: BuatKirim('delete'),
        reset: vi.fn(),
        clearErrors: vi.fn(),
        transform: vi.fn(),
    };
}

function TautanTiruan({ href, children, ...sisa }: { href: string; children?: ReactNode }) {
    return (
        <a href={href} {...sisa}>
            {children}
        </a>
    );
}

export const TiruanInertia = {
    Head: () => null,
    Link: TautanTiruan,
    router: tiruanRouter,
    usePage: () => halaman,
    useForm: useFormTiruan,
};

/** Render dengan QueryClientProvider (untuk komponen yang memakai TanStack Query). */
export function RenderUji(elemen: ReactElement) {
    const klien = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(<QueryClientProvider client={klien}>{elemen}</QueryClientProvider>);
}
