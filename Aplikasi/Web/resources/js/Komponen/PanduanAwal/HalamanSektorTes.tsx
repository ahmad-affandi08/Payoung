import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import HalamanSektor from '@/Halaman/Kelola/PanduanAwal/Sektor';
import type { PropsSektor, TemplatePilihan } from '@/Tipe/PanduanAwal';

import { BuatProgresContoh, BuatPropsBersamaContoh } from './DataUjiPanduan';

const tiruan = vi.hoisted(() => ({ kirim: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        Head: () => null,
        Link: ({ href, children, ...sisa }: { href: string; children: ReactNode }) => (
            <a href={href} {...sisa}>
                {children}
            </a>
        ),
        router: { post: vi.fn() },
        usePage: () => ({ props: BuatPropsBersamaContoh(), url: '/kelola/panduan-awal/sektor' }),
        // useForm sederhana: cukup untuk state isian & alamat POST.
        useForm: <T extends object>(awal: T) => {
            const [data, AturData] = useState<T>(awal);

            return {
                data,
                errors: {},
                processing: false,
                setData: (kunci: keyof T | T, nilai?: unknown) =>
                    typeof kunci === 'object' ? AturData(kunci) : AturData((lama) => ({ ...lama, [kunci]: nilai })),
                post: (alamat: string) => tiruan.kirim(alamat, data),
            };
        },
    };
});

function BuatTemplate(kode: string, nama: string, ubah: Partial<TemplatePilihan> = {}): TemplatePilihan {
    return {
        Kode: kode,
        Nama: nama,
        Keterangan: null,
        Versi: 2,
        ModeKasir: [{ Nilai: 'Cepat', Label: 'Cepat (bayar di depan)' }],
        Fitur: [{ Kunci: 'pos.retail', Nama: 'Kasir retail', TersediaDiPaket: true }],
        Kategori: ['Kopi', 'Non-kopi', 'Makanan'],
        JumlahAkun: 43,
        JumlahProdukContoh: 10,
        ...ubah,
    };
}

function BuatProps(ubah: Partial<PropsSektor> = {}): PropsSektor {
    return {
        Progres: BuatProgresContoh({ ProfilUsaha: 'Selesai' }),
        Template: [
            BuatTemplate('FNB-CAF', 'Kafe / kedai kopi', {
                Fitur: [
                    { Kunci: 'pos.retail', Nama: 'Kasir retail', TersediaDiPaket: true },
                    { Kunci: 'pos.kds', Nama: 'Layar dapur (KDS)', TersediaDiPaket: false },
                ],
            }),
            BuatTemplate('RTL-GEN', 'Toko kelontong / minimarket'),
        ],
        TemplateTerpilih: null,
        SektorLain: [],
        NamaPaket: 'Gratis',
        ...ubah,
    };
}

