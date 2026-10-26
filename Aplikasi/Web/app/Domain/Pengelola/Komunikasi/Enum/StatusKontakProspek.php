<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Enum;

/** Kontak prospek (D-216): hanya `Aktif` yang boleh dikirimi; `Berhenti` dan `Pentalan` tidak pernah dikirimi lagi. */
enum StatusKontakProspek: string
{
    case Aktif = 'Aktif';
    case Berhenti = 'Berhenti';
    case Pentalan = 'Pentalan';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif',
            self::Berhenti => 'Berhenti berlangganan',
            self::Pentalan => 'Alamat tidak aktif',
        };
    }
}
