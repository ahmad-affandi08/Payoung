import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import HalamanPilihDokumen, { CariButirPilihDokumen } from '@/Halaman/Kelola/Bersama/PilihDokumen';
import { RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import SaranDokumenHulu from '@/Komponen/Kelola/SaranDokumenHulu';
import type { ButirPilihDokumen, PropsPilihDokumen } from '@/Tipe/PilihDokumen';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-287: halaman pilih dokumen asal (retur, nota debit, biaya tambahan, faktur) dan saran dokumen hulu di form PO,
 * transfer stok, dan invoice.
 */

function Butir(perubahan: Partial<ButirPilihDokumen> = {}): ButirPilihDokumen {
    return {
        Uuid: '01J9DOK0000000000000000001',
        Nomor: 'GRN/UTM/2611/0001',
        Pihak: 'PT Minyak Nusantara Sejahtera Abadi',
        Tanggal: '2026-11-02',
        Rincian: 'Gudang Utama Delanggu | nilai Rp 315.000',
        Lencana: null,
        Tautan: '/kelola/pembelian/retur/buat?penerimaan=01J9DOK0000000000000000001',
        ...perubahan,
    };
}

const Daftar: ButirPilihDokumen[] = [
    Butir(),
    Butir({
        Uuid: '01J9DOK0000000000000000002',
        Nomor: 'GRN/UTM/2611/0002',
        Pihak: 'CV Beras Delanggu Makmur',
        Rincian: 'Toko Cabang Solo Baru | nilai Rp 1.250.000',
        Lencana: { Jenis: 'netral', Teks: 'Belanja stok' },
        Tautan: '/kelola/pembelian/retur/buat?penerimaan=01J9DOK0000000000000000002',
    }),
];

function Props(perubahan: Partial<PropsPilihDokumen> = {}): PropsPilihDokumen {
    return {
        Teks: {
            Judul: 'Retur barang ke pemasok',
            Petunjuk: 'Pilih penerimaan barang yang akan diretur.',
            JudulDaftar: 'Penerimaan yang bisa diretur',
            LabelCari: 'Cari penerimaan',
            LabelAksi: 'Retur barang',
            Kosong: 'Belum ada penerimaan barang yang bisa diretur.',
        },
        Butir: Daftar,
        Alternatif: null,
        ...perubahan,
    };
}

afterEach(cleanup);

describe('CariButirPilihDokumen', () => {
    it('mencari di nomor, pihak, dan rincian tanpa membedakan huruf besar kecil, kosong = semua', () => {
        expect(CariButirPilihDokumen(Daftar, '')).toHaveLength(2);
        expect(CariButirPilihDokumen(Daftar, '  ')).toHaveLength(2);
        expect(CariButirPilihDokumen(Daftar, 'beras').map((b) => b.Nomor)).toEqual(['GRN/UTM/2611/0002']);
        expect(CariButirPilihDokumen(Daftar, '0001').map((b) => b.Nomor)).toEqual(['GRN/UTM/2611/0001']);
        expect(CariButirPilihDokumen(Daftar, 'solo baru')).toHaveLength(1);
        expect(CariButirPilihDokumen(Daftar, 'tidak ada')).toHaveLength(0);
    });
});

describe('HalamanPilihDokumen', () => {
    it('menampilkan petunjuk, daftar dokumen dengan pihak, tanggal, rincian, lencana, dan tautan aksi ke form tujuan', () => {
        RenderUji(<HalamanPilihDokumen {...Props()} />);

        expect(screen.getByText('Pilih penerimaan barang yang akan diretur.')).toBeTruthy();
        const daftar = screen.getByRole('list', { name: 'Penerimaan yang bisa diretur' });
        const baris = within(daftar).getAllByRole('listitem');

        expect(baris).toHaveLength(2);
        expect(within(baris[0] as HTMLElement).getByText('GRN/UTM/2611/0001')).toBeTruthy();
        expect(within(baris[0] as HTMLElement).getByText('PT Minyak Nusantara Sejahtera Abadi')).toBeTruthy();
        expect(within(baris[0] as HTMLElement).getByText(/Gudang Utama Delanggu \| nilai Rp 315\.000/)).toBeTruthy();
        expect(within(baris[1] as HTMLElement).getByText('Belanja stok')).toBeTruthy();
        expect(
            within(baris[0] as HTMLElement)
                .getByRole('link', { name: 'Retur barang' })
                .getAttribute('href'),
        ).toBe('/kelola/pembelian/retur/buat?penerimaan=01J9DOK0000000000000000001');
    });

    it('mencari memfilter daftar dan menyatakan bila tidak ada yang cocok', () => {
        RenderUji(<HalamanPilihDokumen {...Props()} />);
        const kotak = screen.getByLabelText('Cari penerimaan');

        fireEvent.change(kotak, { target: { value: 'beras' } });
        expect(
            within(screen.getByRole('list', { name: 'Penerimaan yang bisa diretur' })).getAllByRole('listitem'),
        ).toHaveLength(1);
        expect(screen.getByText('CV Beras Delanggu Makmur')).toBeTruthy();

        fireEvent.change(kotak, { target: { value: 'tidak ada' } });
        expect(screen.queryByRole('list', { name: 'Penerimaan yang bisa diretur' })).toBeNull();
        expect(screen.getByText('Tidak ada yang cocok dengan pencarian.')).toBeTruthy();
    });

    it('tanpa dokumen: menampilkan keadaan kosong bertulis, tanpa kotak cari', () => {
        RenderUji(<HalamanPilihDokumen {...Props({ Butir: [] })} />);

        expect(screen.getByText('Belum ada penerimaan barang yang bisa diretur.')).toBeTruthy();
        expect(screen.queryByLabelText('Cari penerimaan')).toBeNull();
        expect(screen.queryByRole('list', { name: 'Penerimaan yang bisa diretur' })).toBeNull();
    });

    it('alternatif yang sah (misal pilih pemasok sendiri) tampil sebagai panel dengan tautan', () => {
        RenderUji(
            <HalamanPilihDokumen
                {...Props({
                    Alternatif: {
                        Judul: 'Pilih pemasok sendiri?',
                        Teks: 'Buka form faktur dan pilih pemasoknya dulu.',
                        Tautan: [{ Teks: 'Pilih pemasok sendiri', Href: '/kelola/pembelian/faktur/buat?manual=1' }],
                    },
                })}
            />,
        );

        expect(screen.getByText('Buka form faktur dan pilih pemasoknya dulu.')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Pilih pemasok sendiri' }).getAttribute('href')).toBe(
            '/kelola/pembelian/faktur/buat?manual=1',
        );
    });
});

describe('SaranDokumenHulu', () => {
    const Saran = (butir: ButirPilihDokumen[]) => (
        <SaranDokumenHulu
            butir={butir}
            judulSatu="Ada satu permintaan yang sudah disetujui"
            judulBanyak="Ada {n} permintaan yang sudah disetujui"
            keterangan="Mulai dari permintaannya supaya barang dan pemasoknya terbawa."
            labelAksi="Jadikan PO dari"
            tautanSemua="/kelola/pembelian/permintaan?saring[Status]=Disetujui"
        />
    );

    it('kosong = tidak tampil', () => {
        const { container } = RenderUji(Saran([]));

        expect(container.textContent).toBe('');
    });

    it('satu dokumen: judul tunggal dan tautan ke dokumen hulu', () => {
        RenderUji(
            Saran([
                Butir({ Nomor: 'PP/UTM/2611/0001', Tautan: '/kelola/pembelian/permintaan/01J9DOK0000000000000000001' }),
            ]),
        );

        expect(screen.getByText('Ada satu permintaan yang sudah disetujui')).toBeTruthy();
        expect(screen.getByRole('link', { name: /Jadikan PO dari PP\/UTM\/2611\/0001/ }).getAttribute('href')).toBe(
            '/kelola/pembelian/permintaan/01J9DOK0000000000000000001',
        );
        expect(screen.queryByRole('link', { name: /Lihat semua/ })).toBeNull();
    });

    it('lebih dari tiga: tampil tiga, judul memuat jumlah, dan ada tautan ke daftar lengkap', () => {
        const lima = [1, 2, 3, 4, 5].map((n) =>
            Butir({ Uuid: `01J9DOK000000000000000000${String(n)}`, Nomor: `PP/UTM/2611/000${String(n)}` }),
        );

        RenderUji(Saran(lima));

        expect(screen.getByText('Ada 5 permintaan yang sudah disetujui')).toBeTruthy();
        expect(screen.getAllByRole('link', { name: /Jadikan PO dari/ })).toHaveLength(3);
        expect(screen.getByRole('link', { name: 'Lihat semua (5)' }).getAttribute('href')).toBe(
            '/kelola/pembelian/permintaan?saring[Status]=Disetujui',
        );
    });
});