describe('Langkah 2 Jenis usaha & template (F-01, BR-01.1 aditif)', () => {
    afterEach(() => {
        cleanup();
        tiruan.kirim.mockReset();
    });

    it('memperingatkan bahwa data template lama tetap ada saat memilih template lain', () => {
        render(
            <HalamanSektor
                {...BuatProps({
                    TemplateTerpilih: {
                        Kode: 'FNB-CAF',
                        Nama: 'Kafe / kedai kopi',
                        Versi: 2,
                        DiterapkanPada: '2026-09-22T07:00:00Z',
                    },
                })}
            />,
        );

        expect(screen.queryByText(/Template baru hanya menambah yang belum ada/)).toBeNull();

        fireEvent.click(screen.getByRole('radio', { name: /Toko kelontong/ }));

        expect(
            screen.getByText(
                'Kategori dan akun dari template sebelumnya tetap ada. Template baru hanya menambah yang belum ada.',
            ),
        ).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Terapkan template saja' }));

        expect(tiruan.kirim).toHaveBeenCalledWith('/kelola/panduan-awal/sektor', {
            KodeTemplate: 'RTL-GEN',
            SektorLain: [],
        });

        // D-23 A: satu klik menyiapkan template, pajak, produk contoh, dan metode bayar.
        fireEvent.click(screen.getByRole('button', { name: 'Siapkan semuanya otomatis' }));
        expect(tiruan.kirim).toHaveBeenLastCalledWith('/kelola/panduan-awal/sektor/siapkan-otomatis', {
            KodeTemplate: 'RTL-GEN',
            SektorLain: [],
        });
    });

    it('rincian template (kategori, akun, produk contoh, fitur) hanya terbuka pada kartu yang dipilih', () => {
        render(<HalamanSektor {...BuatProps()} />);

        expect(screen.queryByText('Kategori awal')).toBeNull();
        expect(screen.queryByText('Versi template 2')).toBeNull();

        fireEvent.click(screen.getByRole('radio', { name: /Kafe/ }));

        expect(screen.getByText('Kategori awal')).toBeTruthy();
        expect(screen.getByText('Kopi')).toBeTruthy();
        const disiapkan = screen.getByLabelText('Yang disiapkan').textContent ?? '';
        expect(disiapkan).toContain('43 akun pembukuan');
        expect(disiapkan).toContain('10 produk contoh');
        expect(screen.getByText('Mode kasir: Cepat (bayar di depan)')).toBeTruthy();
        expect(screen.getByText('Versi template 2')).toBeTruthy();

        // Pindah pilihan: rincian kartu lama menutup, rincian kartu baru terbuka (hanya satu).
        fireEvent.click(screen.getByRole('radio', { name: /Toko kelontong/ }));
        expect(screen.getAllByText('Kategori awal')).toHaveLength(1);
    });

    it('fitur di luar paket diberi label teks "Butuh paket lebih tinggi"', () => {
        render(<HalamanSektor {...BuatProps()} />);

        fireEvent.click(screen.getByRole('radio', { name: /Kafe/ }));

        expect(screen.getByText('2 fitur kasir, 1 butuh paket lebih tinggi')).toBeTruthy();
        expect(screen.getByText('Butuh paket lebih tinggi')).toBeTruthy();
        expect(screen.getByText('Tersedia di paket Anda')).toBeTruthy();
    });

    it('kategori lebih dari enam diringkas "+N lainnya" dan produk contoh kosong ditulis eksplisit', () => {
        render(
            <HalamanSektor
                {...BuatProps({
                    Template: [
                        BuatTemplate('FNB-RST', 'Restoran', {
                            Kategori: ['A1', 'B2', 'C3', 'D4', 'E5', 'F6', 'G7', 'H8'],
                            JumlahProdukContoh: 0,
                        }),
                    ],
                })}
            />,
        );

        fireEvent.click(screen.getByRole('radio', { name: /Restoran/ }));

        expect(screen.getByText('F6')).toBeTruthy();
        expect(screen.queryByText('G7')).toBeNull();
        expect(screen.getByText('+2 lainnya')).toBeTruthy();
        expect(screen.getByLabelText('Yang disiapkan').textContent).toContain('Belum ada produk contoh');
    });

    it('template dikelompokkan menurut jenis usaha dengan judul kelompok; satu kelompok saja tanpa judul', () => {
        render(
            <HalamanSektor
                {...BuatProps({
                    Template: [
                        BuatTemplate('FNB-CAF', 'Kafe / kedai kopi'),
                        BuatTemplate('SVC-LDR', 'Laundry'),
                        BuatTemplate('RTL-GEN', 'Toko kelontong / minimarket'),
                    ],
                })}
            />,
        );

        const judul = screen.getAllByRole('heading', { level: 3 }).map((h) => h.textContent);
        expect(judul).toEqual(['Makanan & minuman', 'Toko & retail', 'Jasa & layanan']);
        expect(screen.getAllByRole('radio')).toHaveLength(3);
        cleanup();

        render(<HalamanSektor {...BuatProps({ Template: [BuatTemplate('FNB-CAF', 'Kafe')] })} />);
        expect(screen.queryAllByRole('heading', { level: 3 })).toHaveLength(0);
    });

    it('template yang sedang dipakai diberi label "Sedang dipakai" dan langsung terbuka rinciannya', () => {
        render(
            <HalamanSektor
                {...BuatProps({
                    TemplateTerpilih: {
                        Kode: 'FNB-CAF',
                        Nama: 'Kafe / kedai kopi',
                        Versi: 2,
                        DiterapkanPada: '2026-09-22T07:00:00Z',
                    },
                })}
            />,
        );

        expect(screen.getAllByText('Sedang dipakai').length).toBeGreaterThan(0);
        expect(screen.getByText('Kategori awal')).toBeTruthy();
        expect(screen.getByRole('radio', { name: /Kafe/ }).getAttribute('aria-checked')).toBe('true');
    });

    it('tanpa template terbit: keadaan kosong, tanpa formulir', () => {
        render(<HalamanSektor {...BuatProps({ Template: [] })} />);

        expect(screen.getByText(/Belum ada template yang bisa dipilih/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Terapkan template saja' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Siapkan semuanya otomatis' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Lewati dulu' })).toBeTruthy();
    });
});
