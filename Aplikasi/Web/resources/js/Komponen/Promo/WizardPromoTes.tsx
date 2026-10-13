import { act, cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanFormulirPromo from '@/Halaman/Kelola/Promo/Formulir';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';

import {
    HitungLangkahPromoBergalat,
    KalimatHari,
    KalimatRingkasPromo,
    PetakanGalatPromoKeLangkah,
    PeriksaLangkahPromo,
    type IsianPromo,
} from './LangkahPromo';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const opsi = {
    OpsiOutlet: [
        { Nilai: '01K5OUTLET0000000000000001', Label: 'Solo' },
        { Nilai: '01K5OUTLET0000000000000002', Label: 'Karanganyar' },
    ],
    OpsiTier: [{ Nilai: 'GOLD', Label: 'Gold (GOLD)' }],
    OpsiKategori: [{ Nilai: '01K5KATEGORI00000000000001', Label: 'Minuman › Kopi' }],
    OpsiKanal: [{ Nilai: 'MakanDiTempat', Label: 'Makan di tempat' }],
    OpsiMetodeBayar: [{ Nilai: '01K5METODE0000000000000002', Label: 'QRIS Toko' }],
    OpsiUlangTahun: [
        { Nilai: 'Hari' as const, Label: 'Tepat di hari ulang tahun' },
        { Nilai: 'Rentang' as const, Label: 'Sekitar hari ulang tahun (± hari)' },
    ],
    OpsiPeriodeBatas: [
        { Nilai: 'Hari' as const, Label: 'Per hari' },
        { Nilai: 'Promo' as const, Label: 'Selama promo' },
    ],
    OpsiPemasok: [{ Nilai: '01K5PEMASOK000000000000001', Label: 'PT Kopi Nusantara Distribusi' }],
};

const isianDasar: IsianPromo = {
    Kode: 'HAPPY',
    Nama: 'Happy hour',
    Prioritas: '0',
    Eksklusif: false,
    WajibVoucher: false,
    TanggalMulai: '',
    TanggalSelesai: '',
    Kuota: '',
    Hari: [],
    JamMulai: '',
    JamSelesai: '',
    Outlet: [],
    Kanal: [],
    Tier: [],
    MinimalSubtotal: '',
    JenisKondisi: 'Semua',
    UuidKondisi: [],
    JumlahMinimal: '',
    JenisAksi: 'DiskonPersenItem',
    Persen: '10',
    Jumlah: '',
    Harga: '',
    Beli: '2',
    Gratis: '1',
    PersenGratis: '100',
    Pengali: '2',
    UuidPemasok: '',
    PersenDanaPemasok: '0',
    BatasPerTransaksi: '',
    MetodeBayar: [],
    UlangTahun: '',
    HariUlangTahun: '7',
    TransaksiPertama: false,
    BatasPerPelanggan: '',
    PeriodeBatasPelanggan: 'Hari',
};

const Lanjut = () => fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));

