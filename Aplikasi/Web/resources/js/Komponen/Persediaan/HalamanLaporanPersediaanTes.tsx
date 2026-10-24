import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanKartuStok, { BuatQueryKartuStok, PeriksaSaringKartu } from '@/Halaman/Kelola/Persediaan/KartuStok';
import HalamanPengaturanPersediaan from '@/Halaman/Kelola/Persediaan/Pengaturan';
import HalamanSaldoStok from '@/Halaman/Kelola/Persediaan/Saldo';
import { AturHalamanUji, RenderUji, TiruanInertia, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import type { BarisSaldoStok, PropsKartuStok, PropsPengaturanPersediaan, PropsSaldoStok } from '@/Tipe/Persediaan';

import {
    BuatBarisKartu,
    BuatBarisSaldo,
    BuatHasilTabel,
    GudangLama,
    GudangUtama,
    NamaPanjang,
    NilaiEkstrem,
    UuidDokumen,
} from './DataUjiPersediaan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/** Ganti hak akses pengguna tiruan (bawaan TiruanInertia: Pemilik). */
function AturAkses(izin: string[]): void {
    TiruanInertia.usePage().props.Akses = { Pemilik: false, Izin: izin };
}

const ringkasanKosong = { TotalNilai: '0.00', JumlahBaris: 0, JumlahMinus: 0 };

function PropsSaldo(
    perubahan: Partial<Omit<PropsSaldoStok, 'Saldo'>> & {
        Saldo?: ReturnType<typeof BuatHasilTabel<BarisSaldoStok>>;
        Ringkasan?: PropsSaldoStok['Saldo']['Ringkasan'];
    } = {},
): PropsSaldoStok {
    const { Saldo = BuatHasilTabel<BarisSaldoStok>([]), Ringkasan = ringkasanKosong, ...sisa } = perubahan;

    return {
        Saldo: { ...Saldo, Ringkasan },
        OpsiGudang: [GudangUtama, GudangLama],
        MetodeHpp: 'RataRata',
        ...sisa,
    };
}

describe('Kelola/Persediaan/Saldo (F-05a, TabelData D-16)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/persediaan/saldo');
        window.history.replaceState({}, '', '/kelola/persediaan/saldo');
    });
    afterEach(() => cleanup());

    it('kosong: ajakan isi stok awal untuk persediaan.kelola; tanpa izin kelola diarahkan ke pengelola', () => {
        RenderUji(<HalamanSaldoStok {...PropsSaldo()} />);
        expect(screen.getByRole('link', { name: 'Buat stok awal' }).getAttribute('href')).toBe(
            '/kelola/persediaan/stok-awal/buat',
        );
        cleanup();

        AturAkses(['persediaan.lihat']);
        RenderUji(<HalamanSaldoStok {...PropsSaldo()} />);
        expect(screen.queryByRole('link', { name: 'Buat stok awal' })).toBeNull();
        expect(screen.getByText('Minta pengelola persediaan mengisi stok awal.')).toBeTruthy();
    });

    it('kosong karena filter: menawarkan hapus pencarian & filter', () => {
        window.history.replaceState({}, '', '/kelola/persediaan/saldo?saring%5BKeadaan%5D=Minus');
        RenderUji(<HalamanSaldoStok {...PropsSaldo()} />);

        expect(screen.getByText('Tidak ada hasil untuk pencarian atau filter ini.')).toBeTruthy();
        expect(within(screen.getByLabelText('Filter aktif')).getByText('Keadaan stok: Stok minus')).toBeTruthy();
    });

    it('ringkasan, stok minus, HPP belum diketahui, batch diringkas, lokasi diarsipkan, tautan kartu stok', () => {
        const batch = ['B-01', 'B-02', 'B-03', 'B-04', 'B-05'].map((nomor, i) => ({
            NomorBatch: nomor,
            TanggalKedaluwarsa: i === 0 ? null : `2027-0${String(i)}-15`,
            JumlahSisa: '2.0000',
        }));
        RenderUji(
            <HalamanSaldoStok
                {...PropsSaldo({
                    Ringkasan: { TotalNilai: NilaiEkstrem, JumlahBaris: 3, JumlahMinus: 1 },
                    MetodeHpp: 'Fifo',
                    Saldo: BuatHasilTabel([
                        BuatBarisSaldo(1, {
                            NamaProduk: NamaPanjang,
                            JumlahTersedia: '-4.0000',
                            NilaiPersediaan: '-4000.00',
                            HppRataRata: '1000.000000',
                        }),
                        BuatBarisSaldo(2, {
                            Pelacakan: 'Batch',
                            Batch: batch,
                            HppRataRata: null,
                            NilaiPersediaan: '0.00',
                            JumlahTersedia: '10.0000',
                        }),
                        BuatBarisSaldo(3, {
                            Pelacakan: 'Seri',
                            JumlahNomorSeri: 1250,
                            GudangAktif: false,
                            NamaGudang: 'Gudang Lama',
                        }),
                    ]),
                })}
            />,
        );

        const ringkasan = screen.getByRole('region', { name: 'Ringkasan saldo stok' });
        expect(ringkasan.textContent).toContain('Rp 1.250.000.000');
        expect(ringkasan.textContent).toContain('FIFO');
        expect(screen.getByText('−4 pcs').className).toContain('text-bahaya');
        expect(screen.getByText('Minus')).toBeTruthy();
        expect(screen.getByText('−Rp 4.000')).toBeTruthy();
        expect(screen.getByText('Belum diketahui')).toBeTruthy();
        const daftarBatch = screen.getByRole('list', { name: 'Batch Produk 2' });
        expect(within(daftarBatch).getAllByRole('listitem')).toHaveLength(4);
        expect(daftarBatch.textContent).toContain('dan 2 batch lain');
        expect(daftarBatch.textContent).toContain('tanpa tanggal');
        expect(screen.getByText('1.250 nomor seri tersedia')).toBeTruthy();
        expect(screen.getByText('Diarsipkan')).toBeTruthy();
        expect(
            screen.getByRole('link', { name: `Kartu stok ${NamaPanjang} di Gudang Utama` }).getAttribute('href'),
        ).toBe(BuatBarisSaldo(1).TautanKartuStok);
        expect(screen.getByRole('link', { name: 'Tampilkan stok minus' }).getAttribute('href')).toBe(
            '/kelola/persediaan/saldo?saring%5BKeadaan%5D=Minus',
        );
    });

    it('data ekstrem: 1.000 baris; angka rata kanan tabular; paginasi server', () => {
        const baris = Array.from({ length: 1000 }, (_, i) => BuatBarisSaldo(i + 1));
        const { container } = RenderUji(
            <HalamanSaldoStok {...PropsSaldo({ Saldo: BuatHasilTabel(baris, 20000, 1000) })} />,
        );

        expect(container.querySelectorAll('tbody tr')).toHaveLength(1000);
        const selNilai = Array.from(container.querySelector('tbody tr')?.querySelectorAll('td') ?? []).find((sel) =>
            sel.textContent.includes('Rp 12.345,68'),
        );
        expect(selNilai?.className).toContain('text-right');
        expect(selNilai?.className).toContain('tabular-nums');
        expect(screen.getByText('Halaman 1 dari 20')).toBeTruthy();
    }, 30_000);
});

