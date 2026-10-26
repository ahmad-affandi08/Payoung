<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Enum;

/**
 * Siklus pembayaran gaji karyawan (D-219): `Bulanan` (rekap per bulan kalender) atau `Mingguan` (rekap per rentang tujuh
 * hari). Satu karyawan masuk ke rekap dengan siklus yang sama dengan miliknya, jadi tidak pernah dibayar dua kali.
 */
enum SiklusGaji: string
{
    case Bulanan = 'Bulanan';
    case Mingguan = 'Mingguan';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Bulanan => 'Bulanan',
            self::Mingguan => 'Mingguan',
        };
    }
}
