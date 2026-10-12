<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/**
 * State machine `Langganan.Status` (F-00, BR-00.7): Trial → Aktif → Tertunggak → Ditangguhkan → Berhenti, cabang Gratis.
 *
 * P-07 (BR-P07.4, BR-P07.5): Super Admin boleh menangguhkan manual dari Trial/Aktif/Gratis (penipuan, penyalahgunaan,
 * permintaan hukum), dan mengaktifkan kembali memulihkan status sebelum penangguhan (termasuk Trial & Tertunggak).
 *
 * BR-P04.4 (turun paket mandiri): Aktif → Gratis saat periode berbayar berakhir atas permintaan Owner yang memilih paket Gratis.
 */
enum StatusLangganan: string
{
    case Trial = 'Trial';
    case Aktif = 'Aktif';
    case Tertunggak = 'Tertunggak';
    case Ditangguhkan = 'Ditangguhkan';
    case Berhenti = 'Berhenti';
    case Gratis = 'Gratis';

    public function BisaBerubahKe(self $tujuan): bool
    {
        return in_array($tujuan, match ($this) {
            self::Trial => [self::Aktif, self::Gratis, self::Ditangguhkan],
            self::Aktif => [self::Tertunggak, self::Berhenti, self::Ditangguhkan, self::Gratis],
            self::Tertunggak => [self::Aktif, self::Ditangguhkan],
            self::Ditangguhkan => [self::Aktif, self::Gratis, self::Berhenti, self::Trial, self::Tertunggak],
            self::Gratis => [self::Aktif, self::Ditangguhkan],
            self::Berhenti => [],
        }, true);
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Trial => 'Trial',
            self::Aktif => 'Aktif',
            self::Tertunggak => 'Tertunggak',
            self::Ditangguhkan => 'Ditangguhkan',
            self::Berhenti => 'Berhenti',
            self::Gratis => 'Gratis',
        };
    }
}
