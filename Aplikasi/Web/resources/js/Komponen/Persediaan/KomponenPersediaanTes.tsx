import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { BukaPilihan } from '@/Pengujian/InteraksiPilihan';

import {
    CariBarisGanda,
    CekSaldoMinus,
    HitungBarisMutasi,
    PeriksaBarisStokAwal,
    PeriksaTanggalStokAwal,
    SusunMasukanBaris,
} from './AturanFormStokAwal';
import BidangHpp from './BidangHpp';
import BidangNomorSeri, { CariNomorSeriGanda, PeriksaNomorSeri, UraiNomorSeri } from './BidangNomorSeri';
import { AkunBelumSiap, AkunSiap, BuatBarisForm, BuatHasilCari, GudangUtama, UuidDokumen } from './DataUjiPersediaan';
import LabelStatusStokAwal from './LabelStatusStokAwal';
import PanelKesiapanAkun from './PanelKesiapanAkun';
import PemantauPosting from './PemantauPosting';
import PemilihProdukStok, { BuatUrlCariProdukStok } from './PemilihProdukStok';
import { BuatUlid } from './UlidKlien';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const aturan = { WajibKedaluwarsaBatch: true, MaksimalNomorSeriPerBaris: 1000 };

function HppTerkendali({ awal, saatBerubah }: { awal: string; saatBerubah: (nilai: string) => void }) {
    const [nilai, AturNilai] = useState(awal);

    return (
        <BidangHpp
            label="Harga modal"
            nilai={nilai}
            simbolSatuan="kg"
            saatBerubah={(baru) => {
                AturNilai(baru);
                saatBerubah(baru);
            }}
        />
    );
}

describe('BidangHpp (DesainF05a E: HPP per satuan 6 desimal, tanpa float)', () => {
    afterEach(() => cleanup());

    it('menampilkan format Indonesia dan mengirim string desimal polos', () => {
        const SaatBerubah = vi.fn();
        render(<HppTerkendali awal="1234.5678" saatBerubah={SaatBerubah} />);
        const input = screen.getByRole<HTMLInputElement>('textbox', { name: 'Harga modal' });

        expect(input.value).toBe('1.234,5678');
        expect(input.className).toContain('tabular-nums');
        expect(input.className).toContain('text-right');
        expect(screen.getByText('Rp')).toBeTruthy();
        expect(screen.getByText('/ kg')).toBeTruthy();

        fireEvent.change(input, { target: { value: '12.500,123456' } });
        expect(SaatBerubah).toHaveBeenLastCalledWith('12500.123456');
    });

    it('menolak digit ke-7 di belakang koma dan huruf', () => {
        const SaatBerubah = vi.fn();
        render(<HppTerkendali awal="" saatBerubah={SaatBerubah} />);
        const input = screen.getByRole<HTMLInputElement>('textbox', { name: 'Harga modal' });

        fireEvent.change(input, { target: { value: '1,1234567' } });
        fireEvent.change(input, { target: { value: 'abc' } });
        expect(SaatBerubah).not.toHaveBeenCalled();
    });

    it('masalah terhubung lewat aria-describedby', () => {
        render(<BidangHpp label="Harga modal" nilai="" saatBerubah={vi.fn()} galat="Isi harga modal." />);
        const input = screen.getByRole('textbox', { name: 'Harga modal' });

        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(document.getElementById(input.getAttribute('aria-describedby') ?? '')?.textContent).toBe(
            'Isi harga modal.',
        );
    });
});