describe('Aturan wizard promo (LangkahPromo)', () => {
    it('langkah Jenis & nama: kode, nama, dan prioritas diperiksa seperti di server', () => {
        expect(PeriksaLangkahPromo('JenisNama', isianDasar)).toEqual({});
        expect(Object.keys(PeriksaLangkahPromo('JenisNama', { ...isianDasar, Kode: '', Nama: ' ' }))).toEqual([
            'Kode',
            'Nama',
        ]);
        expect(PeriksaLangkahPromo('JenisNama', { ...isianDasar, Kode: 'KODE SPASI' }).Kode).toContain('1–30');
        expect(PeriksaLangkahPromo('JenisNama', { ...isianDasar, Kode: 'A'.repeat(31) }).Kode).toContain('1–30');
        expect(PeriksaLangkahPromo('JenisNama', { ...isianDasar, Nama: 'x'.repeat(101) }).Nama).toContain('100');
        expect(PeriksaLangkahPromo('JenisNama', { ...isianDasar, Prioritas: '1000' }).Prioritas).toBe(
            'Prioritas 0–999.',
        );
    });

    it('langkah Potongan: setiap jenis memeriksa isiannya sendiri', () => {
        const Periksa = (ubah: Partial<IsianPromo>) =>
            Object.keys(PeriksaLangkahPromo('Potongan', { ...isianDasar, ...ubah }));

        expect(Periksa({ Persen: '0' })).toEqual(['Persen']);
        expect(Periksa({ Persen: '100,5' })).toEqual(['Persen']);
        expect(Periksa({ Persen: '100' })).toEqual([]);
        expect(Periksa({ JenisAksi: 'DiskonTetapItem', Jumlah: '' })).toEqual(['Jumlah']);
        expect(Periksa({ JenisAksi: 'DiskonTetapPesanan', Jumlah: '0' })).toEqual(['Jumlah']);
        expect(Periksa({ JenisAksi: 'DiskonTetapPesanan', Jumlah: '5000' })).toEqual([]);
        expect(Periksa({ JenisAksi: 'HargaSpesial', Harga: '' })).toEqual(['Harga']);
        expect(Periksa({ JenisAksi: 'HargaSpesial', Harga: '0' })).toEqual([]);
        expect(Periksa({ JenisAksi: 'BundelHargaTetap', JumlahMinimal: '1', Harga: '' })).toEqual([
            'JumlahMinimal',
            'Harga',
        ]);
        expect(Periksa({ JenisAksi: 'BundelHargaTetap', JumlahMinimal: '2', Harga: '30000' })).toEqual([]);
        expect(Periksa({ JenisAksi: 'BeliXGratisY', Beli: '0', Gratis: '100' })).toEqual(['Beli', 'Gratis']);
        expect(Periksa({ JenisAksi: 'BeliXGratisY', PersenGratis: '0' })).toEqual(['PersenGratis']);
        expect(Periksa({ JenisAksi: 'PoinBerlipat', Pengali: '1' })).toEqual(['Pengali']);
        expect(Periksa({ JenisAksi: 'PoinBerlipat', Pengali: '10,5' })).toEqual(['Pengali']);
        expect(Periksa({ JenisAksi: 'PoinBerlipat', Pengali: '1.55' })).toEqual(['Pengali']);
        expect(Periksa({ JenisAksi: 'PoinBerlipat', Pengali: '1.5' })).toEqual([]);
        expect(Periksa({ JenisAksi: 'GratisOngkir', Jumlah: '' })).toEqual([]);
        expect(Periksa({ JenisAksi: 'GratisOngkir', Jumlah: '0' })).toEqual(['Jumlah']);
        expect(
            Periksa({ JenisAksi: 'BundelHargaTetap', JumlahMinimal: '2', Harga: '1', BatasPerTransaksi: '1000' }),
        ).toEqual(['BatasPerTransaksi']);
    });

    it('langkah Barang wajib memilih produk atau kategori; Waktu & Pelanggan memeriksa batasnya', () => {
        expect(PeriksaLangkahPromo('Barang', isianDasar)).toEqual({});
        expect(PeriksaLangkahPromo('Barang', { ...isianDasar, JenisKondisi: 'Produk' }).UuidKondisi).toBe(
            'Pilih minimal satu produk.',
        );
        expect(PeriksaLangkahPromo('Barang', { ...isianDasar, JenisKondisi: 'Kategori' }).UuidKondisi).toBe(
            'Pilih minimal satu kategori.',
        );
        expect(PeriksaLangkahPromo('Waktu', { ...isianDasar, Kuota: '0' }).Kuota).toContain('minimal 1');
        // Tanggal selesai berlaku sampai akhir harinya, jadi hari yang sama sah.
        expect(
            PeriksaLangkahPromo('Waktu', { ...isianDasar, TanggalMulai: '2026-12-01', TanggalSelesai: '2026-12-01' }),
        ).toEqual({});
        expect(
            PeriksaLangkahPromo('Waktu', { ...isianDasar, TanggalMulai: '2026-12-02', TanggalSelesai: '2026-12-01' })
                .TanggalSelesai,
        ).toContain('sebelum');
        expect(
            PeriksaLangkahPromo('Pelanggan', { ...isianDasar, UuidPemasok: 'P', PersenDanaPemasok: '101' })
                .PersenDanaPemasok,
        ).toBe('Bagian pemasok 0 sampai 100%.');
        expect(
            PeriksaLangkahPromo('Pelanggan', { ...isianDasar, UlangTahun: 'Rentang', HariUlangTahun: '31' })
                .HariUlangTahun,
        ).toContain('1–30');
        expect(PeriksaLangkahPromo('Pelanggan', { ...isianDasar, BatasPerPelanggan: '0' }).BatasPerPelanggan).toContain(
            '1–999',
        );
        expect(PeriksaLangkahPromo('Tinjau', { ...isianDasar, Kode: '' })).toEqual({});
    });

    it('kunci galat server dipetakan ke langkahnya; JumlahMinimal bergantung jenis potongan', () => {
        expect(PetakanGalatPromoKeLangkah('Kode', 'DiskonPersenItem')).toBe('JenisNama');
        expect(PetakanGalatPromoKeLangkah('Persen', 'DiskonPersenItem')).toBe('Potongan');
        expect(PetakanGalatPromoKeLangkah('JumlahMinimal', 'BundelHargaTetap')).toBe('Potongan');
        expect(PetakanGalatPromoKeLangkah('JumlahMinimal', 'DiskonPersenItem')).toBe('Barang');
        expect(PetakanGalatPromoKeLangkah('UuidKondisi', 'DiskonPersenItem')).toBe('Barang');
        expect(PetakanGalatPromoKeLangkah('SelesaiPada', 'DiskonPersenItem')).toBe('Waktu');
        expect(PetakanGalatPromoKeLangkah('PersenDanaPemasok', 'DiskonPersenItem')).toBe('Pelanggan');
        expect(PetakanGalatPromoKeLangkah('Umum', 'DiskonPersenItem')).toBeNull();
        expect(HitungLangkahPromoBergalat({ Persen: 'x', Kode: 'y', Umum: 'z' }, 'DiskonPersenItem')).toEqual([
            'JenisNama',
            'Potongan',
        ]);
    });

    it('kalimat ringkasan menyebut potongan, barang, hari, jam, dan periode', () => {
        const konteks = { namaProduk: {}, opsiKategori: opsi.OpsiKategori };

        expect(KalimatRingkasPromo(isianDasar, konteks)).toBe(
            'Diskon 10% per barang, setiap hari, tanpa batas tanggal.',
        );
        expect(
            KalimatRingkasPromo(
                {
                    ...isianDasar,
                    JenisKondisi: 'Kategori',
                    UuidKondisi: ['01K5KATEGORI00000000000001'],
                    Hari: ['1', '2', '3', '4', '5'],
                    JamMulai: '14:00',
                    JamSelesai: '17:00',
                    TanggalMulai: '2026-12-01',
                    TanggalSelesai: '2026-12-31',
                },
                konteks,
            ),
        ).toBe(
            'Diskon 10% per barang khusus kategori Minuman › Kopi, Senin–Jumat pukul 14:00–17:00, 01/12/2026 sampai 31/12/2026.',
        );
        expect(
            KalimatRingkasPromo({ ...isianDasar, JenisAksi: 'BeliXGratisY', Beli: '2', Gratis: '1' }, konteks),
        ).toContain('Beli 2 gratis 1');
        expect(
            KalimatRingkasPromo(
                { ...isianDasar, JenisAksi: 'BundelHargaTetap', JumlahMinimal: '2', Harga: '30000' },
                konteks,
            ),
        ).toContain('2 barang cukup Rp 30.000');
        expect(KalimatRingkasPromo({ ...isianDasar, JenisAksi: 'GratisOngkir', Jumlah: '' }, konteks)).toContain(
            'Gratis ongkir penuh',
        );
        expect(KalimatRingkasPromo({ ...isianDasar, JenisAksi: 'PoinBerlipat', Pengali: '1.5' }, konteks)).toContain(
            '1,5 kali lipat',
        );
        expect(KalimatHari(['6', '7'])).toBe('Sabtu, Minggu');
        expect(KalimatHari(['1', '3'])).toBe('Senin, Rabu');
    });
});

