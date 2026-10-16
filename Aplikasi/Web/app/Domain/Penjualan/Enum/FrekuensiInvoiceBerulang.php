<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

use Carbon\CarbonImmutable;

/** Frekuensi invoice berulang (F-21): tiap N minggu, bulan, atau tahun. */
enum FrekuensiInvoiceBerulang: string
{
    case Mingguan = 'Mingguan';
    case Bulanan = 'Bulanan';
    case Tahunan = 'Tahunan';

    /**
     * Tanggal siklus ke-`$urutan` (0 = tanggal mulai), selalu dihitung dari tanggal mulai: tanggal 31 jatuh di akhir
     * bulan pendek lalu kembali ke 31, bukan menyusut ke 28 untuk seterusnya.
     */
    public function HitungTanggal(CarbonImmutable $mulai, int $interval, int $urutan): CarbonImmutable
    {
        $langkah = $interval * $urutan;

        return match ($this) {
            self::Mingguan => $mulai->addWeeks($langkah),
            self::Bulanan => $mulai->addMonthsNoOverflow($langkah),
            self::Tahunan => $mulai->addYearsNoOverflow($langkah),
        };
    }

    public function AmbilLabel(int $interval): string
    {
        $satuan = match ($this) {
            self::Mingguan => 'minggu',
            self::Bulanan => 'bulan',
            self::Tahunan => 'tahun',
        };

        return $interval === 1 ? match ($this) {
            self::Mingguan => 'Setiap minggu',
            self::Bulanan => 'Setiap bulan',
            self::Tahunan => 'Setiap tahun',
        } : "Setiap {$interval} {$satuan}";
    }
}
