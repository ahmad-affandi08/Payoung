import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import HalamanKompatibilitasPerangkatPublik from '@/Halaman/Situs/KompatibilitasPerangkat';
import type { BarisKompatibilitas } from '@/Tipe/Kompatibilitas';
import type { DataSitus } from '@/Tipe/Situs';

const situs = {
    NamaSitus: 'Payoung',
    Slogan: null,
    Logo: null,
    Menu: [{ Label: 'Harga', Tautan: '/harga' }],
    MenuKaki: [],
    TeksKaki: null,
    Kontak: {
        WhatsApp: null,
        TautanWhatsApp: 'https://wa.me/6281234567890',
        Email: null,
        Telepon: null,
        Alamat: null,
        JamLayanan: null,
    },
    MediaSosial: {},
    Pengumuman: null,
    TautanUnduh: {},
    TombolDaftar: { Label: 'Coba gratis', Tautan: 'https://dashboard.payoung.id/daftar' },
    TombolMasuk: { Label: 'Masuk', Tautan: 'https://dashboard.payoung.id/masuk' },
    WhatsAppMelayang: false,
    Tahun: 2026,
} satisfies DataSitus;

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...sisa }: { href: string; children: ReactNode }) => (
        <a href={href} {...sisa}>
            {children}
        </a>
    ),
    usePage: () => ({ props: { Situs: situs }, url: '/kompatibilitas-perangkat' }),
}));

function Baris(
    isi: Partial<BarisKompatibilitas> & Pick<BarisKompatibilitas, 'Uuid' | 'Jenis' | 'Nama'>,
): BarisKompatibilitas {
    return {
        Sambungan: null,
        Status: 'Kompatibel',
        LabelStatus: 'Kompatibel',
        StatusOtomatis: 'Kompatibel',
        StatusManual: null,
        Catatan: null,
        JumlahPerangkat: 2,
        JumlahTenant: 2,
        JumlahLolos: 2,
        JumlahGagal: 0,
        TerakhirDiujiPada: null,
        DisegarkanPada: null,
        ...isi,
    };
}

const DATA = [
    Baris({ Uuid: '01K5A00000000000000000001', Jenis: 'Perangkat', Nama: 'SUNMI V2s' }),
    Baris({
        Uuid: '01K5A00000000000000000002',
        Jenis: 'Printer',
        Nama: 'RPP02N',
        Sambungan: 'BluetoothKlasik',
        Status: 'Tersertifikasi',
        LabelStatus: 'Tersertifikasi',
        StatusManual: 'Tersertifikasi',
        Catatan: 'Lolos uji lab dengan firmware 2.1',
    }),
    Baris({
        Uuid: '01K5A00000000000000000003',
        Jenis: 'Printer',
        Nama: 'Xprinter XP-58',
        Status: 'Terbatas',
        LabelStatus: 'Terbatas',
        JumlahTenant: 0,
    }),
];

describe('Halaman publik kompatibilitas perangkat (v1.98, D-92)', () => {
    afterEach(() => cleanup());

    it('perangkat & printer dipisah. Status bertulisan, sambungan, jumlah usaha, dan catatan tim tampil dalam shell situs', () => {
        render(<HalamanKompatibilitasPerangkatPublik Baris={DATA} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Perangkat & printer yang didukung' })).toBeTruthy();
        expect(screen.getByRole('region', { name: 'Perangkat' })).toBeTruthy();
        expect(screen.getByRole('region', { name: 'Printer struk' })).toBeTruthy();
        expect(screen.getByText('SUNMI V2s')).toBeTruthy();
        expect(screen.getByText('Bluetooth | 2 usaha')).toBeTruthy();
        expect(screen.getByText('Lolos uji lab dengan firmware 2.1')).toBeTruthy();
        // Kepala situs ikut tampil, dan ringkasan jumlah per status ada di kepala halaman.
        expect(screen.getAllByRole('link', { name: 'Coba gratis' }).length).toBeGreaterThan(0);
        expect(screen.getByText('Tersertifikasi (1)')).toBeTruthy();
        expect(screen.getByText('Terbatas (1)')).toBeTruthy();
    });

    it('pencarian dan filter status memfilter kartu. Hasil nihil menampilkan keadaan kosong bertulisan', () => {
        render(<HalamanKompatibilitasPerangkatPublik Baris={DATA} />);

        fireEvent.change(screen.getByPlaceholderText('Cari merek atau model'), { target: { value: 'sunmi' } });
        expect(screen.getByText('SUNMI V2s')).toBeTruthy();
        expect(screen.queryByText('RPP02N')).toBeNull();

        fireEvent.change(screen.getByPlaceholderText('Cari merek atau model'), { target: { value: '' } });
        fireEvent.click(screen.getByRole('button', { name: 'Terbatas' }));
        expect(screen.getByText('Xprinter XP-58')).toBeTruthy();
        expect(screen.queryByText('SUNMI V2s')).toBeNull();
        expect(screen.getByText('Tidak ada yang cocok dengan pencarian ini.')).toBeTruthy();
    });

    it('tanpa data: keadaan "Daftar sedang disusun" dan ajakan WhatsApp, bukan tabel kosong', () => {
        render(<HalamanKompatibilitasPerangkatPublik Baris={[]} />);

        expect(screen.getByRole('heading', { name: 'Daftar sedang disusun' })).toBeTruthy();
        expect(screen.queryByPlaceholderText('Cari merek atau model')).toBeNull();
        expect(screen.getByRole('link', { name: 'Tanya lewat WhatsApp' }).getAttribute('href')).toBe(
            'https://wa.me/6281234567890',
        );
    });
});