const saringKartu: PropsKartuStok['Saring'] = {
    UuidProduk: null,
    UuidGudang: null,
    Dari: '2026-09-01',
    Sampai: '2026-09-30',
};

function PropsKartu(perubahan: Partial<PropsKartuStok> = {}): PropsKartuStok {
    return {
        Produk: null,
        Gudang: null,
        Saring: saringKartu,
        SaldoAwal: null,
        SaldoAkhir: null,
        Mutasi: null,
        OpsiGudang: [GudangUtama],
        ...perubahan,
    };
}

function PropsKartuTerisi(perubahan: Partial<PropsKartuStok> = {}): PropsKartuStok {
    return PropsKartu({
        Produk: {
            Uuid: '01J9PRD0000000000000000001',
            Nama: 'Biji Kopi Arabika Gayo',
            Sku: 'KOPI-GAYO-1KG',
            SimbolSatuan: 'kg',
            Pelacakan: 'Tidak',
        },
        Gudang: GudangUtama,
        Saring: { ...saringKartu, UuidProduk: '01J9PRD0000000000000000001', UuidGudang: GudangUtama.Uuid },
        SaldoAwal: { Jumlah: '0.0000', Nilai: '0.00' },
        SaldoAkhir: { Jumlah: '7.0000', Nilai: '8641.98' },
        Mutasi: BuatHasilTabel([
            BuatBarisKartu(),
            BuatBarisKartu({
                TanggalBisnis: '2026-09-02',
                DicatatPada: '2026-09-02T05:00:00Z',
                JenisMutasi: 'Penjualan',
                LabelJenisMutasi: 'Penjualan',
                NomorReferensi: 'PJ/SOLO/0001',
                TautanReferensi: null,
                Masuk: null,
                Keluar: '3.0000',
                HppSatuan: '1234.568000',
                TotalHpp: '-3703.70',
                SaldoSetelah: '7.0000',
                NilaiSetelah: '8641.98',
                DicatatOleh: null,
            }),
        ]),
        ...perubahan,
    });
}

