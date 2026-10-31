import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarStokAwal from '@/Halaman/Kelola/Persediaan/StokAwal/Daftar';
import HalamanDetailStokAwal, { PeriksaAlasanBatal } from '@/Halaman/Kelola/Persediaan/StokAwal/Detail';
import HalamanFormStokAwal from '@/Halaman/Kelola/Persediaan/StokAwal/Form';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { BukaPilihan } from '@/Pengujian/InteraksiPilihan';
import type { PropsDaftarStokAwal, PropsFormStokAwal } from '@/Tipe/Persediaan';

import {
    AkunBelumSiap,
    AkunSiap,
    BuatBarisDaftarStokAwal,
    BuatBarisForm,
    BuatHasilTabel,
    BuatHasilCari,
    BuatPropsDetail,
    GudangLama,
    GudangKedua,
    GudangUtama,
    IzinLihat,
    IzinPenuh,
    IzinStafGudang,
    NamaPanjang,
    NilaiEkstrem,
    UuidDokumen,
} from './DataUjiPersediaan';
import { UbahNilai } from '@/Pengujian/InteraksiPilihan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

function PropsDaftar(perubahan: Partial<PropsDaftarStokAwal> = {}): PropsDaftarStokAwal {
    return {
        StokAwal: BuatHasilTabel([]),
        OpsiStatus: [
            { Nilai: 'Draf', Label: 'Draf' },
            { Nilai: 'Diposting', Label: 'Diposting' },
        ],
        OpsiGudang: [GudangUtama, GudangLama],
        Izin: IzinPenuh,
        KesiapanAkun: AkunSiap,
        ...perubahan,
    };
}

function PropsForm(perubahan: Partial<PropsFormStokAwal> = {}): PropsFormStokAwal {
    return {
        Mode: 'Buat',
        StokAwal: null,
        OpsiGudang: [GudangUtama],
        HariIni: '2026-09-24',
        BatasBaris: 2000,
        MaksimalNomorSeriPerBaris: 1000,
        WajibKedaluwarsaBatch: true,
        KesiapanAkun: AkunSiap,
        ...perubahan,
    };
}

function PropsUbah(perubahan: Partial<PropsFormStokAwal> = {}): PropsFormStokAwal {
    return PropsForm({
        Mode: 'Ubah',
        StokAwal: {
            Uuid: UuidDokumen,
            UuidGudang: GudangUtama.Uuid,
            Tanggal: '2026-09-01',
            Catatan: 'Hitung fisik awal bulan',
            VersiDiubahPada: '2026-09-01T03:00:00.000000Z',
            Baris: [
                BuatBarisForm(),
                BuatBarisForm({
                    UuidProduk: '01J9PRD0000000000000000002',
                    NamaProduk: 'Susu UHT Full Cream 1 L',
                    Sku: 'SUSU-UHT-1L',
                    SimbolSatuan: 'pcs',
                    BolehDesimal: false,
                    Pelacakan: 'Batch',
                    Jumlah: '12',
                    HppSatuan: '1000',
                    NomorBatch: 'B-2026-09',
                    TanggalKedaluwarsa: '2027-01-31',
                }),
                BuatBarisForm({
                    UuidProduk: '01J9PRD0000000000000000003',
                    NamaProduk: 'Mesin Espresso Mini',
                    Sku: 'MSN-ESP-01',
                    SimbolSatuan: 'unit',
                    BolehDesimal: false,
                    Pelacakan: 'Seri',
                    Jumlah: '3',
                    HppSatuan: '1333.333333',
                    NomorSeri: ['SN-001', 'SN-002', 'SN-003'],
                }),
            ],
        },
        ...perubahan,
    });
}