describe('BidangNomorSeri (tempel/pindai, satu per baris)', () => {
    afterEach(() => cleanup());

    it('mengurai tempelan: baris kosong & spasi dibuang, jumlah tampil', () => {
        const SaatBerubah = vi.fn();
        render(<BidangNomorSeri label="Nomor seri" nilai={[]} saatBerubah={SaatBerubah} maksimal={1000} />);

        fireEvent.change(screen.getByRole('textbox', { name: 'Nomor seri' }), {
            target: { value: 'SN-001\n\n  SN-002 \r\nSN-003\tSN-004' },
        });
        expect(SaatBerubah).toHaveBeenLastCalledWith(['SN-001', 'SN-002', 'SN-003', 'SN-004']);
        expect(screen.getByText(/0 nomor seri \(maksimal 1\.000\)/)).toBeTruthy();
    });

    it('memeriksa kosong, ganda, terlalu panjang, dan batas per baris', () => {
        expect(UraiNomorSeri('A\nB\n')).toEqual(['A', 'B']);
        expect(CariNomorSeriGanda(['A', 'B', 'A', 'A'])).toEqual(['A']);
        expect(PeriksaNomorSeri([], 1000)).toBe('Isi minimal satu nomor seri.');
        expect(PeriksaNomorSeri(['A', 'A'], 1000)).toBe('Nomor seri ganda: A.');
        expect(PeriksaNomorSeri(['X'.repeat(101)], 1000)).toBe('Nomor seri paling panjang 100 karakter.');
        expect(PeriksaNomorSeri(['A', 'B', 'C'], 2)).toBe('Paling banyak 2 nomor seri per baris.');
        expect(PeriksaNomorSeri(['A', 'B'], 2)).toBeNull();
    });
});

describe('AturanFormStokAwal (pemeriksaan lokal C.6.1)', () => {
    it('jumlah wajib > 0 dan bulat untuk satuan tanpa desimal; HPP boleh 0 tetapi wajib diisi', () => {
        expect(PeriksaBarisStokAwal(BuatBarisForm(), aturan)).toEqual({});
        expect(PeriksaBarisStokAwal(BuatBarisForm({ Jumlah: '0' }), aturan).Jumlah).toBe('Isi jumlah lebih dari 0.');
        expect(
            PeriksaBarisStokAwal(BuatBarisForm({ Jumlah: '1.5', BolehDesimal: false, SimbolSatuan: 'pcs' }), aturan)
                .Jumlah,
        ).toBe('Satuan pcs harus bilangan bulat.');
        expect(PeriksaBarisStokAwal(BuatBarisForm({ HppSatuan: '' }), aturan).HppSatuan).toBe(
            'Isi harga modal per satuan (boleh 0).',
        );
        expect(PeriksaBarisStokAwal(BuatBarisForm({ HppSatuan: '0' }), aturan)).toEqual({});
    });

    it('batch wajib nomor & kedaluwarsa (WajibKedaluwarsaBatch). Seri memakai jumlah nomor', () => {
        const batch = BuatBarisForm({ Pelacakan: 'Batch', NomorBatch: ' ', TanggalKedaluwarsa: null });

        expect(PeriksaBarisStokAwal(batch, aturan)).toEqual({
            NomorBatch: 'Isi nomor batch.',
            TanggalKedaluwarsa: 'Isi tanggal kedaluwarsa batch ini.',
        });
        expect(PeriksaBarisStokAwal(batch, { ...aturan, WajibKedaluwarsaBatch: false }).TanggalKedaluwarsa).toBe(
            undefined,
        );
        const seri = BuatBarisForm({ Pelacakan: 'Seri', Jumlah: '', NomorSeri: ['SN-1', 'SN-2'] });
        expect(PeriksaBarisStokAwal(seri, aturan)).toEqual({});
        expect(SusunMasukanBaris(seri)).toEqual({
            UuidProduk: seri.UuidProduk,
            Jumlah: '2',
            HppSatuan: '1234.5678',
            NomorBatch: null,
            TanggalKedaluwarsa: null,
            NomorSeri: ['SN-1', 'SN-2'],
        });
    });

    it('BarisGanda: produk sama tanpa batch ganda. Batch berbeda boleh', () => {
        const a = BuatBarisForm();
        const b1 = BuatBarisForm({ UuidProduk: 'B', Pelacakan: 'Batch', NomorBatch: 'X1' });
        const b2 = BuatBarisForm({ UuidProduk: 'B', Pelacakan: 'Batch', NomorBatch: 'x1 ' });
        const b3 = BuatBarisForm({ UuidProduk: 'B', Pelacakan: 'Batch', NomorBatch: 'X2' });

        expect([...CariBarisGanda([a, b1, b3])]).toEqual([]);
        expect([...CariBarisGanda([a, b1, a, b2])]).toEqual([2, 3]);
    });

    it('tanggal tidak di masa depan. Baris mutasi menghitung nomor seri satu per nomor. Saldo minus', () => {
        expect(PeriksaTanggalStokAwal('2026-09-25', '2026-09-24')).toBe(
            'Tanggal stok awal tidak boleh setelah hari ini.',
        );
        expect(PeriksaTanggalStokAwal('', '2026-09-24')).toBe('Isi tanggal stok awal.');
        expect(PeriksaTanggalStokAwal('2026-09-24', '2026-09-24')).toBeNull();
        expect(
            HitungBarisMutasi([
                { Pelacakan: 'Tidak', NomorSeri: [] },
                { Pelacakan: 'Seri', NomorSeri: ['a', 'b', 'c'] },
            ]),
        ).toBe(4);
        expect(CekSaldoMinus('-4.0000')).toBe(true);
        expect(CekSaldoMinus('0.0000')).toBe(false);
        expect(CekSaldoMinus(null)).toBe(false);
    });

    it('ULID klien: 26 karakter Crockford, awalan waktu berurutan', () => {
        const ulid = BuatUlid(1_790_000_000_000);

        expect(ulid).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
        expect(BuatUlid(1_790_000_000_001) > ulid).toBe(true);
        expect(BuatUlid()).not.toBe(BuatUlid());
    });
});

