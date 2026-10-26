<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Enum;

/**
 * Cara gaji pokok dihitung per rekap (D-219): `Tetap` = nominal gaji pokok per siklus (sebulan atau seminggu);
 * `PerHariMasuk` = upah per hari × jumlah hari masuk dalam rentang rekap (hari masuk dari absensi, ditambah hari cuti
 * berbayar; bisa disesuaikan selama draf). Karyawan `PerHariMasuk` tidak dikenai potongan tidak masuk karena hari
 * tidak masuk memang tidak dibayar.
 */
enum DasarGaji: string
{
    case Tetap = 'Tetap';
    case PerHariMasuk = 'PerHariMasuk';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Tetap => 'Gaji tetap per siklus',
            self::PerHariMasuk => 'Upah per hari masuk',
        };
    }
}
