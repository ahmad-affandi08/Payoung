<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Enum;

/**
 * Status pendaftaran wajah karyawan (F-18 bagian 4, D-37). Karyawan merekam wajah dari halaman Absen setelah login; sejak D-45 wajah
 * itu langsung Disetujui. `Menunggu` hanya tersisa untuk data lama. Pengelola tetap bisa menolak wajah yang fotonya tidak
 * layak (Disetujui atau Menunggu → Ditolak). Hanya wajah Disetujui yang dipakai mencocokkan absen. Ditolak membuka jalan
 * daftar ulang; reset oleh pengelola menghapus barisnya.
 */
enum StatusWajahKaryawan: string
{
    case Menunggu = 'Menunggu';
    case Disetujui = 'Disetujui';
    case Ditolak = 'Ditolak';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu persetujuan',
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
        };
    }

    public function BisaBerubahKe(self $tujuan): bool
    {
        return match ($this) {
            self::Menunggu => $tujuan !== self::Menunggu,
            self::Disetujui => $tujuan === self::Ditolak,
            self::Ditolak => false,
        };
    }
}