describe('Wizard tambah promo (halaman)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/promo/buat');
        window.history.replaceState({}, '', '/kelola/promo/buat');
    });
    afterEach(() => cleanup());

    it('mulai di langkah 1 dari 6; langkah di depan terkunci dan Lanjut menolak isian kosong', () => {
        RenderUji(<HalamanFormulirPromo Promo={null} FiturAktif {...opsi} />);

        expect(screen.getByText('Langkah 1 dari 6')).not.toBeNull();
        const langkahPotongan = screen.getByRole('button', { name: /Potongan/ });
        expect(langkahPotongan.getAttribute('aria-disabled')).toBe('true');
        expect(screen.queryByRole('button', { name: 'Simpan promo' })).toBeNull();

        Lanjut();

        expect(screen.getByText('Isi kode promo dulu.')).not.toBeNull();
        expect(screen.getByText('Isi nama promo dulu.')).not.toBeNull();
        expect(screen.getByText('Langkah 1 dari 6')).not.toBeNull();
        expect(tiruanRouter.post).not.toHaveBeenCalled();
    });

    it('jenis potongan menentukan isian langkah Potongan', () => {
        RenderUji(<HalamanFormulirPromo Promo={null} FiturAktif {...opsi} />);
        fireEvent.change(screen.getByLabelText('Kode promo'), { target: { value: 'b2g1' } });
        fireEvent.change(screen.getByLabelText('Nama promo'), { target: { value: 'Beli 2 gratis 1' } });
        // Pilih jenis lewat dropdown cari.
        fireEvent.click(screen.getByRole('combobox', { name: /Jenis potongan/ }));
        fireEvent.click(screen.getByRole('option', { name: 'Beli X gratis Y' }));
        Lanjut();

        expect(screen.getByLabelText('Beli')).not.toBeNull();
        expect(screen.getByLabelText('Gratis')).not.toBeNull();
        expect(screen.queryByLabelText('Persen diskon')).toBeNull();

        fireEvent.change(screen.getByLabelText('Beli'), { target: { value: '0' } });
        Lanjut();

        expect(screen.getByText('Isi jumlah beli antara 1 dan 99.')).not.toBeNull();
        expect(screen.getByText('Langkah 2 dari 6')).not.toBeNull();
    });

    it('Tinjau memuat kalimat ringkasan dan Ubah kembali ke langkah itu; menyimpan mengirim POST', () => {
        RenderUji(<HalamanFormulirPromo Promo={null} FiturAktif {...opsi} />);
        fireEvent.change(screen.getByLabelText('Kode promo'), { target: { value: 'kopi10' } });
        fireEvent.change(screen.getByLabelText('Nama promo'), { target: { value: 'Kopi 10 persen' } });
        Lanjut();
        fireEvent.change(screen.getByLabelText('Persen diskon'), { target: { value: '10' } });
        Lanjut();
        Lanjut();
        Lanjut();
        Lanjut();

        expect(screen.getByText('Langkah 6 dari 6')).not.toBeNull();
        expect(screen.getByLabelText('Ringkasan promo').textContent).toBe(
            'Diskon 10% per barang, setiap hari, tanpa batas tanggal.',
        );
        const bagianPotongan = screen.getByRole('region', { name: 'Ringkasan Potongan' });
        expect(within(bagianPotongan).getByText('Diskon 10% per barang')).not.toBeNull();
        expect(
            within(screen.getByRole('region', { name: 'Ringkasan Syarat & waktu' })).getByText('Tanpa minimal'),
        ).not.toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Ubah Potongan' }));
        expect(screen.getByText('Langkah 2 dari 6')).not.toBeNull();
        fireEvent.change(screen.getByLabelText('Persen diskon'), { target: { value: '15' } });
        fireEvent.click(screen.getByRole('button', { name: 'Kembali ke tinjauan' }));
        expect(screen.getByLabelText('Ringkasan promo').textContent).toContain('Diskon 15%');

        fireEvent.click(screen.getByRole('button', { name: 'Simpan promo' }));
        expect(tiruanRouter.post).toHaveBeenCalledTimes(1);
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/promo',
            expect.objectContaining({ Kode: 'KOPI10', Persen: '15', JenisAksi: 'DiskonPersenItem' }),
            expect.anything(),
        );
    });

    it('pendanaan pemasok hanya tampil untuk potongan harga; poin berlipat tidak memilikinya', () => {
        RenderUji(<HalamanFormulirPromo Promo={null} FiturAktif {...opsi} />);
        fireEvent.change(screen.getByLabelText('Kode promo'), { target: { value: 'kopi10' } });
        fireEvent.change(screen.getByLabelText('Nama promo'), { target: { value: 'Kopi 10 persen' } });
        Lanjut();
        fireEvent.change(screen.getByLabelText('Persen diskon'), { target: { value: '10' } });
        Lanjut();
        Lanjut();
        Lanjut();

        expect(screen.getByText('Pendanaan promo')).not.toBeNull();
        expect(screen.getByLabelText('Ditanggung pemasok')).not.toBeNull();
    });

    it('server menolak: kembali ke langkah yang memuat galat dan pesannya tampil', () => {
        RenderUji(<HalamanFormulirPromo Promo={null} FiturAktif {...opsi} />);
        fireEvent.change(screen.getByLabelText('Kode promo'), { target: { value: 'kopi10' } });
        fireEvent.change(screen.getByLabelText('Nama promo'), { target: { value: 'Kopi 10 persen' } });
        Lanjut();
        fireEvent.change(screen.getByLabelText('Persen diskon'), { target: { value: '10' } });
        Lanjut();
        Lanjut();
        Lanjut();
        Lanjut();
        fireEvent.click(screen.getByRole('button', { name: 'Simpan promo' }));

        const opsiKirim = tiruanRouter.post.mock.calls[0]?.[2] as {
            onError: (galat: Record<string, string>) => void;
        };
        act(() => opsiKirim.onError({ Kode: 'Kode promo sudah dipakai promo lain.' }));

        expect(screen.getByText('Langkah 1 dari 6')).not.toBeNull();
        expect(screen.getByText('Kode promo sudah dipakai promo lain.')).not.toBeNull();
        expect(screen.getByText('Perlu diperbaiki')).not.toBeNull();

        // Memperbaiki kode lalu lanjut membersihkan galat server lama.
        fireEvent.change(screen.getByLabelText('Kode promo'), { target: { value: 'kopi11' } });
        Lanjut();
        expect(screen.queryByText('Kode promo sudah dipakai promo lain.')).toBeNull();
    });

    it('ubah promo tetap satu halaman tanpa penunjuk langkah', () => {
        RenderUji(
            <HalamanFormulirPromo
                Promo={{
                    Uuid: '01K5PROMO00000000000000001',
                    Kode: 'HAPPY',
                    Nama: 'Happy hour',
                    JenisAksi: 'DiskonPersenItem',
                    LabelAksi: 'Diskon persen barang',
                    Prioritas: 0,
                    Eksklusif: false,
                    MulaiPada: null,
                    SelesaiPada: null,
                    Kuota: null,
                    KuotaTerpakai: 0,
                    Status: 'Aktif',
                    WajibVoucher: false,
                    JumlahPakai: 0,
                    TotalDiskon: '0.00',
                    Definisi: {
                        Hari: [],
                        JamMulai: null,
                        JamSelesai: null,
                        Outlet: [],
                        Kanal: [],
                        Tier: [],
                        MinimalSubtotal: '0.00',
                        Kondisi: { Jenis: 'Semua', Uuid: [], JumlahMinimal: '0.0000' },
                        Aksi: { Jenis: 'DiskonPersenItem', Persen: '10' },
                        BatasPerTransaksi: null,
                    },
                    TanggalMulai: null,
                    TanggalSelesai: null,
                    NamaProduk: {},
                    UuidPemasok: null,
                    PersenDanaPemasok: '0.00',
                }}
                FiturAktif
                {...opsi}
            />,
        );

        expect(screen.queryByText(/Langkah \d dari/)).toBeNull();
        expect(screen.queryByRole('button', { name: 'Lanjut' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Simpan promo' })).not.toBeNull();
        expect(screen.getByRole('heading', { name: 'Syarat & waktu' })).not.toBeNull();
    });
});
