import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import HalamanBuatFakturGrosir, { KelompokkanSuratJalan } from '@/Halaman/Kelola/Grosir/Faktur/Buat';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import type { BarisDaftarSuratJalan, PropsBuatFaktur } from '@/Tipe/Grosir';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

function Baris(uuid: string, ubah: Partial<BarisDaftarSuratJalan>): BarisDaftarSuratJalan {
    return {
        Uuid: uuid,
        Nomor: `SJ/2610/${uuid.slice(-4)}`,
        Tanggal: '2026-10-05',
        NomorPesanan: null,
        NomorFaktur: null,
        NamaPelanggan: 'CV Maju Jaya',
        KodeOutlet: 'UTM',
        Status: 'Diposting',
        LabelStatus: 'Diposting',
        StatusPengiriman: 'BelumDikirim',
        LabelStatusPengiriman: 'Belum dikirim',
        Total: '1000000.00',
        TotalHpp: '600000.00',
        ...ubah,
    } as BarisDaftarSuratJalan;
}

const data = [
    Baris('01J9SJ000000000000000A0001', {}),
    Baris('01J9SJ000000000000000A0002', { Tanggal: '2026-10-20' }),
    Baris('01J9SJ000000000000000B0003', { NamaPelanggan: 'Toko Sumber Rejeki' }),
    Baris('01J9SJ000000000000000C0004', { Tanggal: '2026-09-28' }),
];

function Props(): PropsBuatFaktur {
    return {
        SuratJalan: { Data: data, Meta: { Total: 4, Halaman: 1, PerHalaman: 25 } },
        HariIni: '2026-10-26',
        Izin: { Kelola: true, SetujuiKredit: false, LihatJurnal: false, LihatPiutang: true },
    } as unknown as PropsBuatFaktur;
}

describe('Buat faktur grosir', () => {
    afterEach(() => cleanup());

    it('surat jalan dikelompokkan per pelanggan, outlet, dan bulan penyerahan', () => {
        const kelompok = KelompokkanSuratJalan(data);

        expect(kelompok.map((k) => k.Judul)).toEqual([
            'CV Maju Jaya | UTM | Oktober 2026',
            'Toko Sumber Rejeki | UTM | Oktober 2026',
            'CV Maju Jaya | UTM | September 2026',
        ]);
        expect(kelompok[0]?.Baris).toHaveLength(2);
    });

    it('Pilih semua mengisi satu kelompok. Memilih di kelompok lain mengganti pilihan', () => {
        AturHalamanUji();
        RenderUji(<HalamanBuatFakturGrosir {...Props()} />);

        fireEvent.click(screen.getAllByRole('button', { name: /Pilih semua/ })[0] ?? document.body);
        expect(screen.getByText(/2 surat jalan dipilih/)).toBeTruthy();

        fireEvent.click(screen.getByLabelText('Pilih SJ/2610/0003'));
        expect(screen.getByText(/1 surat jalan dipilih/)).toBeTruthy();
    });

    it('surat jalan dari tautan ?surat-jalan= sudah terpilih dan terkirim', () => {
        AturHalamanUji({}, '/kelola/grosir/faktur/buat?surat-jalan=01J9SJ000000000000000B0003');
        tiruanRouter.post.mockClear();
        RenderUji(<HalamanBuatFakturGrosir {...Props()} />);

        expect(screen.getByText(/1 surat jalan dipilih/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Terbitkan faktur' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/grosir/faktur',
            expect.objectContaining({ UuidSuratJalan: ['01J9SJ000000000000000B0003'] }),
            expect.anything(),
        );
    });

    it('bila hanya ada satu kelompok, semua surat jalannya langsung tercentang', () => {
        AturHalamanUji();
        const props = Props();
        props.SuratJalan = { ...props.SuratJalan, Data: data.slice(0, 2) } as PropsBuatFaktur['SuratJalan'];
        RenderUji(<HalamanBuatFakturGrosir {...props} />);

        expect(screen.getByText(/2 surat jalan dipilih/)).toBeTruthy();
    });
});
