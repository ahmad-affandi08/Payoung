import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, kirimanForm, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import type { BarisTim, PropsDaftarTim, PropsTambahTim } from '@/Tipe/Tim';

import HalamanDaftarTim from './Daftar';
import HalamanTambahTim from './Tambah';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const UUID_OUTLET = '01K5OUTLET0000000000000001';
const UUID_PERAN_KASIR = '01K5PERAN00000000000000001';
const UUID_PERAN_ADMIN = '01K5PERAN00000000000000002';
const outlet = [{ Uuid: UUID_OUTLET, Kode: 'UTAMA', Nama: 'Toko Utama' }];
const peran = [
    { Uuid: UUID_PERAN_KASIR, Nama: 'Kasir', Pemilik: false, SemuaOutletBawaan: false },
    { Uuid: UUID_PERAN_ADMIN, Nama: 'Admin', Pemilik: false, SemuaOutletBawaan: true },
];
const BuatKaryawan = (
    uuid: string,
    nama: string,
    status: 'Aktif' | 'Nonaktif' = 'Aktif',
    uuidPengguna: string | null = null,
) => ({
    Uuid: uuid,
    Nama: nama,
    Jabatan: 'Barista',
    LevelStaf: null,
    GajiPokok: '2500000.00',
    TarifLemburPerJam: null,
    PotonganTerlambatPerMenit: null,
    PotonganTidakMasukPerHari: null,
    UuidPengguna: uuidPengguna,
    NamaPengguna: uuidPengguna === null ? null : nama,
    UuidOutlet: null,
    NamaOutlet: null,
    Status: status,
    LabelStatus: status,
    TautanAbsen: false,
    StatusWajah: null,
});
const siti: BarisTim = {
    Kunci: '01K5PENGGUNA000000000000S1',
    Nama: 'Siti Barista',
    Jabatan: 'Barista',
    Email: null,
    Pemilik: false,
    UuidPengguna: '01K5PENGGUNA000000000000S1',
    StatusAkun: 'Aktif',
    UuidPeran: UUID_PERAN_KASIR,
    NamaPeran: 'Kasir',
    SemuaOutlet: false,
    UuidOutlet: [UUID_OUTLET],
    Karyawan: BuatKaryawan('01K5KARYAWAN00000000000S1', 'Siti Barista', 'Aktif', '01K5PENGGUNA000000000000S1'),
    Status: 'Aktif',
};
const rina: BarisTim = {
    Kunci: '01K5KARYAWAN00000000000R1',
    Nama: 'Rina Pelayan',
    Jabatan: 'Pelayan',
    Email: null,
    Pemilik: false,
    UuidPengguna: null,
    StatusAkun: null,
    UuidPeran: null,
    NamaPeran: null,
    SemuaOutlet: false,
    UuidOutlet: [],
    Karyawan: BuatKaryawan('01K5KARYAWAN00000000000R1', 'Rina Pelayan'),
    Status: 'Aktif',
};
const akuntan: BarisTim = {
    Kunci: '01K5PENGGUNA000000000000A1',
    Nama: 'Akuntan Luar',
    Jabatan: null,
    Email: 'akuntan@contoh.id',
    Pemilik: false,
    UuidPengguna: '01K5PENGGUNA000000000000A1',
    StatusAkun: 'Aktif',
    UuidPeran: UUID_PERAN_ADMIN,
    NamaPeran: 'Admin',
    SemuaOutlet: true,
    UuidOutlet: [],
    Karyawan: null,
    Status: 'Aktif',
};

const izinPenuh = {
    Tambah: true,
    Undang: true,
    UbahAkses: true,
    Nonaktifkan: true,
    AturPin: true,
    KelolaKaryawan: true,
};

function PropsDaftar(ubah: Partial<PropsDaftarTim> = {}): PropsDaftarTim {
    return {
        Tim: [siti, rina, akuntan],
        Undangan: [],
        Peran: peran,
        Outlet: outlet,
        OpsiOutlet: [{ Uuid: UUID_OUTLET, Nama: 'Toko Utama' }],
        OpsiPengguna: [],
        BatasPengguna: { Batas: 5, Terpakai: 2 },
        UuidSaya: '01K5PENGGUNA000000000000PM',
        Izin: izinPenuh,
        ...ubah,
    };
}

function PropsTambah(ubah: Partial<PropsTambahTim> = {}): PropsTambahTim {
    return {
        KaryawanTertaut: null,
        BolehAkun: true,
        BolehKaryawan: true,
        Peran: peran,
        Outlet: outlet,
        OpsiOutlet: [{ Uuid: UUID_OUTLET, Nama: 'Toko Utama' }],
        BatasPengguna: { Batas: 5, Terpakai: 2 },
        ...ubah,
    };
}

