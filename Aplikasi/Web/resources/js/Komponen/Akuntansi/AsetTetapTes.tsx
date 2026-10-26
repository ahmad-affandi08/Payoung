import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanBuatAsetTetap, { PerkiraanPerBulan } from '@/Halaman/Kelola/Akuntansi/AsetTetap/Buat';
import HalamanDaftarAsetTetap, { FormatMasaManfaat } from '@/Halaman/Kelola/Akuntansi/AsetTetap/Daftar';
import HalamanDetailAsetTetap, { FormatPeriode } from '@/Halaman/Kelola/Akuntansi/AsetTetap/Detail';
import { AturHalamanUji, kirimanForm, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel } from '@/Komponen/Persediaan/DataUjiPersediaan';
import type { BarisAsetTetap, PropsDetailAsetTetap } from '@/Tipe/Akuntansi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const OpsiKelompok = [
    { Nilai: 'Kelompok1', Label: 'Kelompok 1 (4 tahun): komputer, printer, mesin kasir', UmurBulan: 48 },
    { Nilai: 'Kelompok2', Label: 'Kelompok 2 (8 tahun): mobil, mesin dapur', UmurBulan: 96 },
    { Nilai: 'Tanah', Label: 'Tanah (tidak disusutkan)', UmurBulan: 0 },
];
const Akun = [{ Uuid: '01J9AKN0000000000000000001', Kode: '1-1100', Nama: 'Kas Outlet' }];

const Aset: BarisAsetTetap = {
    Uuid: '01J9AST0000000000000000001',
    Nomor: 'AT/2026/07/0001',
    Nama: 'Mesin espresso La Marzocco Linea Mini 2 grup',
    Kelompok: 'Kelompok1',
    LabelKelompok: 'Kelompok 1',
    NamaOutlet: 'Kopi Senja Solo Baru',
    TanggalPerolehan: '2026-07-15',
    HargaPerolehan: '12000000.00',
    UmurBulan: 48,
    Akumulasi: '750000.00',
    NilaiBuku: '11250000.00',
    Status: 'Aktif',
    LabelStatus: 'Aktif',
};

describe('Aset tetap (FIN-10, v3.38)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/akuntansi/aset-tetap');
        window.history.replaceState({}, '', '/kelola/akuntansi/aset-tetap');
    });
    afterEach(() => cleanup());

    it('format masa manfaat, periode, dan perkiraan per bulan tanpa float', () => {
        expect(FormatMasaManfaat(48)).toBe('4 tahun');
        expect(FormatMasaManfaat(18)).toBe('1 tahun 6 bulan');
        expect(FormatMasaManfaat(0)).toBe('Tidak disusutkan');
        expect(FormatPeriode('2026-10')).toBe('Okt 2026');
        expect(PerkiraanPerBulan('12000000', '0', '0', 48)).toBe('250000.00');
        expect(PerkiraanPerBulan('10000001', '1', '0', 3)).toBe('3333333.33');
        expect(PerkiraanPerBulan('20000000', '0', '5000000', 72)).toBe('208333.33');
        expect(PerkiraanPerBulan('', '0', '0', 48)).toBeNull();
        expect(PerkiraanPerBulan('1000', '0', '0', 0)).toBeNull();
    });

    it('daftar: nilai buku, tombol catat & susutkan untuk akuntansi.kelola', () => {
        RenderUji(
            <HalamanDaftarAsetTetap
                Aset={BuatHasilTabel([Aset])}
                OpsiKelompok={OpsiKelompok}
                OpsiStatus={[{ Nilai: 'Aktif', Label: 'Aktif' }]}
                Izin={{ Kelola: true }}
            />,
        );
        expect(screen.getAllByText('Rp 11.250.000').length).toBeGreaterThan(0);
        expect(screen.getByRole('link', { name: 'Catat aset' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/aset-tetap/buat',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Susutkan sampai bulan ini' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/akuntansi/aset-tetap/susutkan',
            expect.objectContaining({ Periode: expect.stringMatching(/^\d{4}-\d{2}$/) as string }),
            expect.anything(),
        );
        cleanup();

        RenderUji(
            <HalamanDaftarAsetTetap
                Aset={BuatHasilTabel([Aset])}
                OpsiKelompok={OpsiKelompok}
                OpsiStatus={[]}
                Izin={{ Kelola: false }}
            />,
        );
        expect(screen.queryByRole('link', { name: 'Catat aset' })).toBeNull();
    });

    it('buat: memilih kelompok mengisi masa manfaat bawaan, perkiraan per bulan tampil, saldo awal menyembunyikan akun kas', () => {
        RenderUji(
            <HalamanBuatAsetTetap OpsiKelompok={OpsiKelompok} OpsiOutlet={[]} OpsiAkun={Akun} UmurMaksimal={600} />,
        );
        fireEvent.change(screen.getByLabelText(/Nama aset/), { target: { value: 'Laptop kasir Lenovo' } });
        fireEvent.change(screen.getByLabelText(/Harga perolehan/), { target: { value: '12000000' } });
        expect(screen.getByText(/Perkiraan penyusutan Rp 250\.000 per bulan/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Simpan aset' }));
        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'post',
            url: '/kelola/akuntansi/aset-tetap',
            data: {
                Nama: 'Laptop kasir Lenovo',
                Kelompok: 'Kelompok1',
                UmurBulan: '48',
                SumberDana: 'KasBank',
                AkunKasBank: Akun[0]?.Uuid,
            },
        });
    });

    it('rincian: jadwal bertaut jurnal, aksi jual/hapus. Batal hanya bila belum disusutkan', () => {
        const props: PropsDetailAsetTetap = {
            Aset: {
                ...Aset,
                NilaiSisa: '0.00',
                AkumulasiAwal: '0.00',
                PeriodeMulai: '2026-07',
                SumberDana: 'KasBank',
                Catatan: null,
                TanggalPelepasan: null,
                NilaiPelepasan: null,
                AlasanBatal: null,
                JurnalPerolehan: { Uuid: 'J1', Nomor: 'JU-2607-000001' },
                JurnalPelepasan: null,
                BisaDibatalkan: false,
            },
            Jadwal: [
                {
                    Periode: '2026-07',
                    Jumlah: '250000.00',
                    Akumulasi: '250000.00',
                    NilaiBuku: '11750000.00',
                    Dijurnal: true,
                    Jurnal: { Uuid: 'J2', Nomor: 'JU-2607-000002' },
                },
                {
                    Periode: '2026-08',
                    Jumlah: '250000.00',
                    Akumulasi: '500000.00',
                    NilaiBuku: '11500000.00',
                    Dijurnal: false,
                    Jurnal: null,
                },
            ],
            OpsiAkun: Akun,
            Izin: { Kelola: true },
        };
        RenderUji(<HalamanDetailAsetTetap {...props} />);
        expect(screen.getAllByText('Jul 2026').length).toBeGreaterThan(0);
        expect(screen.getAllByRole('link', { name: 'JU-2607-000002' })[0]?.getAttribute('href')).toBe(
            '/kelola/akuntansi/jurnal/J2',
        );
        expect(screen.queryByRole('button', { name: 'Batalkan aset' })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Jual atau hapus aset' }));
        expect(screen.getByRole('dialog', { name: 'Jual atau hapus aset' })).toBeTruthy();
    });
});
