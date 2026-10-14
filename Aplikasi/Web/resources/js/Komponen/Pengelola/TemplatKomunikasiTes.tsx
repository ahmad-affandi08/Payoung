import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactElement } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarTemplatKomunikasi from '@/Halaman/Pengelola/Legal/TemplatKomunikasi/Daftar';
import HalamanUbahTemplatKomunikasi, { IsiPratinjau } from '@/Halaman/Pengelola/Legal/TemplatKomunikasi/Ubah';
import { kirimanForm, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { IzinPengelola } from '@/Tipe/Pengelola';
import type { EditorTemplat, PeristiwaTemplat, VariabelTemplat } from '@/Tipe/TemplatKomunikasi';

/*
 * PGL-07 templat komunikasi: daftar peristiwa × kanal × bahasa dengan sumber teks yang dipakai, editor draf dengan
 * pratinjau data contoh, terbitkan, dan status BSP WhatsApp.
 */

const uji = vi.hoisted(() => ({ props: {} as Record<string, unknown>, url: '/template-komunikasi' }));

vi.mock('@inertiajs/react', async () => {
    const tiruan = (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia;

    return { ...tiruan, usePage: () => ({ props: uji.props, url: uji.url }) };
});

beforeEach(() => {
    uji.url = '/template-komunikasi';
    kirimanForm.length = 0;
    Object.values(tiruanRouter).forEach((fungsi) => fungsi.mockClear());
    uji.props = {
        NamaAplikasi: 'Kasir',
        Lingkungan: 'Staging',
        Kilat: null,
        Pengguna: {
            Uuid: 'P1',
            Nama: 'Kania Konten',
            Email: 'kania@contoh.id',
            KodePeran: [],
            Izin: [IzinPengelola.LegalLihat, IzinPengelola.LegalKelola],
        },
        PeringatanSuperAdmin: false,
        PeringatanIntegrasi: [],
        PeringatanOperasional: [],
        errors: {},
    };
});

afterEach(cleanup);

function RenderHalaman(elemen: ReactElement) {
    return render(elemen);
}

const variabel: VariabelTemplat[] = [
    { Nama: 'NamaPemilik', Keterangan: 'Nama Owner penerima', Contoh: 'Rina Wulandari', Wajib: false },
    { Nama: 'NomorTagihan', Keterangan: 'Nomor tagihan', Contoh: 'INV/2610/0042', Wajib: false },
    {
        Nama: 'Tautan',
        Keterangan: 'Tautan bayar',
        Contoh: 'https://dashboard.payoung.id/kelola/langganan',
        Wajib: true,
    },
];

function BuatEditor(timpa: Partial<EditorTemplat> = {}): EditorTemplat {
    return {
        Peristiwa: {
            Kode: 'tagihan.pengingat.h-7',
            Label: 'Tagihan langganan terbit (H-7)',
            Keterangan: 'Dikirim ke Owner 7 hari sebelum jatuh tempo.',
        },
        Kanal: { Nilai: 'Email', Label: 'Email' },
        Bahasa: { Nilai: 'id', Label: 'Indonesia' },
        Variabel: variabel,
        Bawaan: { Subjek: 'Tagihan {NomorTagihan} sudah terbit', Judul: 'Tagihan Anda sudah terbit', Isi: '{Kalimat}' },
        Terbit: null,
        Draf: null,
        Riwayat: [],
        Batas: { Subjek: 150, Judul: 150, Isi: 3000 },
        ...timpa,
    };
}

const versi = {
    Uuid: '01J9TPL0000000000000000001',
    Versi: 1,
    Status: 'Terbit' as const,
    Subjek: 'Segera bayar {NomorTagihan}',
    Judul: 'Halo {NamaPemilik}',
    Isi: 'Tagihan {NomorTagihan} belum dibayar.',
    StatusBsp: null,
    CatatanBsp: null,
    DiterbitkanPada: '2026-10-13T03:00:00Z',
    DiubahPada: '2026-10-13T03:00:00Z',
};

describe('daftar templat komunikasi', () => {
    it('menampilkan sumber teks per kanal dan bahasa, draf, dan status BSP; tautan ke editor', () => {
        const peristiwa: PeristiwaTemplat[] = [
            {
                Kode: 'tagihan.pengingat.h-7',
                Label: 'Tagihan langganan terbit (H-7)',
                Keterangan: 'Dikirim ke Owner 7 hari sebelum jatuh tempo.',
                Sel: [
                    {
                        Kanal: 'Email',
                        KanalLabel: 'Email',
                        Bahasa: 'id',
                        BahasaLabel: 'Indonesia',
                        Sumber: 'Templat',
                        VersiTerbit: 2,
                        AdaDraf: true,
                        StatusBsp: null,
                    },
                    {
                        Kanal: 'WhatsApp',
                        KanalLabel: 'WhatsApp',
                        Bahasa: 'en',
                        BahasaLabel: 'Inggris',
                        Sumber: 'Bawaan',
                        VersiTerbit: null,
                        AdaDraf: false,
                        StatusBsp: null,
                    },
                    {
                        Kanal: 'WhatsApp',
                        KanalLabel: 'WhatsApp',
                        Bahasa: 'id',
                        BahasaLabel: 'Indonesia',
                        Sumber: 'Templat',
                        VersiTerbit: 1,
                        AdaDraf: false,
                        StatusBsp: 'Ditolak',
                    },
                ],
            },
        ];
        RenderHalaman(<HalamanDaftarTemplatKomunikasi Peristiwa={peristiwa} />);

        expect(screen.getByText('Versi 2')).toBeTruthy();
        expect(screen.getByText('Teks bawaan')).toBeTruthy();
        expect(screen.getByText('Ada draf')).toBeTruthy();
        expect(screen.getByText('BSP: Ditolak')).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: /Ubah Tagihan langganan terbit \(H-7\) \| Email \| Indonesia/ })
                .getAttribute('href'),
        ).toBe('/template-komunikasi/tagihan.pengingat.h-7/email/id');
        expect(screen.getByRole('link', { name: /WhatsApp \| Inggris/ }).getAttribute('href')).toBe(
            '/template-komunikasi/tagihan.pengingat.h-7/whatsapp/en',
        );
    });
});

