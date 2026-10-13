<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/**
 * Siklus insiden: `Diselidiki` → `Teridentifikasi` (penyebab ketemu) → `Dipantau` (perbaikan terpasang, diamati) →
 * `Selesai` (final). Boleh melompat maju, dan `Dipantau` boleh kembali ke `Teridentifikasi` bila gangguan berulang.
 */
enum StatusInsiden: string
{
    case Diselidiki = 'Diselidiki';
    case Teridentifikasi = 'Teridentifikasi';
    case Dipantau = 'Dipantau';
    case Selesai = 'Selesai';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Diselidiki => 'Sedang diselidiki',
            self::Teridentifikasi => 'Penyebab ditemukan',
            self::Dipantau => 'Dipantau',
            self::Selesai => 'Selesai',
        };
    }

    public function BisaBerubahKe(self $tujuan): bool
    {
        return match ($this) {
            self::Diselidiki => in_array($tujuan, [self::Teridentifikasi, self::Dipantau, self::Selesai], true),
            self::Teridentifikasi => in_array($tujuan, [self::Dipantau, self::Selesai], true),
            self::Dipantau => in_array($tujuan, [self::Teridentifikasi, self::Selesai], true),
            self::Selesai => false,
        };
    }
}
