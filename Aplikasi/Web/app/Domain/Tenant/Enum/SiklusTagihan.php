<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/**
 * Penggolongan lama langganan. Lama sebenarnya selalu angka bulan (`TagihanLangganan.JumlahBulan`,
 * `Langganan.JumlahBulanSiklus`): 1 bulan = `Bulanan`, 12 bulan = `Tahunan` (dua nilai lama tetap dipakai supaya data
 * dan laporan lama tidak berubah), selain itu `Kustom` (D-249).
 */
enum SiklusTagihan: string
{
    case Bulanan = 'Bulanan';
    case Tahunan = 'Tahunan';
    case Kustom = 'Kustom';

    public static function DariJumlahBulan(int $jumlahBulan): self
    {
        return match ($jumlahBulan) {
            1 => self::Bulanan,
            12 => self::Tahunan,
            default => self::Kustom,
        };
    }

    /** Jumlah bulan bawaan dua siklus lama; null untuk `Kustom` (angkanya ada di kolom jumlah bulan). */
    public function JumlahBulanBawaan(): ?int
    {
        return match ($this) {
            self::Bulanan => 1,
            self::Tahunan => 12,
            self::Kustom => null,
        };
    }
}