function BukaAksi(nama: string): HTMLElement {
    BukaMenu(screen.getByRole('button', { name: new RegExp(`Aksi.*${nama}`) }));

    return screen.getByRole('menu');
}

function Render(elemen: React.ReactElement) {
    return render(<QueryClientProvider client={new QueryClient()}>{elemen}</QueryClientProvider>);
}

beforeEach(() => {
    AturHalamanUji({}, '/kelola/tim');
    kirimanForm.length = 0;
    vi.spyOn(tiruanRouter, 'post').mockImplementation(() => undefined);
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe('Tim (D-101): satu daftar untuk akun & karyawan', () => {
    it('menampilkan satu baris per orang dengan keterangan akses yang jelas, dan satu tombol tambah', () => {
        Render(<HalamanDaftarTim {...PropsDaftar()} />);

        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(screen.getByText('Siti Barista')).toBeTruthy();
        expect(screen.getByText('Masuk aplikasi kasir dengan PIN')).toBeTruthy();
        expect(screen.getByText('Rina Pelayan')).toBeTruthy();
        expect(screen.getByText('Tanpa akun masuk')).toBeTruthy();
        expect(screen.getByText('akuntan@contoh.id')).toBeTruthy();
        expect(screen.getAllByRole('link', { name: 'Tambah anggota tim' })).toHaveLength(1);
        expect(screen.getByRole('link', { name: 'Tambah anggota tim' }).getAttribute('href')).toBe('/kelola/tim/buat');
    });

    it('aksi baris menyesuaikan apa yang dimiliki orangnya: karyawan tanpa akun ditawari akses masuk, akun tanpa karyawan ditawari dicatat', () => {
        Render(<HalamanDaftarTim {...PropsDaftar()} />);

        const menuRina = BukaAksi('Rina Pelayan');
        expect(within(menuRina).getByRole('menuitem', { name: 'Beri akses masuk' })).toBeTruthy();
        expect(within(menuRina).getByRole('menuitem', { name: 'Ubah data & gaji' })).toBeTruthy();
        expect(within(menuRina).queryByRole('menuitem', { name: 'Atur ulang PIN' })).toBeNull();
        cleanup();

        Render(<HalamanDaftarTim {...PropsDaftar()} />);
        const menuAkuntan = BukaAksi('Akuntan Luar');
        expect(within(menuAkuntan).getByRole('menuitem', { name: 'Catat sebagai karyawan' })).toBeTruthy();
        expect(within(menuAkuntan).getByRole('menuitem', { name: 'Ubah peran & outlet' })).toBeTruthy();
        expect(within(menuAkuntan).queryByRole('menuitem', { name: 'Ubah data & gaji' })).toBeNull();
    });

    it('nonaktifkan satu orang mengirim akun dan karyawannya sekaligus setelah dikonfirmasi', () => {
        Render(<HalamanDaftarTim {...PropsDaftar()} />);

        BukaAksi('Siti Barista');
        fireEvent.click(screen.getByRole('menuitem', { name: 'Nonaktifkan' }));
        fireEvent.click(screen.getByRole('button', { name: 'Nonaktifkan' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/tim/status',
            { Aksi: 'Nonaktifkan', UuidPengguna: siti.UuidPengguna, UuidKaryawan: siti.Karyawan?.Uuid },
            expect.any(Object),
        );
    });

    it('tanpa izin nonaktifkan pengguna, hanya bagian karyawan yang dikirim; tanpa izin apa pun tidak ada aksi status', () => {
        Render(
            <HalamanDaftarTim
                {...PropsDaftar({ Izin: { ...izinPenuh, Nonaktifkan: false, UbahAkses: false, AturPin: false } })}
            />,
        );
        BukaAksi('Siti Barista');
        fireEvent.click(screen.getByRole('menuitem', { name: 'Nonaktifkan' }));
        fireEvent.click(screen.getByRole('button', { name: 'Nonaktifkan' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/tim/status',
            { Aksi: 'Nonaktifkan', UuidPengguna: null, UuidKaryawan: siti.Karyawan?.Uuid },
            expect.any(Object),
        );
    });

    it('status sebagian ditandai jelas dan ditawari "Aktifkan kembali" untuk bagian yang tertinggal', () => {
        const sebagian: BarisTim = {
            ...siti,
            Status: 'Sebagian',
            Karyawan: BuatKaryawan('01K5KARYAWAN00000000000S1', 'Siti Barista', 'Nonaktif', siti.UuidPengguna),
        };
        Render(<HalamanDaftarTim {...PropsDaftar({ Tim: [sebagian] })} />);

        expect(screen.getByText('Akun aktif, karyawan nonaktif')).toBeTruthy();
        BukaAksi('Siti Barista');
        fireEvent.click(screen.getByRole('menuitem', { name: 'Aktifkan kembali' }));
        fireEvent.click(screen.getByRole('button', { name: 'Aktifkan kembali' }));

        // Akun sudah aktif; yang diaktifkan hanya karyawannya.
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/tim/status',
            { Aksi: 'Aktifkan', UuidPengguna: null, UuidKaryawan: sebagian.Karyawan?.Uuid },
            expect.any(Object),
        );
    });
});

describe('Tambah anggota tim (D-101): satu formulir, tiga jenis akses', () => {
    it('Kasir terpilih lebih dulu: PIN & peran tampil, email & kata sandi tidak; pindah ke Dashboard memunculkan email', () => {
        Render(<HalamanTambahTim {...PropsTambah()} />);

        expect(screen.getAllByLabelText(/PIN kasir/).length).toBeGreaterThan(0);
        expect(screen.queryAllByLabelText(/Email/)).toHaveLength(0);
        fireEvent.click(screen.getByRole('radio', { name: /Dashboard/ }));
        expect(screen.getAllByLabelText(/Email \(untuk masuk dashboard\)/).length).toBeGreaterThan(0);
        expect(screen.getAllByLabelText(/Kata sandi awal/).length).toBeGreaterThan(0);
    });

    it('Tanpa akun menyembunyikan PIN, peran, dan email, dan menawarkan outlet utama', () => {
        Render(<HalamanTambahTim {...PropsTambah()} />);

        fireEvent.click(screen.getByRole('radio', { name: /Tanpa akun masuk/ }));
        expect(screen.queryAllByLabelText(/PIN kasir/)).toHaveLength(0);
        expect(screen.queryByText('Tugasnya')).toBeNull();
        expect(screen.getByText('Outlet utama (opsional)')).toBeTruthy();
        expect(screen.getByText('Gaji & komisi (opsional)')).toBeTruthy();
    });

    it('tanpa izin karyawan: tidak ada jenis "Tanpa akun" dan tidak ada isian gaji; tanpa izin akun: hanya "Tanpa akun masuk"', () => {
        Render(<HalamanTambahTim {...PropsTambah({ BolehKaryawan: false })} />);
        expect(screen.queryByRole('radio', { name: /Tanpa akun masuk/ })).toBeNull();
        expect(screen.queryByText('Gaji & komisi (opsional)')).toBeNull();
        cleanup();

        Render(<HalamanTambahTim {...PropsTambah({ BolehAkun: false })} />);
        expect(screen.getAllByRole('radio')).toHaveLength(1);
        expect(screen.getByRole('radio', { name: /Tanpa akun masuk/ })).toBeTruthy();
    });

    it('beri akses masuk untuk karyawan yang ada: nama terkunci, tidak ada pilihan "Tanpa akun" atau centang bukan karyawan', () => {
        Render(
            <HalamanTambahTim
                {...PropsTambah({
                    KaryawanTertaut: { Uuid: '01K5KARYAWAN00000000000R1', Nama: 'Rina Pelayan', Jabatan: 'Pelayan' },
                })}
            />,
        );

        expect((screen.getByLabelText(/^Nama/) as HTMLInputElement).value).toBe('Rina Pelayan');
        expect((screen.getByLabelText(/^Nama/) as HTMLInputElement).disabled).toBe(true);
        expect(screen.queryByRole('radio', { name: /Tanpa akun masuk/ })).toBeNull();
        expect(screen.queryByText(/Bukan karyawan/)).toBeNull();
        expect(screen.getByText('Akses masuk untuk karyawan yang sudah ada')).toBeTruthy();
    });

    it('batas akun penuh mengunci jenis berakun, tetapi "Tanpa akun" tetap bisa dikirim', () => {
        Render(<HalamanTambahTim {...PropsTambah({ BatasPengguna: { Batas: 2, Terpakai: 2 } })} />);

        expect(screen.getByText('Batas akun masuk paket sudah tercapai')).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Tambah ke tim' }) as HTMLButtonElement).disabled).toBe(true);
        fireEvent.click(screen.getByRole('radio', { name: /Tanpa akun masuk/ }));
        expect(screen.queryByText('Batas akun masuk paket sudah tercapai')).toBeNull();
        expect((screen.getByRole('button', { name: 'Tambah ke tim' }) as HTMLButtonElement).disabled).toBe(false);
    });
});