describe('PanelKesiapanAkun & LabelStatusStokAwal', () => {
    beforeEach(() => AturHalamanUji());
    afterEach(() => cleanup());

    it('akun siap: tidak menampilkan apa pun. Belum siap: daftar akun & "Perbaiki otomatis" (audit #12) mengirim POST', () => {
        const { container } = render(<PanelKesiapanAkun kesiapan={AkunSiap} />);
        expect(container.textContent).toBe('');
        cleanup();

        render(<PanelKesiapanAkun kesiapan={AkunBelumSiap} />);
        expect(screen.getByRole('alert').textContent).toContain('Ekuitas saldo awal');
        expect(screen.getByRole('link', { name: 'Pemetaan akun' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/pemetaan',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Perbaiki otomatis' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/akuntansi/pemetaan/perbaiki-otomatis',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('label status memakai teks server dan warna token sebagai penguat', () => {
        render(<LabelStatusStokAwal status="Diposting" label="Diposting" />);

        expect(screen.getByText('Diposting').getAttribute('data-jenis')).toBe('sukses');
    });
});

describe('PemilihProdukStok (KunciKueri.Persediaan.CariProduk)', () => {
    beforeEach(() => AturHalamanUji());
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('URL pencarian menyertakan lokasi stok bila dipilih', () => {
        expect(BuatUrlCariProdukStok('kopi', null)).toBe('/kelola/persediaan/produk/cari?kata=kopi&batas=20');
        expect(BuatUrlCariProdukStok('kopi', GudangUtama.Uuid)).toBe(
            `/kelola/persediaan/produk/cari?kata=kopi&gudang=${GudangUtama.Uuid}&batas=20`,
        );
    });

    it('menampilkan stok saat ini. Produk yang stok awalnya sudah dicatat tidak bisa dipilih', async () => {
        const Ambil = vi.fn().mockResolvedValue({
            ok: true,
            json: () =>
                Promise.resolve({
                    Data: [
                        BuatHasilCari({ SaldoDiGudang: '-4.0000' }),
                        BuatHasilCari({
                            Uuid: '01J9PRD0000000000000000009',
                            Nama: 'Kopi Robusta Lampung',
                            StokAwalSudahAda: true,
                        }),
                    ],
                }),
        });
        vi.stubGlobal('fetch', Ambil);
        const SaatPilih = vi.fn();

        RenderUji(
            <PemilihProdukStok
                label="Tambah produk"
                uuidGudang={GudangUtama.Uuid}
                saatPilih={SaatPilih}
                tolakStokAwalAda
            />,
        );
        // Pola PilihanCari: tombol pemicu membuka popover, kotak carinya di dalam popover.
        BukaPilihan(screen.getByRole('combobox', { name: 'Tambah produk' }));
        const input = screen.getByPlaceholderText('Cari nama, SKU, atau barcode');
        fireEvent.change(input, { target: { value: 'kopi' } });

        await waitFor(() => expect(screen.getByRole('option', { name: /Arabika Gayo/ })).toBeTruthy());
        // Membuka daftar memicu permintaan awal (kata kosong) lebih dulu, jadi URL ketikan ditunggu.
        await waitFor(() =>
            expect(Ambil.mock.calls.map((panggilan) => panggilan[0])).toContain(
                `/kelola/persediaan/produk/cari?kata=kopi&gudang=${GudangUtama.Uuid}&batas=20`,
            ),
        );
        expect(screen.getByRole('option', { name: /Arabika Gayo/ }).textContent).toContain('stok −4 kg');
        const robusta = screen.getByRole('option', { name: /Robusta/ });
        expect(robusta.getAttribute('aria-disabled')).toBe('true');
        expect(robusta.textContent).toContain('Stok awal sudah dicatat di lokasi ini');

        fireEvent.click(robusta);
        expect(SaatPilih).not.toHaveBeenCalled();
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(SaatPilih).toHaveBeenCalledWith(expect.objectContaining({ Nama: 'Biji Kopi Arabika Gayo' }));
    });

    it('masalah jaringan ditulis di area aria-live', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) }));
        RenderUji(<PemilihProdukStok label="Produk" uuidGudang={null} saatPilih={vi.fn()} />);
        BukaPilihan(screen.getByRole('combobox', { name: 'Produk' }));
        const input = screen.getByPlaceholderText('Cari nama, SKU, atau barcode');

        fireEvent.change(input, { target: { value: 'xyz' } });
        await waitFor(() =>
            expect(screen.getByText('Pencarian gagal. Periksa koneksi lalu ketik ulang.')).toBeTruthy(),
        );
    });
});