describe('editor templat komunikasi', () => {
    it('IsiPratinjau mengganti variabel dikenal dengan contoh dan membiarkan yang asing', () => {
        expect(IsiPratinjau('Halo {NamaPemilik}, {Asing}', variabel)).toBe('Halo Rina Wulandari, {Asing}');
    });

    it('email: bidang memuat teks terbit, pratinjau memakai data contoh, simpan draf mengirim PUT ke kombinasi', () => {
        RenderHalaman(
            <HalamanUbahTemplatKomunikasi
                Editor={BuatEditor({ Terbit: versi, Riwayat: [versi] })}
                Izin={{ Kelola: true }}
            />,
        );

        expect((screen.getByLabelText(/Subjek email/) as HTMLInputElement).value).toBe('Segera bayar {NomorTagihan}');
        expect(screen.getByText('Segera bayar INV/2610/0042')).toBeTruthy();
        expect(screen.getByText('Tagihan INV/2610/0042 belum dibayar.')).toBeTruthy();
        expect(screen.getByText('Dipakai: versi 1')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Simpan draf' }));
        expect(kirimanForm).toEqual([
            {
                metode: 'put',
                url: '/template-komunikasi/tagihan.pengingat.h-7/email/id',
                data: {
                    Subjek: 'Segera bayar {NomorTagihan}',
                    Judul: 'Halo {NamaPemilik}',
                    Isi: 'Tagihan {NomorTagihan} belum dibayar.',
                },
            },
        ]);
    });

    it('tanpa versi terbit, bidang terisi teks bawaan dan tombol terbitkan belum ada', () => {
        RenderHalaman(<HalamanUbahTemplatKomunikasi Editor={BuatEditor()} Izin={{ Kelola: true }} />);

        expect((screen.getByLabelText(/Isi email/) as HTMLTextAreaElement).value).toBe('{Kalimat}');
        expect(screen.getByText('Dipakai: teks bawaan')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Terbitkan draf' })).toBeNull();
        expect(screen.getByText('Belum ada versi terbit. Pesan memakai teks bawaan.')).toBeTruthy();
    });

    it('draf: terbitkan lewat dialog konfirmasi dan hapus draf mengirim ke Uuid draf', () => {
        const draf = {
            ...versi,
            Uuid: '01J9TPL0000000000000000002',
            Versi: 2,
            Status: 'Draf' as const,
            DiterbitkanPada: null,
        };
        RenderHalaman(
            <HalamanUbahTemplatKomunikasi
                Editor={BuatEditor({ Terbit: versi, Riwayat: [versi], Draf: draf })}
                Izin={{ Kelola: true }}
            />,
        );

        expect(screen.getByText('Draf versi 2', { selector: 'span' })).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Terbitkan draf' }));
        fireEvent.click(screen.getByRole('button', { name: 'Terbitkan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/template-komunikasi/01J9TPL0000000000000000002/terbitkan',
            {},
            expect.anything(),
        );

        cleanup();
        RenderHalaman(<HalamanUbahTemplatKomunikasi Editor={BuatEditor({ Draf: draf })} Izin={{ Kelola: true }} />);
        fireEvent.click(screen.getByRole('button', { name: 'Hapus draf' }));
        fireEvent.click(screen.getAllByRole('button', { name: 'Hapus draf' }).at(-1) as HTMLElement);
        expect(tiruanRouter.delete).toHaveBeenCalledWith(
            '/template-komunikasi/01J9TPL0000000000000000002',
            expect.anything(),
        );
    });

    it('Isi dengan teks bawaan mengembalikan bidang ke bawaan kode', () => {
        RenderHalaman(
            <HalamanUbahTemplatKomunikasi
                Editor={BuatEditor({ Terbit: versi, Riwayat: [versi] })}
                Izin={{ Kelola: true }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Isi dengan teks bawaan' }));

        expect((screen.getByLabelText(/Subjek email/) as HTMLInputElement).value).toBe(
            'Tagihan {NomorTagihan} sudah terbit',
        );
        expect((screen.getByLabelText(/Isi email/) as HTMLTextAreaElement).value).toBe('{Kalimat}');
    });

    it('WhatsApp: tanpa subjek/judul, variabel wajib bertanda, panel BSP muncul untuk versi terbit', () => {
        RenderHalaman(
            <HalamanUbahTemplatKomunikasi
                Editor={BuatEditor({
                    Kanal: { Nilai: 'WhatsApp', Label: 'WhatsApp' },
                    Bawaan: { Subjek: null, Judul: null, Isi: 'Halo {NamaPemilik} bayar di {Tautan}' },
                    Terbit: { ...versi, Subjek: null, Judul: null, Isi: 'Halo {NamaPemilik} bayar di {Tautan}' },
                    Riwayat: [versi],
                    Batas: { Subjek: 150, Judul: 150, Isi: 1500 },
                })}
                Izin={{ Kelola: true }}
            />,
        );

        expect(screen.queryByLabelText(/Subjek email/)).toBeNull();
        expect(screen.getByLabelText(/Isi pesan WhatsApp/)).toBeTruthy();
        expect(screen.getByText('Wajib')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Catat status BSP' })).toBeTruthy();
        expect(
            screen.getByText('Halo Rina Wulandari bayar di https://dashboard.payoung.id/kelola/langganan'),
        ).toBeTruthy();
    });

    it('kanal Notifikasi aplikasi: judul dengan batas 65 tanpa subjek email, isi pendek, tanpa status BSP, pratinjau judul dan isi', () => {
        RenderHalaman(
            <HalamanUbahTemplatKomunikasi
                Editor={BuatEditor({
                    Kanal: { Nilai: 'Notifikasi', Label: 'Notifikasi aplikasi' },
                    Bawaan: { Subjek: null, Judul: 'Tagihan {NomorTagihan} sudah terbit', Isi: 'Halo {NamaPemilik}.' },
                    Batas: { Subjek: 150, Judul: 65, Isi: 200 },
                })}
                Izin={{ Kelola: true }}
            />,
        );

        expect(screen.queryByLabelText(/Subjek email/)).toBeNull();
        expect((screen.getByLabelText(/Judul notifikasi/) as HTMLInputElement).maxLength).toBe(65);
        expect(screen.getByLabelText(/Isi notifikasi/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Catat status BSP' })).toBeNull();
        expect(screen.getByText('Tagihan INV/2610/0042 sudah terbit')).toBeTruthy();
        expect(screen.getByText('Halo Rina Wulandari.')).toBeTruthy();

        fireEvent.change(screen.getByLabelText(/Judul notifikasi/), { target: { value: 'Balasan {NomorTagihan}' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan draf' }));
        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'put',
            url: '/template-komunikasi/tagihan.pengingat.h-7/notifikasi/id',
            data: { Judul: 'Balasan {NomorTagihan}' },
        });
    });

    it('hanya baca: tidak ada tombol simpan, terbitkan, atau catat BSP', () => {
        RenderHalaman(
            <HalamanUbahTemplatKomunikasi
                Editor={BuatEditor({
                    Kanal: { Nilai: 'WhatsApp', Label: 'WhatsApp' },
                    Terbit: versi,
                    Riwayat: [versi],
                })}
                Izin={{ Kelola: false }}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Simpan draf' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Catat status BSP' })).toBeNull();
    });
});