describe('Kelola/Persediaan/StokAwal/Daftar (F-05a, TabelData D-16)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/persediaan/stok-awal');
        window.history.replaceState({}, '', '/kelola/persediaan/stok-awal');
    });
    afterEach(() => cleanup());

    it('kosong: ajakan buat & impor untuk persediaan.kelola', () => {
        RenderUji(<HalamanDaftarStokAwal {...PropsDaftar()} />);

        expect(
            screen.getByText('Belum ada stok awal. Isi stok awal agar saldo stok dan HPP benar sejak hari pertama.'),
        ).toBeTruthy();
        expect(
            screen.getAllByRole('link', { name: 'Buat stok awal' }).map((tautan) => tautan.getAttribute('href')),
        ).toContain('/kelola/persediaan/stok-awal/buat');
        expect(
            screen.getAllByRole('link', { name: 'Impor dari Excel' }).map((tautan) => tautan.getAttribute('href')),
        ).toContain('/kelola/persediaan/stok-awal/impor');
    });

    it('tanpa izin kelola: hanya lihat, tanpa tombol buat, alasannya tertulis', () => {
        RenderUji(<HalamanDaftarStokAwal {...PropsDaftar({ Izin: IzinLihat })} />);

        expect(screen.getByText('Hanya bisa melihat')).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Buat stok awal' })).toBeNull();
        expect(screen.getByText('Minta pengelola persediaan mengisi stok awal.')).toBeTruthy();
    });

    it('kosong karena filter: menawarkan hapus pencarian & filter', () => {
        window.history.replaceState({}, '', '/kelola/persediaan/stok-awal?saring%5BStatus%5D=Draf');
        RenderUji(<HalamanDaftarStokAwal {...PropsDaftar()} />);

        expect(screen.getByText('Tidak ada hasil untuk pencarian atau filter ini.')).toBeTruthy();
        expect(within(screen.getByLabelText('Filter aktif')).getByText('Status: Draf')).toBeTruthy();
    });

    it('peringatan akun jurnal hanya untuk pemegang izin posting', () => {
        RenderUji(<HalamanDaftarStokAwal {...PropsDaftar({ KesiapanAkun: AkunBelumSiap })} />);
        expect(screen.getByText('Pengaturan pembukuan toko belum lengkap')).toBeTruthy();
        cleanup();

        RenderUji(<HalamanDaftarStokAwal {...PropsDaftar({ KesiapanAkun: AkunBelumSiap, Izin: IzinStafGudang })} />);
        expect(screen.queryByText('Pengaturan pembukuan toko belum lengkap')).toBeNull();
    });

    it('data ekstrem: 500 dokumen, nomor Mono, nilai Rp 1.250.000.000 rata kanan tabular, draf tanpa nomor', () => {
        const baris = Array.from({ length: 500 }, (_, i) =>
            BuatBarisDaftarStokAwal(
                i + 1,
                i === 0
                    ? { TotalNilai: NilaiEkstrem, NamaGudang: NamaPanjang }
                    : i === 1
                      ? { Nomor: null, Status: 'Draf', LabelStatus: 'Draf', Sumber: 'Impor' }
                      : {},
            ),
        );
        const { container } = RenderUji(
            <HalamanDaftarStokAwal {...PropsDaftar({ StokAwal: BuatHasilTabel(baris, 500, 500) })} />,
        );
        const barisTabel = container.querySelectorAll('tbody tr');

        expect(barisTabel).toHaveLength(500);
        const pertama = barisTabel[0];
        expect(pertama?.querySelector('a.font-mono')?.textContent).toBe('SA/2026/09/0001');
        const selNilai = Array.from(pertama?.querySelectorAll('td') ?? []).find((sel) =>
            sel.textContent.includes('Rp 1.250.000.000'),
        );
        expect(selNilai?.className).toContain('text-right');
        expect(selNilai?.className).toContain('tabular-nums');
        expect(pertama?.textContent).toContain(NamaPanjang);
        expect(barisTabel[1]?.textContent).toContain('Draf tanpa nomor');
        expect(barisTabel[1]?.textContent).toContain('Dari impor Excel');
    }, 30_000);

    it('filter status tersimpan di URL dan data diambil dari server (TanStack Query)', async () => {
        const permintaan: string[] = [];
        vi.stubGlobal(
            'fetch',
            vi.fn((url: string) => {
                permintaan.push(url);

                return Promise.resolve({
                    ok: true,
                    status: 200,
                    json: () => Promise.resolve(BuatHasilTabel([BuatBarisDaftarStokAwal(9)])),
                });
            }),
        );
        RenderUji(
            <HalamanDaftarStokAwal {...PropsDaftar({ StokAwal: BuatHasilTabel([BuatBarisDaftarStokAwal(1)]) })} />,
        );

        fireEvent.click(screen.getByRole('button', { name: /^Status/ }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'Draf' }));

        await waitFor(() => expect(permintaan).toEqual(['/kelola/persediaan/stok-awal?saring%5BStatus%5D=Draf']));
        expect(window.location.search).toBe('?saring%5BStatus%5D=Draf');
        vi.unstubAllGlobals();
    });
});

