import type { BarisKaryawan, OpsiUuidNama } from '@/Tipe/Karyawan';
import type { Batas, OpsiOutletPengguna, OpsiPeranPengguna } from '@/Tipe/Organisasi';

/**
 * Kontrak props halaman Tim (D-101): satu baris per orang, menggabungkan akun (pengguna: peran, outlet, PIN) dan data
 * karyawan (jadwal, absensi, gaji). Salah satu bisa kosong: akun tanpa karyawan (mis. akuntan luar) atau karyawan
 * tanpa akun (hanya dijadwalkan & digaji).
 */

/** `Sebagian` = akun dan karyawan berbeda status (mis. akun aktif tetapi karyawannya dinonaktifkan). */
export type StatusTim = 'Aktif' | 'Nonaktif' | 'Sebagian';

export type BarisTim = {
    Kunci: string;
    Nama: string;
    Jabatan: string | null;
    /** null = tanpa email: akun kasir (PIN) atau tidak punya akun. */
    Email: string | null;
    Pemilik: boolean;
    UuidPengguna: string | null;
    StatusAkun: 'Aktif' | 'Nonaktif' | null;
    UuidPeran: string | null;
    NamaPeran: string | null;
    SemuaOutlet: boolean;
    UuidOutlet: string[];
    Karyawan: BarisKaryawan | null;
    Status: StatusTim;
};

export type UndanganTim = {
    Uuid: string;
    Email: string;
    NamaPeran: string | null;
    SemuaOutlet: boolean;
    JumlahOutlet: number;
    BerlakuSampai: string;
    Pengundang: string;
};

export type IzinTim = {
    Tambah: boolean;
    Undang: boolean;
    UbahAkses: boolean;
    Nonaktifkan: boolean;
    AturPin: boolean;
    KelolaKaryawan: boolean;
};

export type PropsDaftarTim = {
    Tim: BarisTim[];
    Undangan: UndanganTim[];
    Peran: OpsiPeranPengguna[];
    Outlet: OpsiOutletPengguna[];
    OpsiOutlet: OpsiUuidNama[];
    OpsiPengguna: OpsiUuidNama[];
    BatasPengguna: Batas;
    UuidSaya: string;
    Izin: IzinTim;
};

export type PropsTambahTim = {
    KaryawanTertaut: { Uuid: string; Nama: string; Jabatan: string | null } | null;
    BolehAkun: boolean;
    BolehKaryawan: boolean;
    Peran: OpsiPeranPengguna[];
    Outlet: OpsiOutletPengguna[];
    OpsiOutlet: OpsiUuidNama[];
    BatasPengguna: Batas;
};