describe('PemantauPosting (polling status tiap 3 detik)', () => {
    beforeEach(() => AturHalamanUji());
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('Memproses: menanyakan /status. Saat status berubah halaman dimuat ulang', async () => {
        const Ambil = vi.fn().mockResolvedValue({
            ok: true,
            json: () =>
                Promise.resolve({
                    Status: 'Diposting',
                    LabelStatus: 'Diposting',
                    Nomor: 'SA/2026/09/0001',
                    PesanGalat: null,
                }),
        });
        vi.stubGlobal('fetch', Ambil);
        RenderUji(<PemantauPosting uuid={UuidDokumen} status="Memproses" />);

        expect(screen.getByText('Stok awal sedang dicatat')).toBeTruthy();
        await waitFor(() => expect(tiruanRouter.reload).toHaveBeenCalled());
        expect(Ambil.mock.calls[0]?.[0]).toBe(`/kelola/persediaan/stok-awal/${UuidDokumen}/status`);
    });

    it('selain Memproses: tidak polling dan tidak menampilkan apa pun', () => {
        const Ambil = vi.fn();
        vi.stubGlobal('fetch', Ambil);
        const { container } = RenderUji(<PemantauPosting uuid={UuidDokumen} status="Draf" />);

        expect(container.textContent).toBe('');
        expect(Ambil).not.toHaveBeenCalled();
    });
});
