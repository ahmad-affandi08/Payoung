import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanBuatOutlet from '@/Halaman/Kelola/Outlet/Buat';
import HalamanDaftarOutlet from '@/Halaman/Kelola/Outlet/Daftar';
import HalamanDetailOutlet from '@/Halaman/Kelola/Outlet/Detail';
import HalamanBuatUndangan from '@/Halaman/Kelola/Pengguna/Buat';
import HalamanBuatPeran from '@/Halaman/Kelola/Peran/Buat';
import HalamanDaftarPeran from '@/Halaman/Kelola/Peran/Daftar';
import HalamanPin from '@/Halaman/Kelola/Pin';
import {
    AturHalamanUji,
    kirimanForm,
    RenderUji,
    TambahPropsHalamanUji,
    tiruanRouter,
} from '@/Komponen/Katalog/TiruanInertia';
import { PilihOpsi } from '@/Pengujian/InteraksiPilihan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

beforeEach(() => AturHalamanUji({}, '/kelola/outlet'));
afterEach(() => cleanup());

const batas = { Terpakai: 2, Batas: 5 };

const jenisPesananOtomatis = {
    JenisPesanan: ['MakanDiTempat', 'BawaPulang'],
    JenisPesananBawaan: 'MakanDiTempat',
    Otomatis: true,
    Pilihan: [
        { Nilai: 'MakanDiTempat', Label: 'Makan di tempat' },
        { Nilai: 'BawaPulang', Label: 'Bawa pulang' },
        { Nilai: 'Antar', Label: 'Antar (diantar toko)' },
    ],
};

