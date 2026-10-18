<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Karyawan\Model\Karyawan;

/**
 * Karyawan milik pengguna yang sedang masuk, bila ia wajib absen (D-180): aktif, ikut absensi, dan tertaut ke akunnya.
 * Dasar halaman Absen berlogin (`/kelola/absen`), menu "Absen", dan pengalihan staf yang hanya perlu absen. Dibaca
 * dalam konteks tenant aktif.
 */
final class AbsenSaya
{
    public function Cari(int $idPengguna): ?Karyawan
    {
        return Karyawan::query()->where('IdPengguna', $idPengguna)->wajibAbsen()->first();
    }

    public function CekTersedia(int $idPengguna): bool
    {
        return Karyawan::query()->where('IdPengguna', $idPengguna)->wajibAbsen()->exists();
    }
}