describe('Kelola/Persediaan/StokAwal/Form (F-05a)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/persediaan/stok-awal/buat'));
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('buat: hanya satu lokasi aktif terisi otomatis, terkunci, dan menjadi bidang pertama', () => {
        RenderUji(<HalamanFormStokAwal {...PropsForm()} />);

        const lokasi = screen.getByRole<HTMLButtonElement>('combobox', { name: 'Lokasi stok' });
        expect(lokasi.disabled).toBe(true);
        expect(lokasi.textContent).toContain(GudangUtama.Nama);
        expect(screen.getAllByRole('combobox')[0]).toBe(lokasi);
        fireEvent.click(screen.getByRole('button', { name: 'Simpan draf' }));
        expect(screen.queryByText('Pilih lokasi stok.')).toBeNull();
    });

    it('buat: kirim kosong menampilkan masalah lokal dan tidak mengirim', () => {
        RenderUji(<HalamanFormStokAwal {...PropsForm({ OpsiGudang: [GudangUtama, GudangKedua] })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Simpan draf' }));

        expect(screen.getByText('Pilih lokasi stok.')).toBeTruthy();
        expect(screen.getByText('Tambah minimal satu produk ke stok awal.')).toBeTruthy();
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        expect(screen.getByRole<HTMLInputElement>('combobox', { name: 'Tambah produk' }).disabled).toBe(true);
    });

    it('buat: pilih lokasi, tambah produk dari pencarian, isi jumlah & HPP → POST dengan ULID klien', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: () => Promise.resolve({ Data: [BuatHasilCari({ HppRataRata: '1200.000000' })] }),
            }),
        );
        RenderUji(<HalamanFormStokAwal {...PropsForm({ OpsiGudang: [GudangUtama, GudangKedua] })} />);

        UbahNilai(screen.getByRole('combobox', { name: 'Lokasi stok' }), GudangUtama.Uuid);
        // Pemilih produk memakai pola PilihanCari, tetapi opsinya dari server: buka dulu, lalu ketik di kotak cari
        // di dalam popover (UbahNilai hanya untuk daftar opsi statis).
        // Tunggu PilihanCari "Lokasi stok" selesai mengembalikan fokus ke pemicunya; bila pemilih produk dibuka
        // sebelum itu, pengembalian fokus tersebut menutup popover produk.
        await waitFor(() => expect(document.querySelector('[data-slot="popover-content"]')).toBeNull());
        BukaPilihan(screen.getByRole('combobox', { name: 'Tambah produk' }));
        const cari = screen.getByPlaceholderText('Cari nama, SKU, atau barcode');
        fireEvent.change(cari, { target: { value: 'gayo' } });
        await waitFor(() => expect(screen.getByRole('option', { name: /Arabika Gayo/ })).toBeTruthy());
        fireEvent.keyDown(cari, { key: 'Enter' });

        expect(screen.getByText('HPP rata-rata saat ini Rp 1.200')).toBeTruthy();
        UbahNilai(screen.getByRole('textbox', { name: 'Jumlah Biji Kopi Arabika Gayo' }), '10');
        UbahNilai(screen.getByRole('textbox', { name: 'Harga modal per satuan Biji Kopi Arabika Gayo' }), '1.234,5678');
        expect(screen.getAllByText('Rp 12.345,68').length).toBeGreaterThan(0);

        fireEvent.click(screen.getByRole('button', { name: 'Simpan draf' }));

        expect(tiruanRouter.post).toHaveBeenCalledTimes(1);
        const [url, data] = tiruanRouter.post.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe('/kelola/persediaan/stok-awal');
        expect(data.Uuid).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
        expect(data).toMatchObject({
            UuidGudang: GudangUtama.Uuid,
            Tanggal: '2026-09-24',
            Catatan: null,
            Baris: [
                {
                    UuidProduk: '01J9PRD0000000000000000001',
                    Jumlah: '10',
                    HppSatuan: '1234.5678',
                    NomorBatch: null,
                    TanggalKedaluwarsa: null,
                    NomorSeri: [],
                },
            ],
        });

        // Kirim ulang memakai Uuid yang sama (idempoten di server).
        fireEvent.click(screen.getByRole('button', { name: 'Simpan draf' }));
        const kiriman2 = tiruanRouter.post.mock.calls[1] as [string, Record<string, unknown>];
        expect(kiriman2[1].Uuid).toBe(data.Uuid);
    });

    it('ubah: baris biasa, batch, dan seri. Total perkiraan tanpa float; PUT dengan VersiDiubahPada', () => {
        RenderUji(<HalamanFormStokAwal {...PropsUbah()} />);

        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Ubah draf stok awal');
        expect(
            screen.getByRole<HTMLInputElement>('textbox', { name: 'Nomor batch Susu UHT Full Cream 1 L' }).value,
        ).toBe('B-2026-09');
        expect(screen.getByRole<HTMLTextAreaElement>('textbox', { name: 'Nomor seri Mesin Espresso Mini' }).value).toBe(
            'SN-001\nSN-002\nSN-003',
        );
        expect(screen.getByText('Rp 28.345,68')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Simpan draf' }));

        expect(tiruanRouter.put).toHaveBeenCalledTimes(1);
        const [url, data] = tiruanRouter.put.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe(`/kelola/persediaan/stok-awal/${UuidDokumen}`);
        expect(data).toMatchObject({
            VersiDiubahPada: '2026-09-01T03:00:00.000000Z',
            Catatan: 'Hitung fisik awal bulan',
            Baris: [
                { Jumlah: '10', HppSatuan: '1234.5678' },
                { Jumlah: '12', NomorBatch: 'B-2026-09', TanggalKedaluwarsa: '2027-01-31', NomorSeri: [] },
                { Jumlah: '3', NomorBatch: null, NomorSeri: ['SN-001', 'SN-002', 'SN-003'] },
            ],
        });
        expect(data).not.toHaveProperty('Uuid');
    });

    it('ubah: nomor seri menentukan jumlah. Hapus baris mengurangi total', () => {
        RenderUji(<HalamanFormStokAwal {...PropsUbah()} />);

        UbahNilai(screen.getByRole('textbox', { name: 'Nomor seri Mesin Espresso Mini' }), 'SN-001\nSN-002');
        expect(screen.getByText('Rp 27.012,35')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Hapus baris Susu UHT Full Cream 1 L' }));
        expect(screen.getByText('Rp 15.012,35')).toBeTruthy();
        expect(screen.queryByRole('textbox', { name: 'Nomor batch Susu UHT Full Cream 1 L' })).toBeNull();
    });

    it('masalah lokal: tanggal masa depan, batch tanpa kedaluwarsa, satuan bulat', () => {
        const props = PropsUbah();
        const baris = props.StokAwal?.Baris ?? [];
        RenderUji(
            <HalamanFormStokAwal
                {...PropsUbah({
                    StokAwal: props.StokAwal
                        ? {
                              ...props.StokAwal,
                              Tanggal: '2026-09-25',
                              Baris: [
                                  { ...(baris[1] ?? BuatBarisForm()), TanggalKedaluwarsa: null },
                                  BuatBarisForm({
                                      UuidProduk: 'X',
                                      NamaProduk: 'Gelas Plastik',
                                      BolehDesimal: false,
                                      SimbolSatuan: 'pcs',
                                      Jumlah: '1.5',
                                  }),
                              ],
                          }
                        : null,
                })}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Simpan draf' }));

        expect(screen.getByText('Tanggal stok awal tidak boleh setelah hari ini.')).toBeTruthy();
        expect(screen.getByText('Isi tanggal kedaluwarsa batch ini.')).toBeTruthy();
        expect(screen.getByText('Satuan pcs harus bilangan bulat.')).toBeTruthy();
        expect(tiruanRouter.put).not.toHaveBeenCalled();
    });

    it('masalah server per baris tampil di bawah isian. Masalah lain di ringkasan', () => {
        AturHalamanUji({
            'Baris.0.HppSatuan': 'Harga modal tidak valid.',
            'Baris.2.NomorSeri': 'Nomor seri SN-002 sudah ada.',
            VersiDiubahPada: 'Draf sudah diubah pengguna lain. Muat ulang halaman.',
        });
        RenderUji(<HalamanFormStokAwal {...PropsUbah()} />);

        const hpp = screen.getByRole('textbox', { name: 'Harga modal per satuan Biji Kopi Arabika Gayo' });
        expect(document.getElementById(hpp.getAttribute('aria-describedby') ?? '')?.textContent).toBe(
            'Harga modal tidak valid.',
        );
        expect(screen.getByText('Nomor seri SN-002 sudah ada.')).toBeTruthy();
        const ringkasan = screen.getByText('Perubahan tidak disimpan').closest('[role="alert"]');
        expect(ringkasan?.textContent).toContain('Draf sudah diubah pengguna lain.');
        expect(ringkasan?.textContent).not.toContain('Harga modal tidak valid.');
    });

    it('stok minus di lokasi: peringatan selisih HPP (H-16). Ganti lokasi menyembunyikan saldo lama', () => {
        const props = PropsUbah();
        RenderUji(
            <HalamanFormStokAwal
                {...PropsUbah({
                    OpsiGudang: [GudangUtama, { ...GudangLama, Aktif: true }],
                    StokAwal: props.StokAwal
                        ? { ...props.StokAwal, Baris: [BuatBarisForm({ SaldoDiGudang: '-4.0000' })] }
                        : null,
                })}
            />,
        );

        expect(screen.getByText('Stok minus. Selisih HPP dicatat saat stok awal dicatat.')).toBeTruthy();
        expect(screen.getByText(/stok saat ini −4 kg/)).toBeTruthy();

        UbahNilai(screen.getByRole('combobox', { name: 'Lokasi stok' }), GudangLama.Uuid);
        expect(screen.queryByText(/stok saat ini/)).toBeNull();
    });

    it('batas baris tercapai: pemilih produk dinonaktifkan dengan penjelasan', () => {
        RenderUji(<HalamanFormStokAwal {...PropsUbah({ BatasBaris: 3 })} />);

        expect(screen.getByRole<HTMLInputElement>('combobox', { name: 'Tambah produk' }).disabled).toBe(true);
        expect(screen.getByText(/Batas 3 baris per dokumen tercapai/)).toBeTruthy();
    });
});

describe('Kelola/Persediaan/StokAwal/Detail (F-05a)', () => {
    beforeEach(() => AturHalamanUji({}, `/kelola/persediaan/stok-awal/${UuidDokumen}`));
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('draf: ringkasan, baris batch/seri, tombol ubah/buang/posting. Jurnal belum ada', () => {
        RenderUji(<HalamanDetailStokAwal {...BuatPropsDetail()} />);

        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Stok awal Draf tanpa nomor');
        expect(screen.getByRole('link', { name: 'Ubah draf' }).getAttribute('href')).toBe(
            `/kelola/persediaan/stok-awal/${UuidDokumen}/ubah`,
        );
        expect(screen.getByText('B-2026-09')).toBeTruthy();
        expect(screen.getByText('Kedaluwarsa 31 Jan 2027')).toBeTruthy();
        expect(screen.getByText('3 nomor seri')).toBeTruthy();
        // HPP per satuan di tabel detail paling banyak 2 desimal (1234.5678 menjadi Rp 1.234,57).
        expect(screen.getByText('Rp 1.234,57')).toBeTruthy();
        expect(screen.getByText('Jurnal dibuat saat stok awal dicatat.')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Batalkan stok awal' })).toBeNull();
    });

    it('posting: konfirmasi menyebut total & jurnal, lalu POST /posting', () => {
        RenderUji(<HalamanDetailStokAwal {...BuatPropsDetail()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Catat stok awal' }));
        const dialog = screen.getByRole('alertdialog');
        expect(dialog.textContent).toContain('Rp 28.345,68');
        expect(dialog.textContent).toContain('dicatat otomatis ke pembukuan sebagai modal awal');
        expect(dialog.textContent).not.toContain('latar belakang');

        fireEvent.click(within(dialog).getByRole('button', { name: 'Catat stok awal' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/persediaan/stok-awal/${UuidDokumen}/posting`,
            {},
            expect.anything(),
        );
    });

    it('dokumen besar (baris seri dihitung per nomor) diposting lewat antrean', () => {
        RenderUji(<HalamanDetailStokAwal {...BuatPropsDetail({ BatasPostingLangsung: 4 })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Catat stok awal' }));
        expect(screen.getByRole('alertdialog').textContent).toContain('5 baris barang');
    });

    it('akun belum dipetakan: posting nonaktif dengan penjelasan', () => {
        RenderUji(<HalamanDetailStokAwal {...BuatPropsDetail({ KesiapanAkun: AkunBelumSiap })} />);

        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Catat stok awal' }).disabled).toBe(true);
        expect(screen.getByText('Pengaturan pembukuan toko belum lengkap')).toBeTruthy();
    });

    it('staf gudang: bisa ubah draf, tidak ada tombol posting, alasan tertulis', () => {
        RenderUji(
            <HalamanDetailStokAwal
                {...BuatPropsDetail({
                    Izin: IzinStafGudang,
                    Tindakan: { Ubah: true, Buang: true, Posting: false, Batalkan: false },
                })}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Catat stok awal' })).toBeNull();
        expect(screen.getByText('Menunggu dicatat')).toBeTruthy();
    });

    it('buang draf: konfirmasi lalu POST /buang', () => {
        RenderUji(<HalamanDetailStokAwal {...BuatPropsDetail()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Buang draf' }));
        fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Buang draf' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/persediaan/stok-awal/${UuidDokumen}/buang`,
            {},
            expect.anything(),
        );
    });

    it('diposting: jurnal bertaut (izin laporan keuangan). Batalkan wajib alasan 5–255 karakter', () => {
        const props = BuatPropsDetail({
            Tindakan: { Ubah: false, Buang: false, Posting: false, Batalkan: true },
            Jurnal: [
                {
                    Uuid: '01J9JRN0000000000000000001',
                    Nomor: 'JU/2026/09/000001',
                    Tanggal: '2026-09-01',
                    Keterangan: 'Stok awal SA/2026/09/0001',
                    TotalDebit: '28345.68',
                    Pembalik: false,
                },
            ],
        });
        RenderUji(
            <HalamanDetailStokAwal
                {...props}
                StokAwal={{
                    ...props.StokAwal,
                    Nomor: 'SA/2026/09/0001',
                    Status: 'Diposting',
                    LabelStatus: 'Diposting',
                    DipostingOleh: 'Budi Santoso',
                    DipostingPada: '2026-09-01T04:00:00Z',
                }}
            />,
        );

        expect(screen.getByRole('link', { name: 'JU/2026/09/000001' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/jurnal/01J9JRN0000000000000000001',
        );
        expect(screen.queryByRole('link', { name: 'Ubah draf' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Batalkan stok awal' }));
        const dialog = screen.getByRole('alertdialog');
        UbahNilai(within(dialog).getByRole('textbox', { name: 'Alasan pembatalan' }), 'sal');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Batalkan stok awal' }));
        expect(within(dialog).getByText('Tulis alasan minimal 5 karakter.')).toBeTruthy();
        expect(tiruanRouter.post).not.toHaveBeenCalled();

        UbahNilai(within(dialog).getByRole('textbox', { name: 'Alasan pembatalan' }), '  Salah hitung fisik gudang  ');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Batalkan stok awal' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/persediaan/stok-awal/${UuidDokumen}/batalkan`,
            { Alasan: 'Salah hitung fisik gudang' },
            expect.anything(),
        );
    });

    it('masalah pembatalan dari server (stok sudah terpakai) tampil di dalam dialog', () => {
        AturHalamanUji({ Umum: 'Stok Biji Kopi Arabika Gayo sudah berkurang. Koreksi lewat penyesuaian stok.' });
        const props = BuatPropsDetail({ Tindakan: { Ubah: false, Buang: false, Posting: false, Batalkan: true } });
        RenderUji(
            <HalamanDetailStokAwal
                {...props}
                StokAwal={{ ...props.StokAwal, Status: 'Diposting', LabelStatus: 'Diposting' }}
            />,
        );

        // Galat umum dari server juga dibuka sebagai dialog hasil di tata letak; tutup dulu, lalu buka dialog batal.
        fireEvent.click(screen.getByRole('button', { name: 'Tutup' }));
        fireEvent.click(screen.getByRole('button', { name: 'Batalkan stok awal' }));
        expect(within(screen.getByRole('alertdialog')).getByText(/sudah berkurang/)).toBeTruthy();
    });

    it('tanpa izin lihat jurnal: nomor jurnal tanpa tautan. Pembalik diberi label', () => {
        const props = BuatPropsDetail({
            Izin: IzinLihat,
            Tindakan: { Ubah: false, Buang: false, Posting: false, Batalkan: false },
            Jurnal: [
                {
                    Uuid: 'J1',
                    Nomor: 'JU/2026/09/000001',
                    Tanggal: '2026-09-01',
                    Keterangan: 'Stok awal',
                    TotalDebit: '28345.68',
                    Pembalik: false,
                },
                {
                    Uuid: 'J2',
                    Nomor: 'JU/2026/09/000002',
                    Tanggal: '2026-09-02',
                    Keterangan: 'Pembatalan stok awal',
                    TotalDebit: '28345.68',
                    Pembalik: true,
                },
            ],
        });
        RenderUji(
            <HalamanDetailStokAwal
                {...props}
                StokAwal={{
                    ...props.StokAwal,
                    Status: 'Dibatalkan',
                    LabelStatus: 'Dibatalkan',
                    AlasanBatal: 'Salah hitung',
                }}
            />,
        );

        expect(screen.queryByRole('link', { name: 'JU/2026/09/000001' })).toBeNull();
        expect(screen.getByText('Pembalik')).toBeTruthy();
        expect(screen.getByText('Stok awal dibatalkan')).toBeTruthy();
        expect(screen.queryByRole('button', { name: /Posting|Batalkan|Buang/ })).toBeNull();
    });

    it('memproses: memantau status posting. Posting gagal menampilkan pesan masalah', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: () =>
                    Promise.resolve({ Status: 'Memproses', LabelStatus: 'Memproses', Nomor: null, PesanGalat: null }),
            }),
        );
        const props = BuatPropsDetail({ Tindakan: { Ubah: false, Buang: false, Posting: false, Batalkan: false } });
        RenderUji(
            <HalamanDetailStokAwal
                {...props}
                StokAwal={{ ...props.StokAwal, Status: 'Memproses', LabelStatus: 'Memproses' }}
            />,
        );
        expect(screen.getByText('Stok awal sedang dicatat')).toBeTruthy();
        await waitFor(() => expect(vi.mocked(fetch)).toHaveBeenCalled());
        expect(tiruanRouter.reload).not.toHaveBeenCalled();
        cleanup();

        RenderUji(
            <HalamanDetailStokAwal
                {...props}
                StokAwal={{ ...props.StokAwal, PesanGalat: 'Stok awal Biji Kopi Arabika Gayo sudah ada.' }}
            />,
        );
        expect(screen.getByText('Pencatatan terakhir gagal')).toBeTruthy();
        expect(screen.getByText(/sudah ada\. Perbaiki draf lalu catat ulang\./)).toBeTruthy();
    });

    it('alasan batal divalidasi 5–255 karakter', () => {
        expect(PeriksaAlasanBatal('  abcd ')).toBe('Tulis alasan minimal 5 karakter.');
        expect(PeriksaAlasanBatal('x'.repeat(256))).toBe('Alasan paling panjang 255 karakter.');
        expect(PeriksaAlasanBatal('Salah hitung')).toBeNull();
    });
});