describe('Kelola/Persediaan/KartuStok (F-05a)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/persediaan/kartu-stok'));
    afterEach(() => cleanup());

    it('belum memilih: ajakan memilih produk & lokasi; kirim kosong memberi masalah lokal', () => {
        RenderUji(<HalamanKartuStok {...PropsKartu()} />);

        expect(screen.getByText('Pilih produk dan lokasi stok, lalu tekan Tampilkan kartu stok.')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Tampilkan kartu stok' }));
        expect(screen.getByText('Pilih produk.')).toBeTruthy();
        expect(screen.getByText('Pilih lokasi stok.')).toBeTruthy();
        expect(tiruanRouter.get).not.toHaveBeenCalled();
    });

    it('saldo awal & akhir periode, mutasi masuk/keluar bertanda, referensi bertaut (TabelData D-16)', () => {
        window.history.replaceState({}, '', '/kelola/persediaan/kartu-stok');
        const { container } = RenderUji(<HalamanKartuStok {...PropsKartuTerisi()} />);

        const saldo = screen.getByLabelText('Saldo periode');
        expect(saldo.textContent).toContain('Saldo awal');
        expect(saldo.textContent).toContain('7 kg');
        expect(saldo.textContent).toContain('Rp 8.641,98');
        const baris = Array.from(container.querySelectorAll('tbody tr'));
        expect(baris).toHaveLength(2);
        expect(baris[0]?.textContent).toContain('Stok awal');
        expect(screen.getByRole('link', { name: 'SA/2026/09/0001' }).getAttribute('href')).toBe(
            `/kelola/persediaan/stok-awal/${UuidDokumen}`,
        );
        expect(baris[1]?.textContent).toContain('PJ/SOLO/0001');
        expect(baris[1]?.textContent).toContain('−Rp 3.703,70');
        expect(baris[1]?.textContent).toContain('Sistem');
        expect(screen.getByRole('heading', { name: 'Biji Kopi Arabika Gayo di Gudang Utama' })).toBeTruthy();
    });

    it('tanpa mutasi di rentang tanggal: pesan jelas, saldo awal/akhir tetap tampil', () => {
        window.history.replaceState({}, '', '/kelola/persediaan/kartu-stok');
        RenderUji(<HalamanKartuStok {...PropsKartuTerisi({ Mutasi: BuatHasilTabel([]) })} />);

        expect(screen.getByText('Tidak ada mutasi stok di rentang tanggal ini.')).toBeTruthy();
        expect(screen.getByText('Saldo akhir')).toBeTruthy();
    });

    it('ubah rentang tanggal lalu tampilkan: GET dengan produk, gudang, dari, sampai', () => {
        RenderUji(<HalamanKartuStok {...PropsKartuTerisi()} />);

        fireEvent.change(screen.getByLabelText('Sampai tanggal'), { target: { value: '2026-08-01' } });
        fireEvent.click(screen.getByRole('button', { name: 'Tampilkan kartu stok' }));
        expect(screen.getByText('Tanggal akhir tidak boleh sebelum tanggal awal.')).toBeTruthy();
        expect(tiruanRouter.get).not.toHaveBeenCalled();

        fireEvent.change(screen.getByLabelText('Sampai tanggal'), { target: { value: '2026-09-15' } });
        fireEvent.click(screen.getByRole('button', { name: 'Tampilkan kartu stok' }));
        expect(tiruanRouter.get).toHaveBeenCalledWith(
            '/kelola/persediaan/kartu-stok',
            {
                produk: '01J9PRD0000000000000000001',
                gudang: GudangUtama.Uuid,
                dari: '2026-09-01',
                sampai: '2026-09-15',
            },
            expect.anything(),
        );
    });

    it('ganti produk menampilkan pemilih produk berstok', () => {
        RenderUji(<HalamanKartuStok {...PropsKartuTerisi()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Ganti produk' }));
        expect(screen.getByRole('combobox', { name: 'Produk' })).toBeTruthy();
    });

    it('query & pemeriksaan filter', () => {
        expect(BuatQueryKartuStok({ UuidProduk: null, UuidGudang: null, Dari: '', Sampai: '' })).toEqual({});
        expect(PeriksaSaringKartu({ ...saringKartu, UuidProduk: 'P', UuidGudang: 'G' })).toEqual({});
    });
});

function PropsPengaturan(perubahan: Partial<PropsPengaturanPersediaan> = {}): PropsPengaturanPersediaan {
    return {
        MetodeHpp: 'RataRata',
        StokBolehMinus: false,
        MetodeHppTerkunci: false,
        AlasanTerkunci: null,
        OpsiMetodeHpp: [
            { Nilai: 'RataRata', Label: 'Rata-rata bergerak', Keterangan: 'HPP = nilai persediaan ÷ jumlah.' },
            { Nilai: 'Fifo', Label: 'FIFO', Keterangan: 'Barang yang masuk pertama keluar pertama.' },
        ],
        ...perubahan,
    };
}

describe('Kelola/Persediaan/Pengaturan (F-05a)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/persediaan/pengaturan'));
    afterEach(() => cleanup());

    it('tanpa perubahan tombol simpan nonaktif; ubah stok minus langsung PUT', () => {
        RenderUji(<HalamanPengaturanPersediaan {...PropsPengaturan()} />);

        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Simpan pengaturan' }).disabled).toBe(true);
        fireEvent.click(screen.getByRole('checkbox', { name: /stok minus/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan' }));

        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/persediaan/pengaturan',
            { MetodeHpp: 'RataRata', StokBolehMinus: true },
            expect.anything(),
        );
    });

    it('ubah metode HPP meminta konfirmasi dulu', () => {
        RenderUji(<HalamanPengaturanPersediaan {...PropsPengaturan()} />);

        fireEvent.click(screen.getByRole('radio', { name: /FIFO/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan' }));
        expect(tiruanRouter.put).not.toHaveBeenCalled();

        const dialog = screen.getByRole('alertdialog');
        expect(dialog.textContent).toContain('Ubah metode HPP ke FIFO?');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Ubah metode HPP' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/persediaan/pengaturan',
            { MetodeHpp: 'Fifo', StokBolehMinus: false },
            expect.anything(),
        );
    });

    it('metode terkunci (sudah ada mutasi): pilihan nonaktif dan alasannya tertulis', () => {
        RenderUji(
            <HalamanPengaturanPersediaan
                {...PropsPengaturan({
                    MetodeHppTerkunci: true,
                    AlasanTerkunci: 'Sudah ada 12 mutasi stok sejak 1 Sep 2026.',
                })}
            />,
        );

        expect(screen.getByText('Metode HPP sudah terkunci')).toBeTruthy();
        expect(screen.getByText('Sudah ada 12 mutasi stok sejak 1 Sep 2026.')).toBeTruthy();
        expect(screen.getAllByRole('radio').every((radio) => radio.hasAttribute('disabled'))).toBe(true);
    });

    it('masalah server tampil di bawah bidangnya', () => {
        AturHalamanUji({ MetodeHpp: 'Metode HPP tidak bisa diubah karena sudah ada mutasi stok.' });
        RenderUji(<HalamanPengaturanPersediaan {...PropsPengaturan()} />);

        expect(screen.getByText('Metode HPP tidak bisa diubah karena sudah ada mutasi stok.')).toBeTruthy();
        expect(screen.queryByText('Perubahan tidak disimpan')).toBeNull();
    });
});