describe('Kelola/Outlet (F-02 langkah 1, TabelData D-16)', () => {
    const outlet = [
        {
            Uuid: 'O-1',
            Kode: 'JKT1',
            Nama: 'Kopi Nusantara Sudirman',
            NamaMerek: 'Kopi Nusantara',
            NamaKota: 'Kota Jakarta Pusat',
            ZonaWaktu: 'WIB',
            JamTutupBuku: '04:00',
            JumlahGudang: 2,
            Status: 'Aktif' as const,
        },
        {
            Uuid: 'O-2',
            Kode: 'SBY1',
            Nama: 'Kopi Nusantara Tunjungan',
            NamaMerek: null,
            NamaKota: 'Kota Surabaya',
            ZonaWaktu: 'WIB',
            JamTutupBuku: '04:00',
            JumlahGudang: 1,
            Status: 'Diarsipkan' as const,
        },
    ];

    it('daftar outlet: tautan detail, status bertulis, dan cari memfilter baris', async () => {
        RenderUji(<HalamanDaftarOutlet Outlet={outlet} Merek={[]} Kota={[]} BatasOutlet={batas} />);

        expect(screen.getByRole('link', { name: 'Kopi Nusantara Sudirman' }).getAttribute('href')).toBe(
            '/kelola/outlet/O-1',
        );
        expect(screen.getByText('Diarsipkan')).toBeTruthy();

        fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Surabaya' } });
        await vi.waitFor(() => expect(screen.queryByText('Kopi Nusantara Sudirman')).toBeNull());
        expect(screen.getByText('Kopi Nusantara Tunjungan')).toBeTruthy();
    });

    it('tombol tambah outlet membuka halaman penuh /kelola/outlet/buat; batas penuh menonaktifkannya', () => {
        RenderUji(<HalamanDaftarOutlet Outlet={outlet} Merek={[]} Kota={[]} BatasOutlet={batas} />);
        expect(screen.getByRole('link', { name: 'Tambah outlet' }).getAttribute('href')).toBe('/kelola/outlet/buat');
        cleanup();

        RenderUji(<HalamanDaftarOutlet Outlet={outlet} Merek={[]} Kota={[]} BatasOutlet={{ Terpakai: 5, Batas: 5 }} />);
        expect(screen.queryByRole('link', { name: 'Tambah outlet' })).toBeNull();
        expect((screen.getByRole('button', { name: 'Tambah outlet' }) as HTMLButtonElement).disabled).toBe(true);
    });

    it('halaman tambah outlet: kirim POST /kelola/outlet dengan merek pertama; Batal kembali ke daftar', () => {
        AturHalamanUji({}, '/kelola/outlet/buat');
        TambahPropsHalamanUji({ SektorOutlet: ['FNB-CAF'] });
        RenderUji(
            <HalamanBuatOutlet Merek={[{ Nilai: 'M-1', Label: 'Kopi Nusantara' }]} Kota={[]} BatasOutlet={batas} />,
        );

        fireEvent.change(screen.getByLabelText('Nama outlet'), { target: { value: 'Kopi Nusantara Solo' } });
        fireEvent.change(screen.getByLabelText('Kode outlet'), { target: { value: 'SLO1' } });
        // Tambah outlet berupa wizard: Nama & kode → Lokasi & jam → Pajak → Tinjau.
        for (let i = 0; i < 3; i += 1) {
            fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
        }
        fireEvent.click(screen.getByRole('button', { name: 'Tambah outlet' }));

        expect(kirimanForm[0]).toEqual({
            metode: 'post',
            url: '/kelola/outlet',
            data: {
                Nama: 'Kopi Nusantara Solo',
                Kode: 'SLO1',
                Merek: 'M-1',
                Alamat: '',
                Telepon: '',
                KodeKota: '',
                ZonaWaktu: 'WIB',
                JamTutupBuku: '04:00',
                Pkp: false,
                Nitku: '',
                PungutPbjt: false,
                Kanvas: false,
                NomorKendaraan: '',
            },
        });

        // Wizard: Batal berupa tautan kembali ke daftar outlet.
        expect(screen.getByRole('link', { name: 'Batal' }).getAttribute('href')).toBe('/kelola/outlet');
    });

    it('halaman tambah outlet: batas paket penuh menampilkan peringatan', () => {
        RenderUji(<HalamanBuatOutlet Merek={[]} Kota={[]} BatasOutlet={{ Terpakai: 5, Batas: 5 }} />);
        expect(screen.getByText('Batas outlet paket sudah tercapai')).toBeTruthy();
    });

    it('detail outlet: jenis pesanan kasir diatur manual dengan bawaan (v3.51)', () => {
        RenderUji(
            <HalamanDetailOutlet
                Outlet={{
                    Uuid: 'O-1',
                    Kode: 'JKT1',
                    Nama: 'Kopi Nusantara Sudirman',
                    UuidMerek: null,
                    Alamat: null,
                    Telepon: null,
                    KodeKota: null,
                    ZonaWaktu: 'WIB',
                    JamTutupBuku: '04:00',
                    Pkp: false,
                    Nitku: null,
                    PungutPbjt: false,
                    Status: 'Aktif',
                    KodeTerkunci: true,
                    Kanvas: false,
                    NomorKendaraan: null,
                }}
                Gudang={[]}
                Merek={[]}
                Kota={[]}
                JenisGudang={[]}
                ModeMeja={{ Aktif: false, Area: [], Meja: [] }}
                BentukMeja={[]}
                PesanSendiri={{ FiturAktif: false, Aktif: false }}
                JenisPesanan={jenisPesananOtomatis}
                LokasiAbsensi={{ Lintang: null, Bujur: null, RadiusMeter: 100 }}
            />,
        );

        expect(screen.getByText('Makan di tempat, Bawa pulang | bawaan Makan di tempat (otomatis)')).toBeTruthy();
        fireEvent.click(screen.getByLabelText('Otomatis menurut jenis usaha outlet'));
        fireEvent.click(screen.getByLabelText('Antar (diantar toko)'));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan jenis pesanan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/outlet/O-1/jenis-pesanan',
            {
                Otomatis: false,
                JenisPesanan: ['MakanDiTempat', 'BawaPulang', 'Antar'],
                JenisPesananBawaan: 'MakanDiTempat',
            },
            expect.anything(),
        );
    });

    it('detail outlet: lokasi stok diarsipkan lewat menu aksi baris', () => {
        RenderUji(
            <HalamanDetailOutlet
                Outlet={{
                    Uuid: 'O-1',
                    Kode: 'JKT1',
                    Nama: 'Kopi Nusantara Sudirman',
                    UuidMerek: null,
                    Alamat: null,
                    Telepon: null,
                    KodeKota: null,
                    ZonaWaktu: 'WIB',
                    JamTutupBuku: '04:00',
                    Pkp: false,
                    Nitku: null,
                    PungutPbjt: false,
                    Status: 'Aktif',
                    KodeTerkunci: true,
                    Kanvas: false,
                    NomorKendaraan: null,
                }}
                Gudang={[{ Uuid: 'G-1', Kode: 'UTAMA', Nama: 'Gudang utama', Jenis: 'Jual', Status: 'Aktif' }]}
                Merek={[]}
                Kota={[]}
                JenisGudang={[{ Nilai: 'Jual', Label: 'Barang jual' }]}
                ModeMeja={{ Aktif: false, Area: [], Meja: [] }}
                BentukMeja={[]}
                PesanSendiri={{ FiturAktif: false, Aktif: false }}
                JenisPesanan={jenisPesananOtomatis}
                LokasiAbsensi={{ Lintang: null, Bujur: null, RadiusMeter: 100 }}
            />,
        );

        expect(screen.getByText('Barang jual')).toBeTruthy();
        // Tanpa mode meja & tanpa data meja: bagian meja tidak tampil.
        expect(screen.queryByRole('heading', { name: 'Meja & area' })).toBeNull();
        fireEvent.keyDown(screen.getByRole('button', { name: 'Aksi lokasi stok Gudang utama' }), { key: 'Enter' });
        fireEvent.click(screen.getByRole('menuitem', { name: 'Arsipkan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith('/kelola/outlet/O-1/gudang/G-1/arsipkan', {}, expect.anything());
    });

    it('F-10a meja: area & meja tampil, meja diarsipkan lewat aksi baris, tambah meja membuka formulir; mode mati hanya info', () => {
        const outletAktif = {
            Uuid: 'O-1',
            Kode: 'JKT1',
            Nama: 'Kopi Nusantara Sudirman',
            UuidMerek: null,
            Alamat: null,
            Telepon: null,
            KodeKota: null,
            ZonaWaktu: 'WIB',
            JamTutupBuku: '04:00',
            Pkp: false,
            Nitku: null,
            PungutPbjt: false,
            Status: 'Aktif' as const,
            KodeTerkunci: true,
            Kanvas: false,
            NomorKendaraan: null,
        };
        const modeMeja = {
            Aktif: true,
            Area: [
                {
                    Uuid: 'A-1',
                    Nama: 'Teras Belakang',
                    Urutan: 0,
                    Status: 'Aktif' as const,
                    JumlahMeja: 1,
                    MinimumCharge: '300000.00',
                },
            ],
            Meja: [
                {
                    Uuid: 'M-7',
                    Nama: '7',
                    UuidArea: 'A-1',
                    NamaArea: 'Teras Belakang',
                    Kapasitas: 4,
                    Bentuk: 'Bundar',
                    Urutan: 0,
                    Status: 'Aktif' as const,
                    MinimumCharge: null,
                    MinimumChargeEfektif: '300000.00',
                },
            ],
        };
        const bentuk = [
            { Nilai: 'Persegi', Label: 'Persegi' },
            { Nilai: 'Bundar', Label: 'Bundar' },
        ];
        const { unmount: Lepas } = RenderUji(
            <HalamanDetailOutlet
                Outlet={outletAktif}
                Gudang={[]}
                Merek={[]}
                Kota={[]}
                JenisGudang={[]}
                ModeMeja={modeMeja}
                BentukMeja={bentuk}
                PesanSendiri={{ FiturAktif: true, Aktif: false }}
                JenisPesanan={jenisPesananOtomatis}
                LokasiAbsensi={{ Lintang: null, Bujur: null, RadiusMeter: 100 }}
            />,
        );

        expect(screen.getByRole('heading', { name: 'Meja & area' })).toBeTruthy();
        expect(screen.getAllByText('Teras Belakang').length).toBeGreaterThan(0);
        expect(screen.getByText('4 orang')).toBeTruthy();
        fireEvent.keyDown(screen.getByRole('button', { name: 'Aksi meja 7' }), { key: 'Enter' });
        fireEvent.click(screen.getByRole('menuitem', { name: 'Arsipkan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith('/kelola/outlet/O-1/meja/M-7/arsipkan', {}, expect.anything());

        // D-144: minimum charge area tampil di tabel area, minimum yang berlaku (dari area) di tabel meja.
        expect(screen.getAllByText(/Rp\s?300\.000/).length).toBeGreaterThanOrEqual(2);
        expect(screen.getByText('dari area')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Tambah meja' }));
        expect(screen.getByRole('heading', { name: 'Tambah meja' })).toBeTruthy();
        expect(screen.getByLabelText('Nama atau nomor meja')).toBeTruthy();
        expect(screen.getByLabelText(/Minimum charge meja/)).toBeTruthy();
        Lepas();

        RenderUji(
            <HalamanDetailOutlet
                Outlet={outletAktif}
                Gudang={[]}
                Merek={[]}
                Kota={[]}
                JenisGudang={[]}
                ModeMeja={{ ...modeMeja, Aktif: false }}
                BentukMeja={bentuk}
                PesanSendiri={{ FiturAktif: true, Aktif: false }}
                JenisPesanan={jenisPesananOtomatis}
                LokasiAbsensi={{ Lintang: null, Bujur: null, RadiusMeter: 100 }}
            />,
        );
        expect(screen.getByText('Mode meja tidak aktif')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Tambah meja' })).toBeNull();
    });
});

describe('Kelola/PIN (F-02 langkah 4)', () => {
    it('status PIN bertulis; atur ulang dari menu aksi membuka formulir PIN anggota', () => {
        RenderUji(
            <HalamanPin
                PinSayaDiatur
                Anggota={[
                    {
                        Uuid: 'U-2',
                        Nama: 'Budi Santoso',
                        Email: 'budi@kopinusantara.id',
                        NamaPeran: 'Kasir',
                        PinDiatur: false,
                    },
                ]}
            />,
        );

        expect(screen.getByText('Belum diatur')).toBeTruthy();
        fireEvent.keyDown(screen.getByRole('button', { name: 'Aksi Budi Santoso' }), { key: 'Enter' });
        fireEvent.click(screen.getByRole('menuitem', { name: 'Atur ulang PIN' }));
        expect(screen.getByRole('heading', { name: 'Atur ulang PIN Budi Santoso' })).toBeTruthy();
    });

    it('tanpa anggota: keadaan kosong', () => {
        RenderUji(<HalamanPin PinSayaDiatur={false} Anggota={[]} />);
        expect(screen.getByText('Belum ada anggota lain yang PIN-nya bisa Anda atur.')).toBeTruthy();
    });
});

describe('Kelola/Peran (§19.1): buat peran di halaman penuh', () => {
    const daftarIzin = [
        { Kunci: 'penjualan.buat', Label: 'Berjualan', Kelompok: 'Penjualan', KhususPemilik: false },
        { Kunci: 'langganan.kelola', Label: 'Kelola langganan', Kelompok: 'Organisasi', KhususPemilik: true },
    ];

    it('daftar: tombol buat peran menuju /kelola/peran/buat', () => {
        AturHalamanUji({}, '/kelola/peran');
        RenderUji(
            <HalamanDaftarPeran
                Peran={[
                    {
                        Uuid: 'R-1',
                        Nama: 'Barista',
                        Keterangan: null,
                        Bawaan: false,
                        Pemilik: false,
                        Izin: ['penjualan.buat'],
                        JumlahAnggota: 0,
                    },
                ]}
                DaftarIzin={daftarIzin}
            />,
        );

        expect(screen.getByRole('link', { name: 'Buat peran' }).getAttribute('href')).toBe('/kelola/peran/buat');
    });

    it('halaman buat: izin khusus Pemilik tidak ditawarkan; kirim POST /kelola/peran; Batal kembali ke daftar', () => {
        AturHalamanUji({}, '/kelola/peran/buat');
        RenderUji(<HalamanBuatPeran DaftarIzin={daftarIzin} />);

        expect(screen.queryByLabelText('Kelola langganan')).toBeNull();
        fireEvent.change(screen.getByLabelText('Nama peran'), { target: { value: 'Kasir Senior' } });
        fireEvent.click(screen.getByLabelText('Berjualan'));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan peran' }));

        expect(kirimanForm[0]).toEqual({
            metode: 'post',
            url: '/kelola/peran',
            data: { Nama: 'Kasir Senior', Keterangan: '', Izin: ['penjualan.buat'] },
        });

        fireEvent.click(screen.getByRole('button', { name: 'Batal' }));
        expect(tiruanRouter.visit).toHaveBeenCalledWith('/kelola/peran');
    });
});

describe('Kelola/Pengguna (F-02 langkah 3): undang pengguna di halaman penuh', () => {
    const peran = [
        { Uuid: 'R-P', Nama: 'Pemilik', Pemilik: true, Cakupan: 'Semua' as const },
        { Uuid: 'R-K', Nama: 'Kasir', Pemilik: false, Cakupan: 'Terpilih' as const },
    ];
    const outletOpsi = [{ Uuid: 'O-1', Kode: 'JKT1', Nama: 'Kopi Nusantara Sudirman' }];

    it('kirim POST /kelola/pengguna/undangan dengan outlet yang dicentang; Batal kembali ke daftar', () => {
        AturHalamanUji({}, '/kelola/pengguna/undangan/buat');
        RenderUji(<HalamanBuatUndangan Peran={peran} Outlet={outletOpsi} BatasPengguna={batas} />);

        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'budi@kopinusantara.id' } });
        // D-104: pilihan outlet baru muncul setelah peran dipilih (peran operasional = outlet tertentu).
        expect(screen.queryByLabelText('JKT1 | Kopi Nusantara Sudirman')).toBeNull();
        PilihOpsi(screen.getByRole('combobox', { name: /Peran/ }), 'R-K');
        // Usaha hanya punya satu outlet: outlet peran operasional terisi otomatis.
        expect(screen.getByLabelText('JKT1 | Kopi Nusantara Sudirman').getAttribute('aria-checked')).toBe('true');
        fireEvent.click(screen.getByRole('button', { name: 'Kirim undangan' }));

        expect(kirimanForm[0]).toEqual({
            metode: 'post',
            url: '/kelola/pengguna/undangan',
            data: { Email: 'budi@kopinusantara.id', Peran: 'R-K', SemuaOutlet: false, Outlet: ['O-1'] },
        });

        fireEvent.click(screen.getByRole('button', { name: 'Batal' }));
        expect(tiruanRouter.visit).toHaveBeenCalledWith('/kelola/tim');
    });

    it('kursi penuh menampilkan peringatan batas paket', () => {
        RenderUji(<HalamanBuatUndangan Peran={peran} Outlet={outletOpsi} BatasPengguna={{ Terpakai: 5, Batas: 5 }} />);
        expect(screen.getByText('Batas pengguna paket sudah tercapai')).toBeTruthy();
    });
});
